<?php

namespace App\Actions\Products;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Enums\SupplyMode;
use App\Exceptions\BusinessRuleViolation;
use App\Models\Combination;
use App\Models\Product;
use App\Models\User;
use App\Support\Products\CatalogUsage;
use App\Support\Products\CombinationAudit;
use App\Support\Products\ProductAudit;
use App\Support\Products\StockMinimum;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Storage;

/**
 * Deletes a product that has no history, is not a combo component and, when it is a service, is not
 * admitted or included by anything else (PRD-014, DEC-PRD-52, design Decision 17). Its attributes,
 * allowed values, detail locations, customizations, combinations (with their codes, axis values and
 * own minimums) go in the same transaction through the foreign keys' cascade. The checks run first
 * under the product lock to give a friendly message; the restrict foreign keys stay as the backstop.
 * The audit row keeps a copy of the whole aggregate, and the stored files are deleted only after
 * the commit, so a rolled back delete never loses a file.
 */
class DeleteProduct
{
    private const DISK = 'local';

    public function __construct(private readonly RecordAuditEvent $audit) {}

    /**
     * @throws BusinessRuleViolation when the product is a combo component, a service in use or has history
     */
    public function handle(Product $product, User $actor): void
    {
        DB::transaction(function () use ($product, $actor): void {
            $product = Product::query()->lockForUpdate()->findOrFail($product->id)->load('category');

            $this->ensureNotComboComponent($product);
            $this->ensureServiceNotInUse($product);
            $this->ensureHasNoHistory($product);

            $copy = $this->copy($product);
            $files = array_values(array_filter([$product->image_display_path, $product->image_thumb_path]));

            $product->delete();

            $this->audit->handle(AuditAction::ProductDeleted, $actor, $product, oldValues: $copy);

            if ($files !== []) {
                DB::afterCommit(fn (): bool => Storage::disk(self::DISK)->delete($files));
            }
        });
    }

    /**
     * E-28: a component of any combo, whatever the status of the combo, cannot be deleted.
     *
     * @throws BusinessRuleViolation
     */
    private function ensureNotComboComponent(Product $product): void
    {
        $combos = CatalogUsage::combosUsingProduct($product->id);

        if ($combos !== []) {
            throw new BusinessRuleViolation(__('validation.delete_product_in_combos', ['combos' => CatalogUsage::quote($combos)]));
        }
    }

    /**
     * E-70 (delete): a service that other products admit or combinations include cannot be deleted;
     * the message names those products and the combination codes.
     *
     * @throws BusinessRuleViolation
     */
    private function ensureServiceNotInUse(Product $product): void
    {
        if ($product->supply_mode !== SupplyMode::Service) {
            return;
        }

        $usage = CatalogUsage::productsUsingService($product->id);
        $replace = [
            'products' => CatalogUsage::quote($usage['products']),
            'combinations' => CatalogUsage::quote($usage['combinations']),
        ];

        $key = match (true) {
            $usage['products'] !== [] && $usage['combinations'] !== [] => 'validation.delete_service_in_use_both',
            $usage['products'] !== [] => 'validation.delete_service_in_use_products',
            $usage['combinations'] !== [] => 'validation.delete_service_in_use_combinations',
            default => null,
        };

        if ($key !== null) {
            throw new BusinessRuleViolation(Lang::string($key, $replace));
        }
    }

    /**
     * Blocks the delete when the product or any of its combinations has history (E-29, DEC-PRD-21).
     * No condition exists in 003 because no table references a product or combination yet. Specs 004
     * (quotations), 006 (orders) and 008 (stock movements) each add their condition here, throwing
     * a `BusinessRuleViolation` that suggests deactivating, and bring their own E-29 test (a `todo`
     * in `DeleteProductTest` until then). Their tables must reference `combinations.id` with
     * `restrictOnDelete()`.
     */
    private function ensureHasNoHistory(Product $product): void
    {
        // Intentionally empty in 003.
    }

    /**
     * Full copy of the aggregate in audit form (design "Audit payloads"): general data, admitted
     * detail locations and customizations as sorted names, structure, every combination with its
     * code and own minimums. `template_files` stays empty until the SVG templates exist (Phase 22
     * lists their file names here).
     *
     * @return array<string, mixed>
     */
    private function copy(Product $product): array
    {
        $names = function (string $relation, string $column) use ($product): array {
            $names = $product->{$relation}()->pluck($column)->map(fn (mixed $name): string => (string) $name)->all();
            sort($names, SORT_STRING | SORT_FLAG_CASE);

            return $names;
        };

        $combinations = $product->combinations()->orderBy('id')->get()
            ->map(fn (Combination $combination): array => CombinationAudit::snapshot($combination))
            ->all();

        return [
            ...ProductAudit::snapshot($product),
            'detail_locations' => $names('detailLocations', 'detail_locations.name'),
            'customizations' => $names('customizations', 'products.name'),
            'structure' => ProductAudit::structure($product->productAttributes()->with(['catalogAttribute', 'allowedValues'])->get()),
            'combinations' => $combinations,
            'stock_minimum_overrides' => StockMinimum::snapshot($product),
            'template_files' => [],
        ];
    }
}
