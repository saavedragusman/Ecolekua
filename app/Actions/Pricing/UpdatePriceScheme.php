<?php

namespace App\Actions\Pricing;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Enums\PriceSchemeType;
use App\Enums\PriceTierKind;
use App\Models\CombinationPrice;
use App\Models\PriceScheme;
use App\Models\PriceTier;
use App\Models\Product;
use App\Models\User;
use App\Support\Pricing\AffectedPrices;
use App\Support\Pricing\SchemeAudit;
use App\Support\Pricing\SchemeInput;
use App\Support\Pricing\SchemeRules;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Edits a price scheme: its name and, for a tiered scheme, the full ordered tier list (COT-001, E-02,
 * design Decision 8). Lock order: the scheme row, then the product rows that hold affected prices in
 * id order (Decision 10).
 *
 * - The type never changes (DEC-COT-51): a different `type` is an error on `type`.
 * - Changing labels or limits never touches prices; a new tier simply has none (COT-025).
 * - Removing a tier with prices, or turning a priced tier with prices into a negotiation tier
 *   (DEC-COT-57), needs `confirm_price_removal`; without it the answer is a validation error on
 *   `tiers` with the number of prices and products (DEC-COT-23) and nothing changes. With it, those
 *   prices are deleted in the same transaction and listed in `old_values.deleted_prices`.
 * - Nothing is written, and nothing audited, when the request changes nothing.
 */
class UpdatePriceScheme
{
    /** Offset that parks the sort order of kept tiers while labels and order are rewritten. */
    private const PARKING_ORDER = 10000;

    public function __construct(private readonly RecordAuditEvent $audit) {}

    /**
     * @param  array<string, mixed>  $data  validated input (`name`, `type`, `tiers`, `confirm_price_removal`)
     *
     * @throws ValidationException
     */
    public function handle(PriceScheme $scheme, array $data, User $actor): PriceScheme
    {
        return DB::transaction(function () use ($scheme, $data, $actor): PriceScheme {
            $scheme = PriceScheme::query()->lockForUpdate()->findOrFail($scheme->id);

            if (isset($data['type']) && $data['type'] !== $scheme->type->value) {
                throw ValidationException::withMessages(['type' => __('validation.scheme_type_immutable')]);
            }

            $tiersBefore = SchemeAudit::tiers($scheme);
            $oldValues = [];
            $newValues = [];

            $name = (string) $data['name'];

            if ($name !== $scheme->name) {
                $oldValues['name'] = $scheme->name;
                $newValues['name'] = $name;
            }

            if ($scheme->type === PriceSchemeType::Tiered && isset($data['tiers'])) {
                $deletedPrices = $this->syncTiers($scheme, $data['tiers'], (bool) ($data['confirm_price_removal'] ?? false));
                $tiersAfter = SchemeAudit::tiers($scheme->load('tiers'));

                if ($tiersAfter !== $tiersBefore) {
                    $oldValues['tiers'] = $tiersBefore;
                    $newValues['tiers'] = $tiersAfter;
                }

                if ($deletedPrices !== []) {
                    $oldValues['deleted_prices'] = AffectedPrices::forAudit($deletedPrices);
                }
            }

            if ($newValues === []) {
                return $scheme;
            }

            if (isset($newValues['name'])) {
                $this->rename($scheme, $newValues['name']);
            }

            $this->audit->handle(AuditAction::PriceSchemeUpdated, $actor, $scheme, oldValues: $oldValues, newValues: $newValues);

            return $scheme;
        });
    }

    private function rename(PriceScheme $scheme, string $name): void
    {
        try {
            $scheme->forceFill(['name' => $name])->save();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => __('validation.scheme_name_unique')]);
        }
    }

    /**
     * Rewrites the tiers of the scheme to the submitted list and returns the prices it deleted.
     *
     * @return list<array{id: int, product_id: int, code: ?string, size_group: ?string, tier: string, price: string}>
     *
     * @throws ValidationException
     */
    private function syncTiers(PriceScheme $scheme, mixed $submitted, bool $confirmPriceRemoval): array
    {
        $submitted = SchemeInput::normalizeTiers($submitted);
        $current = $scheme->tiers->keyBy('id');

        $this->assertOwnTiers($submitted, array_values($current->keys()->all()));

        $errors = SchemeRules::validateTiers($submitted);

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        // Same values in the same order on the same tiers: swapping which tier holds which values moves the prices.
        $unchanged = array_column($submitted, 'id') === $current->keys()->all()
            && array_map(fn (array $tier): array => array_diff_key($tier, ['id' => true]), $submitted) === SchemeAudit::tiers($scheme);

        if ($unchanged) {
            return [];
        }

        $keptIds = array_filter(array_column($submitted, 'id'));
        $losingPrices = $current->filter(fn (PriceTier $tier): bool => ! in_array($tier->id, $keptIds, true))->keys()->all();

        foreach ($submitted as $tier) {
            $stored = $current->get($tier['id'] ?? 0);

            if ($stored !== null && $stored->kind === PriceTierKind::Priced && $tier['kind'] === PriceTierKind::Negotiation->value) {
                $losingPrices[] = $stored->id;
            }
        }

        $deletedPrices = $this->deletePrices(array_values($losingPrices), $confirmPriceRemoval);

        PriceTier::query()->whereKey($current->keys()->diff($keptIds)->all())->delete();
        // A plain array: `Eloquent\Collection::only()` drops the id keys.
        $this->writeTiers($scheme, $submitted, array_intersect_key($current->all(), array_flip($keptIds)));

        return $deletedPrices;
    }

    /**
     * @param  list<array{id: ?int}>  $submitted
     * @param  list<int|string>  $ownIds
     *
     * @throws ValidationException when a tier id is not one of this scheme or is sent twice
     */
    private function assertOwnTiers(array $submitted, array $ownIds): void
    {
        $seen = [];

        foreach ($submitted as $index => $tier) {
            $id = $tier['id'] ?? null;

            if ($id === null) {
                continue;
            }

            if (! in_array($id, $ownIds, true) || in_array($id, $seen, true)) {
                throw ValidationException::withMessages(["tiers.{$index}.id" => __('validation.scheme_tier_foreign')]);
            }

            $seen[] = $id;
        }
    }

    /**
     * Deletes the prices of the tiers that stop carrying them, after the confirmation and the product
     * locks. The rows are read again under the locks so the audit lists exactly what was deleted.
     *
     * @param  list<int>  $tierIds
     * @return list<array{id: int, product_id: int, code: ?string, size_group: ?string, tier: string, price: string}>
     *
     * @throws ValidationException when prices would be deleted and the user did not confirm
     */
    private function deletePrices(array $tierIds, bool $confirmed): array
    {
        $affected = AffectedPrices::forTiers($tierIds);

        if ($affected === []) {
            return [];
        }

        if (! $confirmed) {
            throw AffectedPrices::confirmationRequired($affected, 'tiers');
        }

        Product::query()->whereKey(AffectedPrices::productIds($affected))->orderBy('id')->lockForUpdate()->get();

        $affected = AffectedPrices::forTiers($tierIds);
        CombinationPrice::query()->whereKey(array_column($affected, 'id'))->delete();

        return $affected;
    }

    /**
     * Writes the submitted list in two passes so swapping labels or order never meets the unique
     * indexes: kept tiers are parked first, then every tier takes its final values.
     *
     * @param  list<array{id?: ?int, label: string, min: int, max: ?int, kind: string}>  $submitted
     * @param  array<int, PriceTier>  $kept
     */
    private function writeTiers(PriceScheme $scheme, array $submitted, array $kept): void
    {
        foreach ($kept as $tier) {
            $tier->forceFill(['label' => '~'.$tier->id, 'sort_order' => self::PARKING_ORDER + $tier->id])->save();
        }

        foreach ($submitted as $position => $tier) {
            $values = [
                'label' => $tier['label'],
                'min_quantity' => $tier['min'],
                'max_quantity' => $tier['max'],
                'kind' => PriceTierKind::from($tier['kind']),
                'sort_order' => $position + 1,
            ];

            $stored = $kept[$tier['id'] ?? 0] ?? null;

            if ($stored === null) {
                $scheme->tiers()->create($values);
            } else {
                $stored->forceFill($values)->save();
            }
        }
    }
}
