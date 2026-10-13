<?php

namespace App\Actions\Products;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Enums\BusinessLine;
use App\Enums\SupplyMode;
use App\Models\Product;
use App\Models\User;
use App\Support\Products\CatalogUsage;
use App\Support\Products\ProductAudit;
use App\Support\Products\StockMinimum;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Edits the general data of a product (PRD-012, design Decision 10): name, category, business
 * line, supply mode, minimum stock, custom color and portal visibility, plus the admitted detail
 * locations and customizations (PRD-007, PRD-008), which replace the stored set when sent. Status
 * has its own Actions; structure and minimum overrides arrive in later phases.
 *
 * Mode changes keep the supply mode rules (PRD-009, DEC-PRD-46, DEC-PRD-34): leaving
 * `stock_with_minimum` clears the default minimum (the audit row carries the previous value) and
 * returning to it requires a new one; leaving `on_demand` clears custom color. Leaving
 * `stock_with_minimum` also deletes the own minimum overrides of the combinations in the same
 * transaction, and the audit row lists them (E-66). The audit row carries only the fields that changed and nothing is written when nothing changed
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
        $before = $this->snapshot($product);

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

        if ($mode !== SupplyMode::StockWithMinimum) {
            StockMinimum::deleteOverrides($product);
        }

        $this->syncRelations($product, $data);

        $product->load('category');

        $after = $this->snapshot($product);
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
     * DEC-PRD-52 / E-70: changing the business line, or the mode to `service`, of a product that is
     * a combo component is rejected naming the combos (combos hold diaper-line products that are not
     * services, DEC-PRD-43 and E-22). Each changed field reports its own error. Runs before any
     * write, under the product lock.
     *
     * @throws ValidationException on `business_line` and `supply_mode`
     */
    private function ensureNotComboComponentChange(Product $product, BusinessLine $line, SupplyMode $mode): void
    {
        $lineChanges = $line !== $product->business_line;
        $becomesService = $mode === SupplyMode::Service && $product->supply_mode !== SupplyMode::Service;

        if (! $lineChanges && ! $becomesService) {
            return;
        }

        $combos = CatalogUsage::combosUsingProduct($product->id);

        if ($combos === []) {
            return;
        }

        $replace = ['combos' => CatalogUsage::quote($combos)];
        $errors = [];

        if ($lineChanges) {
            $errors['business_line'] = __('validation.product_in_combos_line', $replace);
        }

        if ($becomesService) {
            $errors['supply_mode'] = __('validation.product_in_combos_service', $replace);
        }

        throw ValidationException::withMessages($errors);
    }

    /**
     * DEC-PRD-52 / E-70: taking a product out of mode `service` is rejected while other products
     * admit it or combinations include it as a customization, naming the products and the
     * combination codes. Runs before any write, under the product lock.
     *
     * @throws ValidationException on `supply_mode`
     */
    private function ensureNotServiceReferenced(Product $product, SupplyMode $mode): void
    {
        if ($product->supply_mode !== SupplyMode::Service || $mode === SupplyMode::Service) {
            return;
        }

        $usage = CatalogUsage::productsUsingService($product->id);
        $replace = [
            'products' => CatalogUsage::quote($usage['products']),
            'combinations' => CatalogUsage::quote($usage['combinations']),
        ];

        $message = match (true) {
            $usage['products'] !== [] && $usage['combinations'] !== [] => __('validation.service_in_use_both', $replace),
            $usage['products'] !== [] => __('validation.service_in_use_products', $replace),
            $usage['combinations'] !== [] => __('validation.service_in_use_combinations', $replace),
            default => null,
        };

        if ($message !== null) {
            throw ValidationException::withMessages(['supply_mode' => $message]);
        }
    }

    /**
     * Full replace of the detail locations (PRD-007) and admitted customizations (PRD-008) when the
     * request sends them; an omitted list keeps the stored set, like `description`. The ids were
     * validated by `CatalogRules::productRelationRules()`.
     *
     * @param  array<string, mixed>  $data
     */
    private function syncRelations(Product $product, array $data): void
    {
        if (array_key_exists('detail_location_ids', $data)) {
            $ids = $this->ids($data['detail_location_ids']);
            $this->ensureNoDetailLocationLayerGap($product, array_values(array_diff($ids, $product->detailLocations()->pluck('detail_locations.id')->all())));
            $product->detailLocations()->sync($ids);
        }

        if (array_key_exists('customization_ids', $data)) {
            $product->customizations()->sync($this->ids($data['customization_ids']));
        }
    }

    /**
     * Hook for PRD-020 / DEC-PRD-52: adding a location whose `svg_layer` is missing from any
     * template of the product is rejected with the missing layer. Templates arrive in Phase 21;
     * task 22.7 fills this hook with the layer requirements check.
     *
     * @param  list<int>  $addedLocationIds
     */
    private function ensureNoDetailLocationLayerGap(Product $product, array $addedLocationIds): void
    {
        // Intentionally empty until task 22.7.
    }

    /**
     * @return list<int>
     */
    private function ids(mixed $value): array
    {
        return array_values(array_unique(array_map('intval', (array) $value)));
    }

    /**
     * Audit form of the product: the general data plus the detail locations and customizations as
     * sorted name lists (design "Audit payloads").
     *
     * @return array<string, mixed>
     */
    private function snapshot(Product $product): array
    {
        $names = function (string $relation) use ($product): array {
            $names = $product->{$relation}()->pluck($relation === 'customizations' ? 'products.name' : 'detail_locations.name')->map(fn (mixed $name): string => (string) $name)->all();
            sort($names, SORT_STRING | SORT_FLAG_CASE);

            return $names;
        };

        return [
            ...ProductAudit::snapshot($product),
            'detail_locations' => $names('detailLocations'),
            'customizations' => $names('customizations'),
            'stock_minimum_overrides' => StockMinimum::snapshot($product),
        ];
    }
}
