<?php

namespace App\Actions\Products;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Enums\BusinessLine;
use App\Enums\SupplyMode;
use App\Models\Product;
use App\Models\User;
use App\Support\Products\ProductAudit;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Edits the general data of a product (PRD-012, design Decision 10): name, category, business
 * line, supply mode, minimum stock, custom color and portal visibility. Status has its own Actions;
 * structure, details, customizations and minimum overrides arrive in later phases.
 *
 * Mode changes keep the supply mode rules (PRD-009, DEC-PRD-46, DEC-PRD-34): leaving
 * `stock_with_minimum` clears the default minimum (the audit row carries the previous value) and
 * returning to it requires a new one; leaving `on_demand` clears custom color. The deletion of the
 * own minimum overrides of the combinations (E-66) is completed in Phase 12, when that table exists.
 * The audit row carries only the fields that changed and nothing is written when nothing changed
 * (E-68, as `UpdateCustomer`).
 */
class UpdateProduct
{
    public function __construct(private readonly RecordAuditEvent $audit) {}

    /**
     * @param  array<string, mixed>  $data  validated input (see CatalogRules::productRules()); `description` and `portal_visible` keep their value when not sent
     *
     * @throws ValidationException when the name is taken by a concurrent request
     */
    public function handle(Product $product, array $data, User $actor): Product
    {
        try {
            return DB::transaction(fn (): Product => $this->update($product, $data, $actor));
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => __('validation.product_name_unique')]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function update(Product $product, array $data, User $actor): Product
    {
        $product = Product::query()->lockForUpdate()->findOrFail($product->id)->load('category');
        $before = ProductAudit::snapshot($product);

        $mode = SupplyMode::from($data['supply_mode']);
        $line = BusinessLine::from($data['business_line']);

        $this->ensureNotComboComponentChange($product, $line, $mode);
        $this->ensureNotServiceReferenced($product, $mode);

        $product->fill([
            'name' => $data['name'],
            'description' => array_key_exists('description', $data) ? $data['description'] : $product->description,
            'product_category_id' => $data['product_category_id'],
            'business_line' => $line,
            'supply_mode' => $mode,
            'min_stock_default' => $mode === SupplyMode::StockWithMinimum ? $data['min_stock_default'] : null,
            'allows_custom_color' => $this->customColor($product, $mode, $data['allows_custom_color'] ?? null),
            'portal_visible' => $data['portal_visible'] ?? $product->portal_visible,
        ])->save();

        $product->load('category');

        $after = ProductAudit::snapshot($product);
        $changed = array_keys(array_filter($after, fn (mixed $value, string $field): bool => $value !== $before[$field], ARRAY_FILTER_USE_BOTH));

        if ($changed !== []) {
            $this->audit->handle(
                AuditAction::ProductUpdated,
                $actor,
                $product,
                oldValues: array_intersect_key($before, array_flip($changed)),
                newValues: array_intersect_key($after, array_flip($changed)),
            );
        }

        return $product;
    }

    /**
     * Custom color is only stored for `on_demand` (DEC-PRD-34). Entering that mode without an explicit
     * choice defaults to true; staying in it keeps the current value.
     */
    private function customColor(Product $product, SupplyMode $mode, mixed $requested): bool
    {
        if ($mode !== SupplyMode::OnDemand) {
            return false;
        }

        if ($requested !== null) {
            return filter_var($requested, FILTER_VALIDATE_BOOLEAN);
        }

        return $product->supply_mode === SupplyMode::OnDemand ? $product->allows_custom_color : true;
    }

    /**
     * Hook for DEC-PRD-52 / E-70: changing the business line, or the mode to `service`, of a product
     * that is a combo component is rejected naming the combos. The combo tables arrive in Phase 13,
     * which fills this hook (task 13.6) with `CatalogUsage::combosUsingProduct()`.
     */
    private function ensureNotComboComponentChange(Product $product, BusinessLine $line, SupplyMode $mode): void
    {
        // Intentionally empty until task 13.6.
    }

    /**
     * Hook for DEC-PRD-52 / E-70: taking a product out of mode `service` is rejected while other
     * products admit it or combinations include it as a customization, naming them. Those tables
     * arrive in Phase 11, which fills this hook (task 11.2) with `CatalogUsage::productsUsingService()`.
     */
    private function ensureNotServiceReferenced(Product $product, SupplyMode $mode): void
    {
        // Intentionally empty until task 11.2.
    }
}
