<?php

namespace App\Support\Products\Selection;

/**
 * Options available for a partial selection (PRD-019): the values of the next axis while axes are
 * missing (`stage: axis`) or, with every axis chosen, the combination and the order options
 * (`stage: order`). Array-serializable so 004 and 005 pass it to their frontends unchanged. It holds
 * snapshots and formats them; prices and stock never appear (Decision 14).
 *
 * Every option carries `id`, `name`, `sort_order`, `description`, `image_urls`, `tone` and `layer`.
 * `image_urls` is empty until the images exist (Phase 21).
 */
final readonly class SelectionOptions
{
    /**
     * @param  list<ValueSnapshot>  $axisValues
     * @param  list<array{attribute: AttributeSnapshot, values: list<ValueSnapshot>, allowsCustomColor: bool}>  $order
     * @param  list<LocationSnapshot>  $locations
     * @param  list<ValueSnapshot>  $palette
     * @param  list<ServiceSnapshot>  $customizations
     */
    private function __construct(
        public string $stage,
        public ?AttributeSnapshot $axis,
        public array $axisValues,
        public ?CombinationSnapshot $combination,
        public array $order,
        public array $locations,
        public array $palette,
        public array $customizations,
    ) {}

    /**
     * @param  list<ValueSnapshot>  $values
     */
    public static function axis(AttributeSnapshot $attribute, array $values): self
    {
        return new self('axis', $attribute, $values, null, [], [], [], []);
    }

    /**
     * @param  list<array{attribute: AttributeSnapshot, values: list<ValueSnapshot>, allowsCustomColor: bool}>  $order
     * @param  list<LocationSnapshot>  $locations
     * @param  list<ValueSnapshot>  $palette
     * @param  list<ServiceSnapshot>  $customizations
     */
    public static function order(CombinationSnapshot $combination, array $order, array $locations, array $palette, array $customizations): self
    {
        return new self('order', null, [], $combination, $order, $locations, $palette, $customizations);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        if ($this->axis !== null) {
            return [
                'stage' => $this->stage,
                'axis' => [
                    'attribute_id' => $this->axis->id,
                    'attribute' => $this->axis->name,
                    'options' => array_map(self::option(...), $this->axisValues),
                ],
            ];
        }

        return [
            'stage' => $this->stage,
            'code' => $this->combination?->code,
            'combination_id' => $this->combination?->id,
            'order' => array_map(fn (array $group): array => [
                'attribute_id' => $group['attribute']->id,
                'attribute' => $group['attribute']->name,
                'options' => array_map(self::option(...), $group['values']),
                'allows_custom_color' => $group['allowsCustomColor'],
            ], $this->order),
            'detail_locations' => array_map(fn (LocationSnapshot $location): array => [
                'id' => $location->id, 'name' => $location->name, 'layer' => $location->layer, 'image_urls' => [],
            ], $this->locations),
            'palette' => array_map(self::option(...), $this->palette),
            'customizations' => array_map(fn (ServiceSnapshot $service): array => ['id' => $service->id, 'name' => $service->name], $this->customizations),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function option(ValueSnapshot $value): array
    {
        return [
            'id' => $value->id,
            'name' => $value->name,
            'sort_order' => $value->sortOrder,
            'description' => $value->description,
            'image_urls' => [],
            'tone' => $value->tone,
            'layer' => $value->layer,
        ];
    }
}
