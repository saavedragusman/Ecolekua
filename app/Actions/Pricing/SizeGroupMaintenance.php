<?php

namespace App\Actions\Pricing;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Models\Combination;
use App\Models\CombinationPrice;
use App\Models\SizeGroup;
use App\Models\User;
use App\Support\Audit\AuditOrigin;
use App\Support\Pricing\AdmittedSizes;
use App\Support\Pricing\Money;
use App\Support\Pricing\SizeGroupLabel;
use Illuminate\Support\Facades\DB;

/**
 * Keeps the size groups coherent when 003 takes a size away from a product or a combination
 * (COT-004, DEC-COT-48, design Decision 10). The size leaves its group with a copy in the audit; a
 * group left empty is deleted with its prices, listing them in the audit. A group that keeps other
 * sizes keeps its prices.
 *
 * The callers are the 003 Actions that remove sizes, inside their own transaction and under the
 * product lock, and **before** they save the change: the sizes the combination admits are then still
 * the ones the groups were defined against, which is what labels the groups in the audit.
 */
class SizeGroupMaintenance
{
    public function __construct(private readonly RecordAuditEvent $audit) {}

    /**
     * Takes the sizes out of the groups of each combination; one `pricing.size_groups_updated` audit
     * row per combination that held any of them, with `reason: size_removed`.
     *
     * @param  list<int>  $combinationIds
     * @param  list<int>  $sizeValueIds
     * @param  array<string, mixed>  $auditContext
     */
    public function removeSizes(array $combinationIds, array $sizeValueIds, User $actor, ?AuditOrigin $origin = null, array $auditContext = []): void
    {
        if ($combinationIds === [] || $sizeValueIds === []) {
            return;
        }

        $affected = DB::table('size_group_values')
            ->whereIn('combination_id', $combinationIds)
            ->whereIn('size_value_id', $sizeValueIds)
            ->distinct()
            ->orderBy('combination_id')
            ->pluck('combination_id');

        foreach ($affected as $combinationId) {
            $combination = Combination::query()->with('catalogCode')->findOrFail((int) $combinationId);

            $this->removeFromCombination($combination, $sizeValueIds, $actor, $origin, [...$auditContext, 'reason' => 'size_removed']);
        }
    }

    /**
     * A combination about to be saved with these restrictions (`attributeId => valueIds`) loses the
     * sizes it no longer admits from its groups.
     *
     * @param  array<int, list<int>>  $restrictions
     */
    public function restrictTo(Combination $combination, array $restrictions, User $actor): void
    {
        if (! $combination->sizeGroups()->exists()) {
            return;
        }

        $this->removeSizes([$combination->id], AdmittedSizes::narrowedBy($combination, $restrictions), $actor);
    }

    /**
     * @param  list<int>  $sizeValueIds
     * @param  array<string, mixed>  $auditContext
     */
    private function removeFromCombination(Combination $combination, array $sizeValueIds, User $actor, ?AuditOrigin $origin, array $auditContext): void
    {
        $groups = $combination->sizeGroups()->with('sizes')->get();
        $palette = AdmittedSizes::forCombinations([$combination->id])[$combination->id] ?? [];

        foreach ($groups as $group) {
            foreach ($group->sizes as $size) {
                $palette[$size->id] ??= $size->name;
            }
        }

        $inOrder = fn (array $ids): array => array_values(array_intersect(array_keys($palette), $ids));
        $names = fn (array $ids): array => array_values(array_intersect_key($palette, array_flip($ids)));

        $before = [];
        $after = [];
        $removed = [];
        $emptied = [];

        foreach ($groups as $group) {
            $members = $inOrder(array_map(intval(...), $group->sizes->modelKeys()));
            $leaving = array_values(array_intersect($members, $sizeValueIds));
            $remaining = array_values(array_diff($members, $leaving));
            $before[] = $names($members);
            $removed = [...$removed, ...$leaving];

            if ($leaving !== [] && $remaining === []) {
                $emptied[$group->id] = SizeGroupLabel::of($members, $palette);
            } else {
                $after[] = $names($remaining);
            }
        }

        $deleted = $this->deletedPrices($combination, array_values(array_map(intval(...), $groups->modelKeys())), $emptied);

        DB::table('size_group_values')->where('combination_id', $combination->id)->whereIn('size_value_id', $sizeValueIds)->delete();
        SizeGroup::query()->whereKey(array_keys($emptied))->delete();

        $this->audit->handle(
            AuditAction::SizeGroupsUpdated,
            $actor,
            $combination,
            oldValues: ['partition' => $before, 'removed_sizes' => $names($removed), 'deleted_prices' => $deleted],
            newValues: ['partition' => $after],
            context: $auditContext,
            origin: $origin,
        );
    }

    /**
     * The prices of the groups about to be deleted, by group order and then tier order, in audit form.
     *
     * @param  list<int>  $groupOrder  group ids in their stored order
     * @param  array<int, string>  $emptied  group id => label of the groups being deleted
     * @return list<array{code: ?string, size_group: string, tier: string, price: string}>
     */
    private function deletedPrices(Combination $combination, array $groupOrder, array $emptied): array
    {
        $rank = array_flip($groupOrder);

        $prices = CombinationPrice::query()
            ->whereIn('size_group_id', array_keys($emptied))
            ->with('tier')
            ->get()
            ->sortBy(fn (CombinationPrice $price): string => sprintf('%05d-%05d-%010d', $rank[(int) $price->size_group_id], $price->tier->sort_order, $price->id));

        $rows = [];

        foreach ($prices as $price) {
            $rows[] = [
                'code' => $combination->code,
                'size_group' => $emptied[(int) $price->size_group_id],
                'tier' => $price->tier->label,
                'price' => Money::toStorage($price->unit_price, 2),
            ];
        }

        return $rows;
    }
}
