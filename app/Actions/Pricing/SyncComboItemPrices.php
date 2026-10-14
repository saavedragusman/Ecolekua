<?php

namespace App\Actions\Pricing;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Models\Combo;
use App\Models\ComboComponent;
use App\Models\ComboItemPrice;
use App\Models\User;
use App\Support\Audit\AuditOrigin;
use App\Support\Pricing\InvalidAmount;
use App\Support\Pricing\Money;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Illuminate\Validation\ValidationException;

/**
 * Saves the unit price of the components of one combo (COT-005, DEC-COT-06, design Decision 12). The
 * combo price is never stored: `Σ price × quantity` is computed where it is shown or quoted.
 *
 * - Lock: the combo row, the lock that 003 combo edits take, so prices and component edits serialize.
 * - An entry is `{combo_component_id, price|null}`. The component belongs to the combo (active or
 *   inactive); the price is greater than zero with at most 2 decimals (as COT-003). A `null` price
 *   deletes the price of the component; a `null` over a component without price changes nothing.
 * - Every entry is checked before anything is written, so one invalid entry saves nothing. Only changed
 *   entries are written, each with one audit row `pricing.combo_item_price_created|updated|deleted` on
 *   the `ComboItemPrice` carrying `{combo_code, component, product, quantity, price}`, where `component`
 *   is the 1-based number of the component in the combo order (the number the price import uses).
 */
class SyncComboItemPrices
{
    public function __construct(private readonly RecordAuditEvent $audit) {}

    /**
     * @param  list<array{combo_component_id: int, price: string|null}>  $entries
     * @param  array<string, mixed>  $auditContext
     *
     * @throws ValidationException when an entry breaks a rule of the combo prices
     */
    public function handle(Combo $combo, array $entries, User $actor, ?AuditOrigin $origin = null, array $auditContext = []): void
    {
        DB::transaction(function () use ($combo, $entries, $actor, $origin, $auditContext): void {
            $combo = Combo::query()->lockForUpdate()->with('catalogCode')->findOrFail($combo->id);
            $components = $combo->components()->with(['product', 'price'])->get()->values();
            $numbers = $components->mapWithKeys(fn (ComboComponent $component, int $index): array => [$component->id => $index + 1]);
            $components = $components->keyBy('id');

            $errors = [];
            $amounts = [];
            $seen = [];

            foreach ($entries as $index => $entry) {
                $id = $entry['combo_component_id'];

                if (! $components->has($id)) {
                    $errors["prices.{$index}.combo_component_id"][] = Lang::string('validation.combo_price_component_foreign');
                } elseif (isset($seen[$id])) {
                    $errors["prices.{$index}.combo_component_id"][] = Lang::string('validation.combo_price_component_duplicate');
                }

                $seen[$id] = true;
                $amounts[$index] = $this->amount($entry['price'] ?? null, $index, $errors);
            }

            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            foreach ($entries as $index => $entry) {
                /** @var ComboComponent $component */
                $component = $components->get($entry['combo_component_id']);
                $number = $numbers->get($component->id);
                $existing = $component->price;
                $amount = $amounts[$index];

                if ($amount === null) {
                    if ($existing !== null) {
                        $this->delete($existing, $combo, $component, $number, $actor, $origin, $auditContext);
                    }
                } elseif ($existing === null) {
                    $this->create($combo, $component, $number, $amount, $actor, $origin, $auditContext);
                } elseif (! $existing->unit_price->isEqualTo($amount)) {
                    $this->update($existing, $combo, $component, $number, $amount, $actor, $origin, $auditContext);
                }
            }
        });
    }

    /**
     * `null` for an empty entry (deletes), the exact amount otherwise; an invalid amount adds its error.
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
            $errors["prices.{$index}.price"][] = Lang::string('validation.price_invalid');

            return null;
        }

        if (! $amount->isPositive()) {
            $errors["prices.{$index}.price"][] = Lang::string('validation.price_invalid');

            return null;
        }

        return $amount;
    }

    /**
     * @param  array<string, mixed>  $auditContext
     */
    private function create(Combo $combo, ComboComponent $component, int $number, BigDecimal $amount, User $actor, ?AuditOrigin $origin, array $auditContext): void
    {
        $price = ComboItemPrice::query()->create([
            'combo_component_id' => $component->id,
            'unit_price' => Money::toStorage($amount, 2),
            'updated_by' => $actor->id,
        ]);

        $this->audit->handle(AuditAction::ComboItemPriceCreated, $actor, $price, newValues: self::snapshot($combo, $component, $number, $amount), context: $auditContext, origin: $origin);
    }

    /**
     * @param  array<string, mixed>  $auditContext
     */
    private function update(ComboItemPrice $price, Combo $combo, ComboComponent $component, int $number, BigDecimal $amount, User $actor, ?AuditOrigin $origin, array $auditContext): void
    {
        $previous = self::snapshot($combo, $component, $number, $price->unit_price);

        $price->update(['unit_price' => Money::toStorage($amount, 2), 'updated_by' => $actor->id]);

        $this->audit->handle(AuditAction::ComboItemPriceUpdated, $actor, $price, oldValues: $previous, newValues: self::snapshot($combo, $component, $number, $amount), context: $auditContext, origin: $origin);
    }

    /**
     * @param  array<string, mixed>  $auditContext
     */
    private function delete(ComboItemPrice $price, Combo $combo, ComboComponent $component, int $number, User $actor, ?AuditOrigin $origin, array $auditContext): void
    {
        $previous = self::snapshot($combo, $component, $number, $price->unit_price);

        $price->delete();

        $this->audit->handle(AuditAction::ComboItemPriceDeleted, $actor, $price, oldValues: $previous, context: $auditContext, origin: $origin);
    }

    /**
     * What the audit keeps of a component price, with the amount as a canonical string.
     *
     * @return array{combo_code: ?string, component: int, product: string, quantity: int, price: string}
     */
    private static function snapshot(Combo $combo, ComboComponent $component, int $number, BigDecimal $amount): array
    {
        return [
            'combo_code' => $combo->code,
            'component' => $number,
            'product' => $component->product->name,
            'quantity' => $component->quantity,
            'price' => Money::toStorage($amount, 2),
        ];
    }
}
