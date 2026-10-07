<?php

namespace App\Actions\Products;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * Moves an ordered catalog row one position up or down by swapping `sort_order` with its
 * neighbor (design Decision 9). The scope is locked in id order in one query (no deadlocks, with
 * retry as a backstop); ties on `sort_order` are resolved by id, renumbering the scope when needed.
 * Each moved row gets a `catalog.updated` audit row with its old and new `sort_order`. Moving the first row up or the
 * last row down is a no-op without audit. Generic over every catalog entity that has `sort_order`
 * (categories now; attributes and values reuse it in later slices).
 */
class MoveCatalogItem
{
    public const UP = 'up';

    public const DOWN = 'down';

    public function __construct(private readonly RecordAuditEvent $audit) {}

    /** Retries when MySQL aborts the transaction as a deadlock victim. */
    private const ATTEMPTS = 3;

    public function handle(Model $item, string $direction, User $actor): void
    {
        DB::transaction(function () use ($item, $direction, $actor): void {
            // One locking query in ascending id order: every concurrent move takes the locks in
            // the same order, so two opposite moves cannot deadlock. The scope is a handful of rows.
            $rows = $item->newQuery()->orderBy('id')->lockForUpdate()->get();

            // Observable order is (sort_order, id), so rows that tie on sort_order are still ordered.
            $ordered = $rows->sort(fn (Model $a, Model $b): int => [(int) $a->getAttribute('sort_order'), $a->getKey()] <=> [(int) $b->getAttribute('sort_order'), $b->getKey()])->values()->all();

            $position = null;

            foreach ($ordered as $index => $row) {
                if ($row->is($item)) {
                    $position = $index;
                }
            }

            if ($position === null) {
                throw (new ModelNotFoundException)->setModel($item::class, [$item->getKey()]);
            }

            $neighborPosition = $direction === self::UP ? $position - 1 : $position + 1;

            if (! isset($ordered[$neighborPosition])) {
                return;
            }

            $moved = $ordered[$position];
            $neighbor = $ordered[$neighborPosition];
            $movedOrder = (int) $moved->getAttribute('sort_order');
            $neighborOrder = (int) $neighbor->getAttribute('sort_order');

            if ($movedOrder !== $neighborOrder) {
                $moved->forceFill(['sort_order' => $neighborOrder])->save();
                $neighbor->forceFill(['sort_order' => $movedOrder])->save();
            } else {
                // A tie cannot be swapped: renumber the scope 1..n in the swapped order.
                $ordered[$position] = $neighbor;
                $ordered[$neighborPosition] = $moved;

                foreach ($ordered as $index => $row) {
                    if ((int) $row->getAttribute('sort_order') !== $index + 1) {
                        $row->forceFill(['sort_order' => $index + 1])->save();
                    }
                }
            }

            $this->audit->handle(
                AuditAction::CatalogUpdated,
                $actor,
                $moved,
                oldValues: ['sort_order' => $movedOrder],
                newValues: ['sort_order' => (int) $moved->getAttribute('sort_order')],
            );
            $this->audit->handle(
                AuditAction::CatalogUpdated,
                $actor,
                $neighbor,
                oldValues: ['sort_order' => $neighborOrder],
                newValues: ['sort_order' => (int) $neighbor->getAttribute('sort_order')],
            );
        }, self::ATTEMPTS);
    }
}
