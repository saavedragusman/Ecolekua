<?php

namespace App\Actions\Products;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Enums\CatalogStatus;
use App\Models\CatalogAttribute;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Deactivates a catalog row (category, attribute, value or detail location). Only `status`
 * changes; catalog rows are never deleted (PRD-001, PRD-002). An already inactive row is a no-op
 * with no audit row. The in-use guard for attributes declared by products is an empty hook
 * until Phase 9 (E-69).
 */
class DeactivateCatalogItem
{
    public function __construct(private readonly RecordAuditEvent $audit) {}

    /**
     * @template TItem of Model
     *
     * @param  TItem  $item
     * @return TItem
     */
    public function handle(Model $item, User $actor): Model
    {
        return DB::transaction(function () use ($item, $actor): Model {
            $item = $item->newQuery()->whereKey($item->getKey())->lockForUpdate()->firstOrFail();

            if ($item->getAttribute('status') === CatalogStatus::Inactive) {
                return $item;
            }

            if ($item instanceof CatalogAttribute) {
                $this->ensureNotDeclaredByProducts($item);
            }

            $item->forceFill(['status' => CatalogStatus::Inactive])->save();

            $this->audit->handle(
                AuditAction::CatalogDeactivated,
                $actor,
                $item,
                oldValues: ['status' => CatalogStatus::Active->value],
                newValues: ['status' => CatalogStatus::Inactive->value],
            );

            return $item;
        });
    }

    /**
     * Hook for DEC-PRD-51 / E-69: an attribute declared by an active product cannot be deactivated,
     * and the error names the products. The product tables arrive in Phase 8 and
     * `CatalogUsage::productsDeclaring()` in Phase 9, which fills this hook (task 9.6).
     */
    private function ensureNotDeclaredByProducts(CatalogAttribute $attribute): void
    {
        // Intentionally empty until task 9.6.
    }
}
