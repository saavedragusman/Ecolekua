<?php

namespace App\Actions\Products;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Enums\CatalogStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Reactivates a catalog row (category, attribute, value or detail location). Only `status`
 * changes. An already active row is a no-op with no audit row (PRD-013 rule applied to catalog
 * items, same as `ActivateCustomer`).
 */
class ActivateCatalogItem
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

            if ($item->getAttribute('status') === CatalogStatus::Active) {
                return $item;
            }

            $item->forceFill(['status' => CatalogStatus::Active])->save();

            $this->audit->handle(
                AuditAction::CatalogActivated,
                $actor,
                $item,
                oldValues: ['status' => CatalogStatus::Inactive->value],
                newValues: ['status' => CatalogStatus::Active->value],
            );

            return $item;
        });
    }
}
