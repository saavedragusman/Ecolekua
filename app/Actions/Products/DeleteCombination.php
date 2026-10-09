<?php

namespace App\Actions\Products;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AttributeRole;
use App\Enums\AuditAction;
use App\Exceptions\BusinessRuleViolation;
use App\Models\Combination;
use App\Models\Product;
use App\Models\User;
use App\Support\Products\CatalogUsage;
use App\Support\Products\CombinationAudit;
use App\Support\Products\ProductCombinations;
use App\Support\Products\StockMinimum;
use Illuminate\Support\Facades\DB;

/**
 * Deletes a combination that has no history and is not part of a combo (PRD-014, design
 * Decision 17). It takes the product lock like every combination write, so it serializes with the
 * combo writes that read the product. A combination is part of a combo when the customer could
 * choose it in one of the combos where its product is a component (DEC-PRD-42, E-61). The registry
 * row, the axis values, the included customizations and the own minimums go with it through the
 * foreign keys' cascade; the audit row keeps the full copy, including the code, so the deleted code
 * can be traced even though it is free again.
 */
class DeleteCombination
{
    public function __construct(private readonly RecordAuditEvent $audit) {}

    /**
     * @throws BusinessRuleViolation when the combination is part of a combo or has history
     */
    public function handle(Combination $combination, User $actor): void
    {
        DB::transaction(function () use ($combination, $actor): void {
            $product = Product::query()->lockForUpdate()->findOrFail($combination->product_id);
            $combination = Combination::query()->lockForUpdate()->findOrFail($combination->id);

            $this->ensureNotPartOfCombo($product, $combination);
            $this->ensureHasNoHistory($combination);

            $copy = CombinationAudit::snapshot($combination);
            $copy['stock_minimum_overrides'] = array_values(array_filter(
                StockMinimum::snapshot($product),
                fn (array $row): bool => $row['code'] === $copy['code'],
            ));

            $combination->delete();

            $this->audit->handle(AuditAction::CombinationDeleted, $actor, $combination, oldValues: $copy);
        });
    }

    /**
     * E-61: rejects the delete naming the combos the combination is part of and suggesting to
     * deactivate it. Runs before any write, under the product lock.
     *
     * @throws BusinessRuleViolation
     */
    private function ensureNotPartOfCombo(Product $product, Combination $combination): void
    {
        $declared = ProductCombinations::declared($product);
        $axisIds = array_column(array_filter($declared, fn (array $attribute): bool => $attribute['role'] === AttributeRole::Axis), 'attribute_id');

        $combos = CatalogUsage::combosIncludingCombination($product->id, ProductCombinations::axesOf($combination, $declared), $axisIds);

        if ($combos !== []) {
            throw new BusinessRuleViolation(__('validation.delete_combination_in_combos', ['combos' => CatalogUsage::quote($combos)]));
        }
    }

    /**
     * Blocks the delete when the combination has history (E-29, DEC-PRD-21). No condition exists in
     * 003 because no table references a combination yet. Specs 004 (quotations), 006 (orders) and
     * 008 (stock movements) each add their condition here, throwing a `BusinessRuleViolation` that
     * suggests deactivating, and bring their own E-29 test (a `todo` in `DeleteProductTest` until
     * then). Their tables must reference `combinations.id` with `restrictOnDelete()` (design
     * Decision 3, "Rule for later specs").
     */
    private function ensureHasNoHistory(Combination $combination): void
    {
        // Intentionally empty in 003.
    }
}
