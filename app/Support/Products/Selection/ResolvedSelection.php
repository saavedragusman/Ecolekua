<?php

namespace App\Support\Products\Selection;

/**
 * Result of resolving a valid selection of a product (PRD-011): the sellable combination and the
 * normalized selection, with the name and tone of each color or the tone and note of the custom
 * one. Array-serializable so 004/005/006 can pass it along unchanged.
 */
final readonly class ResolvedSelection
{
    /**
     * @param  list<array<string, mixed>>  $axes
     * @param  list<array<string, mixed>>  $order
     * @param  list<array<string, mixed>>  $details
     * @param  list<array{id: int, name: string}>  $customizations
     * @param  list<array{id: int, name: string}>  $includedCustomizations
     */
    public function __construct(
        public string $kind,
        public string $code,
        public int $combinationId,
        public int $productId,
        public string $productName,
        public string $descriptiveName,
        public array $axes,
        public array $order,
        public array $details,
        public array $customizations,
        public array $includedCustomizations,
        public bool $requiresAdvisor,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'code' => $this->code,
            'combination_id' => $this->combinationId,
            'product' => ['id' => $this->productId, 'name' => $this->productName],
            'descriptive_name' => $this->descriptiveName,
            'axes' => $this->axes,
            'order' => $this->order,
            'details' => $this->details,
            'customizations' => $this->customizations,
            'included_customizations' => $this->includedCustomizations,
            'requires_advisor' => $this->requiresAdvisor,
        ];
    }
}
