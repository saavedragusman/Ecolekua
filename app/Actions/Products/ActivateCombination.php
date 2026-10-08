<?php

namespace App\Actions\Products;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Enums\CatalogStatus;
use App\Exceptions\BusinessRuleViolation;
use App\Models\Combination;
use App\Models\Product;
use App\Models\User;
use App\Support\Products\ProductCombinations;
use Illuminate\Support\Facades\DB;

/**
 * Reactivates a combination (PRD-013, design Decisions 5 and 16). The overlap check runs under the
 * product lock, with the same rule as the save, and a combination that coincides with another active
 * one is rejected naming it, with no side effect on the other (DEC-PRD-40, E-59). An already active
 * combination is a no-op with no audit row.
 */
class ActivateCombination
{
    public function __construct(private readonly RecordAuditEvent $audit) {}

    /**
     * @throws BusinessRuleViolation when the combination overlaps another active one
     */
    public function handle(Combination $combination, User $actor): Combination
    {
        return DB::transaction(function () use ($combination, $actor): Combination {
            $product = Product::query()->lockForUpdate()->findOrFail($combination->product_id);
            $combination = Combination::query()->lockForUpdate()->findOrFail($combination->id);

            if ($combination->status === CatalogStatus::Active) {
                return $combination;
            }

            $axes = ProductCombinations::axesOf($combination, ProductCombinations::declared($product));
            $overlapping = ProductCombinations::overlappingCode($product, $axes, $combination->id);

            if ($overlapping !== null) {
                throw new BusinessRuleViolation(__('validation.combination_overlap', ['code' => $overlapping]));
            }

            $combination->forceFill(['status' => CatalogStatus::Active])->save();

            $this->audit->handle(
                AuditAction::CombinationActivated,
                $actor,
                $combination,
                oldValues: ['status' => CatalogStatus::Inactive->value],
                newValues: ['status' => CatalogStatus::Active->value],
            );

            return $combination;
        });
    }
}
