<?php

namespace App\Support\Products\Selection;

/**
 * Result of resolving a valid selection of a combo (PRD-010, PRD-011): one resolved selection per
 * component, with the quantity the component carries. The choice of a component applies to all its
 * units (DEC-PRD-12). Array-serializable so 004/005/006 can pass it along unchanged.
 */
final readonly class ResolvedCombo
{
    /**
     * @param  list<array{component_id: int, quantity: int, selection: ResolvedSelection}>  $components
     */
    public function __construct(
        public int $comboId,
        public string $name,
        public string $code,
        public array $components,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'kind' => 'combo',
            'combo' => ['id' => $this->comboId, 'name' => $this->name, 'code' => $this->code],
            'components' => array_map(fn (array $component): array => [
                'component_id' => $component['component_id'],
                'quantity' => $component['quantity'],
                'selection' => $component['selection']->toArray(),
            ], $this->components),
            'requires_advisor' => array_filter($this->components, fn (array $component): bool => $component['selection']->requiresAdvisor) !== [],
        ];
    }
}
