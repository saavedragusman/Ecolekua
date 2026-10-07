<?php

namespace App\Actions\Products;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Moves an ordered catalog row one position up or down by swapping `sort_order` with its
 * neighbor (design Decision 9). Both rows are locked in the same transaction and each gets a
 * `catalog.updated` audit row with its old and new `sort_order`. Moving the first row up or the
 * last row down is a no-op without audit. Generic over every catalog entity that has `sort_order`
 * (categories now; attributes and values reuse it in later slices).
 */
class MoveCatalogItem
{
    public const UP = 'up';

    public const DOWN = 'down';

    public function __construct(private readonly RecordAuditEvent $audit) {}

    public function handle(Model $item, string $direction, User $actor): void
    {
        DB::transaction(function () use ($item, $direction, $actor): void {
            $item = $item->newQuery()->whereKey($item->getKey())->lockForUpdate()->firstOrFail();
            $order = (int) $item->getAttribute('sort_order');

            $neighbor = $item->newQuery()
                ->when(
                    $direction === self::UP,
                    fn ($query) => $query->where('sort_order', '<', $order)->orderByDesc('sort_order'),
                    fn ($query) => $query->where('sort_order', '>', $order)->orderBy('sort_order'),
                )
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            if ($neighbor === null) {
                return;
            }

            $neighborOrder = (int) $neighbor->getAttribute('sort_order');

            $item->forceFill(['sort_order' => $neighborOrder])->save();
            $neighbor->forceFill(['sort_order' => $order])->save();

            $this->audit->handle(
                AuditAction::CatalogUpdated,
                $actor,
                $item,
                oldValues: ['sort_order' => $order],
                newValues: ['sort_order' => $neighborOrder],
            );
            $this->audit->handle(
                AuditAction::CatalogUpdated,
                $actor,
                $neighbor,
                oldValues: ['sort_order' => $neighborOrder],
                newValues: ['sort_order' => $order],
            );
        });
    }
}
