<?php

namespace App\Support\Quotations\Calculation;

/**
 * What a negotiated price was negotiated for (DEC-COT-55, design Decision 17): the resolved combination of
 * the line and the sorted distinct size groups its rows fall in (empty when the combination has no
 * groups). Quantities and the individual sizes are not part of it, so changing them within the same group
 * keeps the price; another combination or another set of groups discards it (E-72).
 */
final readonly class NegotiationBasis
{
    /**
     * @param  list<int>  $sizeGroupIds  sorted, distinct
     */
    private function __construct(
        public int $combinationId,
        public array $sizeGroupIds,
    ) {}

    /**
     * @param  list<?int>  $rowGroupIds  the size group of each row; null for a row in no group
     */
    public static function of(int $combinationId, array $rowGroupIds): self
    {
        $groups = array_values(array_unique(array_filter($rowGroupIds, fn (?int $id) => $id !== null)));
        sort($groups);

        return new self($combinationId, $groups);
    }

    public function matches(self $other): bool
    {
        return $this->combinationId === $other->combinationId && $this->sizeGroupIds === $other->sizeGroupIds;
    }
}
