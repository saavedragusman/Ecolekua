<?php

namespace App\Support\Pricing;

/**
 * What changing the size groups of a combination does to its stored groups and prices (DEC-COT-53,
 * DEC-COT-58, note N-9, design Decision 10). Pure: it is computed from plain rows and touches no
 * database, so the preview, the save and the 003 cleanup read the same answer.
 *
 * - A proposed group whose sizes equal a stored group's, or equal them plus sizes that were in no group,
 *   keeps that stored group and its prices (assigning a size that had no group is not a reorganization).
 * - Every other stored group is dropped (merged, split, a size moved between groups) and its prices are
 *   listed for deletion; every other proposed group is new and has no prices (COT-025).
 * - Going from ungrouped to grouped lists the prices without group; going back to ungrouped drops every
 *   group with its prices.
 *
 * A stored group is claimed by at most one proposed group, and a group without sizes is never kept.
 */
final readonly class SizeGroupPlan
{
    /**
     * @param  array<int, int>  $kept  proposed group index => stored group id
     * @param  list<int>  $dropped  stored group ids, in the stored order
     * @param  list<int>  $new  proposed group indexes with no stored group
     * @param  list<array{id: int, group: ?string, tier: string, price: string, updated_at: string}>  $deleted  in listing order
     */
    private function __construct(
        private array $kept,
        private array $dropped,
        private array $new,
        private array $deleted,
    ) {}

    /**
     * @param  list<array{id: int, size_ids: list<int>}>  $stored  stored groups, in their order
     * @param  list<list<int>>  $proposed  proposed groups (size value ids)
     * @param  list<int>  $ungrouped  admitted sizes that are in no stored group
     * @param  list<array{id: int, size_group_id: ?int, tier: string, tier_order: int, price: string, updated_at: string}>  $prices  loaded prices of the combination
     * @param  array<int, string>  $palette  size value id => name of the admitted sizes, in size order (labels the groups)
     */
    public static function diff(array $stored, array $proposed, array $ungrouped, array $prices, array $palette): self
    {
        $kept = self::matchStoredGroups($stored, $proposed, array_flip($ungrouped));
        $keptIds = array_values($kept);

        $dropped = [];
        $labels = [];
        $rank = [];

        foreach ($stored as $position => $group) {
            $labels[$group['id']] = $group['size_ids'] === [] ? null : SizeGroupLabel::of($group['size_ids'], $palette);
            $rank[$group['id']] = $position + 1;

            if (! in_array($group['id'], $keptIds, true)) {
                $dropped[] = $group['id'];
            }
        }

        $new = array_values(array_diff(array_keys($proposed), array_keys($kept)));
        $deleted = self::deletedRows($prices, $dropped, $proposed !== [], $labels, $rank);

        return new self($kept, $dropped, $new, $deleted);
    }

    /**
     * Stored group each proposed group keeps, by proposed index.
     *
     * @return array<int, int>
     */
    public function keptGroups(): array
    {
        return $this->kept;
    }

    /**
     * @return list<int>
     */
    public function droppedGroupIds(): array
    {
        return $this->dropped;
    }

    /**
     * @return list<int>
     */
    public function newGroupIndexes(): array
    {
        return $this->new;
    }

    /**
     * Ids of the prices the change deletes, in the order `deletedPrices()` lists them.
     *
     * @return list<int>
     */
    public function deletedPriceIds(): array
    {
        return array_column($this->deleted, 'id');
    }

    public function requiresConfirmation(): bool
    {
        return $this->deleted !== [];
    }

    /**
     * The prices the confirmation dialog lists, by group order (the prices without group first), then
     * tier order. `group` is the derived label of the stored group, null for a price without group.
     *
     * @return list<array{group: ?string, tier: string, price: string}>
     */
    public function deletedPrices(): array
    {
        return array_map(fn (array $row): array => [
            'group' => $row['group'],
            'tier' => $row['tier'],
            'price' => $row['price'],
        ], $this->deleted);
    }

    /**
     * Proof that the user confirmed exactly these prices: a sha256 of the ids, values and `updated_at`
     * of the deleted prices. A price written, changed or added between the preview and the save changes
     * it. Null when nothing is deleted.
     */
    public function confirmToken(): ?string
    {
        if ($this->deleted === []) {
            return null;
        }

        $rows = array_map(fn (array $row): array => [$row['id'], $row['price'], $row['updated_at']], $this->deleted);
        usort($rows, fn (array $a, array $b): int => $a[0] <=> $b[0]);

        return hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
    }

    /**
     * @param  list<array{id: int, size_ids: list<int>}>  $stored
     * @param  list<list<int>>  $proposed
     * @param  array<int, int>  $ungroupedSet  size id => position, only the keys matter
     * @return array<int, int>
     */
    private static function matchStoredGroups(array $stored, array $proposed, array $ungroupedSet): array
    {
        $kept = [];
        $claimed = [];

        foreach ($proposed as $index => $sizeIds) {
            foreach ($stored as $group) {
                if (isset($claimed[$group['id']]) || ! self::keeps($group['size_ids'], $sizeIds, $ungroupedSet)) {
                    continue;
                }

                $kept[$index] = $group['id'];
                $claimed[$group['id']] = true;

                break;
            }
        }

        return $kept;
    }

    /**
     * The proposed group is the stored one, or the stored one plus sizes that were in no group.
     *
     * @param  list<int>  $storedSizes
     * @param  list<int>  $proposedSizes
     * @param  array<int, int>  $ungroupedSet
     */
    private static function keeps(array $storedSizes, array $proposedSizes, array $ungroupedSet): bool
    {
        if ($storedSizes === [] || array_diff($storedSizes, $proposedSizes) !== []) {
            return false;
        }

        foreach (array_diff($proposedSizes, $storedSizes) as $added) {
            if (! isset($ungroupedSet[$added])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<array{id: int, size_group_id: ?int, tier: string, tier_order: int, price: string, updated_at: string}>  $prices
     * @param  list<int>  $dropped
     * @param  array<int, ?string>  $labels  stored group id => label
     * @param  array<int, int>  $rank  stored group id => position in the stored order
     * @return list<array{id: int, group: ?string, tier: string, price: string, updated_at: string}>
     */
    private static function deletedRows(array $prices, array $dropped, bool $grouped, array $labels, array $rank): array
    {
        $rows = [];

        foreach ($prices as $price) {
            $groupId = $price['size_group_id'];

            if ($groupId === null ? ! $grouped : ! in_array($groupId, $dropped, true)) {
                continue;
            }

            $rows[] = [
                'sort' => [$groupId === null ? 0 : $rank[$groupId], $price['tier_order'], $price['id']],
                'row' => [
                    'id' => $price['id'],
                    'group' => $groupId === null ? null : $labels[$groupId],
                    'tier' => $price['tier'],
                    'price' => $price['price'],
                    'updated_at' => $price['updated_at'],
                ],
            ];
        }

        usort($rows, fn (array $a, array $b): int => $a['sort'] <=> $b['sort']);

        return array_column($rows, 'row');
    }
}
