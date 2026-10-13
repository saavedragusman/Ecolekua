<?php

namespace App\Actions\Pricing;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Enums\PriceTierKind;
use App\Models\Combination;
use App\Models\CombinationPrice;
use App\Models\PriceScheme;
use App\Models\PriceTier;
use App\Models\Product;
use App\Models\SizeGroup;
use App\Models\User;
use App\Support\Audit\AuditOrigin;
use App\Support\Pricing\AffectedPrices;
use App\Support\Pricing\InvalidAmount;
use App\Support\Pricing\Money;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Illuminate\Validation\ValidationException;

/**
 * Saves the price table of one product: one row per combination, one column per priced tier of its
 * scheme, and per size group when the combination has groups (COT-003, COT-004, DEC-COT-32, design
 * Decision 10).
 *
 * - Lock order (Decision 10): the scheme row, then the product row. The product row is the lock that
 *   003 combination writes take, so prices and combination edits serialize.
 * - A cell is `{combination_id, size_group_id|null, price_tier_id, price|null}`. The combination
 *   belongs to the product (active or inactive); the tier is a priced tier of the product's current
 *   scheme (a fixed scheme has one); a combination with groups needs one of its own groups, any other
 *   one takes none; the price is greater than zero with at most 2 decimals (E-06).
 * - A `null` price deletes the price of the cell: this table is the only place where prices are
 *   deleted (DEC-COT-32). A `null` over an empty cell changes nothing.
 * - Every cell is checked before anything is written, so one invalid cell saves nothing. Only changed
 *   cells are written, each with one audit row `pricing.price_created|updated|deleted` on the
 *   `CombinationPrice` carrying `{code, size_group, tier, price}` (E-05).
 */
class SyncCombinationPrices
{
    public function __construct(private readonly RecordAuditEvent $audit) {}

    /**
     * @param  list<array{combination_id: int, size_group_id?: int|null, price_tier_id: int, price: string|null}>  $cells
     * @param  array<string, mixed>  $auditContext
     *
     * @throws ValidationException when the product has no scheme or a cell breaks a rule of the table
     */
    public function handle(Product $product, array $cells, User $actor, ?AuditOrigin $origin = null, array $auditContext = []): void
    {
        DB::transaction(function () use ($product, $cells, $actor, $origin, $auditContext): void {
            $schemeId = Product::query()->whereKey($product->id)->value('price_scheme_id');

            if ($schemeId !== null) {
                PriceScheme::query()->lockForUpdate()->findOrFail($schemeId);
            }

            $product = Product::query()->lockForUpdate()->findOrFail($product->id);

            if ($product->price_scheme_id === null) {
                throw ValidationException::withMessages(['cells' => __('validation.price_table_no_scheme')]);
            }

            if ($product->price_scheme_id !== $schemeId) {
                throw ValidationException::withMessages(['cells' => __('validation.price_table_scheme_changed')]);
            }

            $tiers = PriceTier::query()->where('price_scheme_id', $product->price_scheme_id)->get()->keyBy('id');
            $combinations = Combination::query()
                ->where('product_id', $product->id)
                ->whereKey(array_column($cells, 'combination_id'))
                ->with('catalogCode')
                ->get()
                ->keyBy('id');
            $groups = SizeGroup::query()->whereIn('combination_id', $combinations->keys())->with('sizes')->get()->keyBy('id');
            $stored = CombinationPrice::query()->whereIn('combination_id', $combinations->keys())->get()->keyBy(
                fn (CombinationPrice $price): string => self::cellKey($price->combination_id, $price->price_tier_id, $price->size_group_id),
            );

            $errors = [];
            $amounts = [];
            $seen = [];

            foreach ($cells as $index => $cell) {
                $combination = $combinations->get($cell['combination_id']);
                $tier = $tiers->get($cell['price_tier_id']);
                $group = isset($cell['size_group_id']) ? $groups->get($cell['size_group_id']) : null;

                if ($combination === null) {
                    $errors["cells.{$index}.combination_id"][] = Lang::string('validation.price_combination_foreign');
                }

                if ($tier === null) {
                    $errors["cells.{$index}.price_tier_id"][] = Lang::string('validation.scheme_tier_foreign');
                } elseif ($tier->kind !== PriceTierKind::Priced) {
                    $errors["cells.{$index}.price"][] = Lang::string('validation.price_tier_not_priced');
                }

                if ($combination !== null) {
                    $error = $this->groupError($combination, $cell['size_group_id'] ?? null, $group, $groups->where('combination_id', $combination->id)->isNotEmpty());

                    if ($error !== null) {
                        $errors["cells.{$index}.size_group_id"][] = $error;
                    }
                }

                if ($combination !== null && $tier !== null) {
                    $key = self::cellKey($combination->id, $tier->id, $group?->id);

                    if (isset($seen[$key])) {
                        $errors["cells.{$index}.price_tier_id"][] = Lang::string('validation.price_cell_duplicate');
                    }

                    $seen[$key] = true;
                }

                $amounts[$index] = $this->amount($cell['price'] ?? null, $index, $errors);
            }

            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            foreach ($cells as $index => $cell) {
                $combination = $combinations->get($cell['combination_id']);
                $tier = $tiers->get($cell['price_tier_id']);
                $group = isset($cell['size_group_id']) ? $groups->get($cell['size_group_id']) : null;
                $existing = $stored->get(self::cellKey($combination->id, $tier->id, $group?->id));
                $amount = $amounts[$index];

                if ($amount === null) {
                    if ($existing !== null) {
                        $this->delete($existing, $combination, $group, $tier, $actor, $origin, $auditContext);
                    }
                } elseif ($existing === null) {
                    $this->create($combination, $group, $tier, $amount, $actor, $origin, $auditContext);
                } elseif (! $existing->unit_price->isEqualTo($amount)) {
                    $this->update($existing, $combination, $group, $tier, $amount, $actor, $origin, $auditContext);
                }
            }
        });
    }

    /**
     * The group rule of a cell: a combination that has groups needs one of its own, any other takes none.
     */
    private function groupError(Combination $combination, ?int $groupId, ?SizeGroup $group, bool $hasGroups): ?string
    {
        if (! $hasGroups) {
            return $groupId === null ? null : Lang::string('validation.price_size_group_unexpected');
        }

        if ($groupId === null) {
            return Lang::string('validation.price_size_group_required');
        }

        return $group?->combination_id === $combination->id ? null : Lang::string('validation.price_size_group_foreign');
    }

    /**
     * `null` for an empty cell (deletes), the exact amount otherwise; an invalid amount adds its error.
     *
     * @param  array<string, list<string>>  $errors
     */
    private function amount(?string $price, int $index, array &$errors): ?BigDecimal
    {
        if ($price === null) {
            return null;
        }

        try {
            $amount = Money::parse($price, 2);
        } catch (InvalidAmount) {
            $errors["cells.{$index}.price"][] = Lang::string('validation.price_invalid');

            return null;
        }

        if (! $amount->isPositive()) {
            $errors["cells.{$index}.price"][] = Lang::string('validation.price_invalid');

            return null;
        }

        return $amount;
    }

    /**
     * @param  array<string, mixed>  $auditContext
     */
    private function create(Combination $combination, ?SizeGroup $group, PriceTier $tier, BigDecimal $amount, User $actor, ?AuditOrigin $origin, array $auditContext): void
    {
        $price = CombinationPrice::query()->create([
            'combination_id' => $combination->id,
            'price_tier_id' => $tier->id,
            'size_group_id' => $group?->id,
            'unit_price' => Money::toStorage($amount, 2),
            'updated_by' => $actor->id,
        ]);

        $this->audit->handle(AuditAction::PriceCreated, $actor, $price, newValues: self::snapshot($combination, $group, $tier, $amount), context: $auditContext, origin: $origin);
    }

    /**
     * @param  array<string, mixed>  $auditContext
     */
    private function update(CombinationPrice $price, Combination $combination, ?SizeGroup $group, PriceTier $tier, BigDecimal $amount, User $actor, ?AuditOrigin $origin, array $auditContext): void
    {
        $previous = self::snapshot($combination, $group, $tier, $price->unit_price);

        $price->update(['unit_price' => Money::toStorage($amount, 2), 'updated_by' => $actor->id]);

        $this->audit->handle(AuditAction::PriceUpdated, $actor, $price, oldValues: $previous, newValues: self::snapshot($combination, $group, $tier, $amount), context: $auditContext, origin: $origin);
    }

    /**
     * @param  array<string, mixed>  $auditContext
     */
    private function delete(CombinationPrice $price, Combination $combination, ?SizeGroup $group, PriceTier $tier, User $actor, ?AuditOrigin $origin, array $auditContext): void
    {
        $previous = self::snapshot($combination, $group, $tier, $price->unit_price);

        $price->delete();

        $this->audit->handle(AuditAction::PriceDeleted, $actor, $price, oldValues: $previous, context: $auditContext, origin: $origin);
    }

    /**
     * What the audit keeps of a price: `{code, size_group, tier, price}` with the amount as a canonical string.
     *
     * @return array{code: ?string, size_group: ?string, tier: string, price: string}
     */
    private static function snapshot(Combination $combination, ?SizeGroup $group, PriceTier $tier, BigDecimal $amount): array
    {
        return [
            'code' => $combination->code,
            'size_group' => AffectedPrices::sizeGroupName($group),
            'tier' => $tier->label,
            'price' => Money::toStorage($amount, 2),
        ];
    }

    /**
     * One key per cell of the table: a missing size group counts as group `0`, like `size_group_key`.
     */
    private static function cellKey(int $combinationId, int $tierId, ?int $groupId): string
    {
        return $combinationId.':'.$tierId.':'.($groupId ?? 0);
    }
}
