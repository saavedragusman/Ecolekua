<?php

namespace App\Actions\Products;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Enums\CatalogStatus;
use App\Models\Combination;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Deactivates a combination (PRD-013, design Decision 16). It takes the product lock like every
 * combination write, changes only `status` and audits it; an already inactive combination is a
 * no-op with no audit row. Its code stays reserved in the registry (DEC-PRD-01).
 */
class DeactivateCombination
{
    public function __construct(private readonly RecordAuditEvent $audit) {}

    public function handle(Combination $combination, User $actor): Combination
    {
        return DB::transaction(function () use ($combination, $actor): Combination {
            Product::query()->lockForUpdate()->findOrFail($combination->product_id);
            $combination = Combination::query()->lockForUpdate()->findOrFail($combination->id);

            if ($combination->status === CatalogStatus::Inactive) {
                return $combination;
            }

            $combination->forceFill(['status' => CatalogStatus::Inactive])->save();

            $this->audit->handle(
                AuditAction::CombinationDeactivated,
                $actor,
                $combination,
                oldValues: ['status' => CatalogStatus::Active->value],
                newValues: ['status' => CatalogStatus::Inactive->value],
            );

            return $combination;
        });
    }
}
