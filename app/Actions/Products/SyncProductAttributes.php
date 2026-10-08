<?php

namespace App\Actions\Products;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AttributePresentation;
use App\Enums\AttributeRole;
use App\Enums\AttributeSpecialUse;
use App\Enums\AuditAction;
use App\Enums\CatalogStatus;
use App\Models\AttributeValue;
use App\Models\CatalogAttribute;
use App\Models\CatalogCode;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\User;
use App\Support\Products\CatalogUsage;
use App\Support\Products\ProductRules;
use App\Support\Products\StockMinimum;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Replaces the whole structure of a product: the attributes it declares, their role and order and
 * the values it admits (PRD-004, design Decision 11). Everything runs in one transaction under the
 * product lock, which serializes it with the combination writes of the same product (Decision 5):
 *
 * 1. each attribute once; a newly declared attribute and newly admitted values must be active;
 * 2. values belong to their attribute and at least one is active, except the color of a product that
 *    declares the fabric, which admits none (DEC-PRD-35);
 * 3. role rule (DEC-PRD-50): `ProductRules::roleViolation()`;
 * 4. freeze while combinations exist (DEC-PRD-41, E-60);
 * 5. no admitted value (or order attribute) used by a combination or restricted by a combo component
 *    can be removed (DEC-PRD-37, E-24, E-57);
 * 6. layer guard: an empty hook, filled in Phase 22;
 * 7. the own minimum stock of a removed size is deleted (DEC-PRD-52, E-71), after the empty hook
 *    `ensureNoStockForRemovedSizes()` that spec 008 fills;
 * 8. audit with the full previous and new structure; the deleted minimums go in `old_values`.
 */
class SyncProductAttributes
{
    public function __construct(private readonly RecordAuditEvent $audit) {}

    /**
     * @param  list<array{attribute_id: int|string, role: string, allowed_value_ids?: list<int|string>|null}>  $entries  validated input, in display order
     *
     * @throws ValidationException when a structure rule rejects the change
     */
    public function handle(Product $product, array $entries, User $actor): Product
    {
        return DB::transaction(function () use ($product, $entries, $actor): Product {
            $product = Product::query()->lockForUpdate()->findOrFail($product->id);

            $current = $product->productAttributes()->with(['catalogAttribute', 'allowedValues'])->get()->keyBy('catalog_attribute_id');
            $before = $this->structure($current->values());

            $declared = $this->normalize($entries);
            $this->ensureEachAttributeOnce($declared);

            // Attributes are locked in id order, so a concurrent deactivation (which locks the same
            // row) either happens before this check or waits until this transaction ends.
            $attributes = CatalogAttribute::query()->whereIn('id', array_column($declared, 'attribute_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $values = AttributeValue::query()->whereIn('id', array_merge([], ...array_column($declared, 'value_ids')))->get()->keyBy('id');

            $this->ensureDeclarable($declared, $attributes, $current);
            $this->ensureAllowedValues($declared, $attributes, $values, $current);
            $this->ensureRoles($declared, $attributes);
            $this->ensureStructureNotFrozen($product, $declared, $current);

            $removedValueIds = $this->removedValueIds($declared, $current);
            $this->ensureRemovedValuesNotUsedByCombinations($product, $removedValueIds);
            $this->ensureNoComboUsesRemovedValues($product, $removedValueIds, $this->removedAttributeIds($declared, $current));
            $this->ensureLayerGuard($product, $declared, $current);
            $this->ensureNoStockForRemovedSizes($product, $removedValueIds);

            // Step 7 (DEC-PRD-52, E-71): the own minimums keyed by a removed size go with it. Values
            // used by a restriction were rejected above, so only unrestricted sizes reach this point.
            $deletedOverrides = StockMinimum::deleteOverrides($product, $removedValueIds);

            $this->persist($product, $declared, $current);

            $after = $this->structure($product->productAttributes()->with(['catalogAttribute', 'allowedValues'])->get());

            if ($after !== $before) {
                $this->audit->handle(
                    AuditAction::ProductAttributesUpdated,
                    $actor,
                    $product,
                    oldValues: ['attributes' => $before] + ($deletedOverrides === [] ? [] : ['stock_minimum_overrides' => $deletedOverrides]),
                    newValues: ['attributes' => $after],
                );
            }

            return $product;
        });
    }

    /**
     * @param  list<array{attribute_id: int|string, role: string, allowed_value_ids?: list<int|string>|null}>  $entries
     * @return list<array{attribute_id: int, role: AttributeRole, value_ids: list<int>}>
     */
    private function normalize(array $entries): array
    {
        return array_map(fn (array $entry): array => [
            'attribute_id' => (int) $entry['attribute_id'],
            'role' => AttributeRole::from($entry['role']),
            'value_ids' => array_values(array_unique(array_map('intval', $entry['allowed_value_ids'] ?? []))),
        ], $entries);
    }

    /**
     * @param  list<array{attribute_id: int, role: AttributeRole, value_ids: list<int>}>  $declared
     *
     * @throws ValidationException
     */
    private function ensureEachAttributeOnce(array $declared): void
    {
        $seen = [];

        foreach ($declared as $index => $entry) {
            if (isset($seen[$entry['attribute_id']])) {
                throw ValidationException::withMessages(["attributes.{$index}.attribute_id" => __('validation.structure_attribute_repeated')]);
            }

            $seen[$entry['attribute_id']] = true;
        }
    }

    /**
     * An inactive attribute cannot be newly declared (DEC-PRD-51, E-69); one the product already
     * declares may stay even if it was deactivated afterwards.
     *
     * @param  list<array{attribute_id: int, role: AttributeRole, value_ids: list<int>}>  $declared
     * @param  Collection<int, CatalogAttribute>  $attributes
     * @param  Collection<int, ProductAttribute>  $current
     *
     * @throws ValidationException
     */
    private function ensureDeclarable(array $declared, Collection $attributes, Collection $current): void
    {
        foreach ($declared as $index => $entry) {
            $attribute = $attributes->get($entry['attribute_id']);

            if ($attribute === null || ($attribute->status !== CatalogStatus::Active && ! $current->has($entry['attribute_id']))) {
                throw ValidationException::withMessages(["attributes.{$index}.attribute_id" => __('validation.structure_attribute_inactive')]);
            }
        }
    }

    /**
     * @param  list<array{attribute_id: int, role: AttributeRole, value_ids: list<int>}>  $declared
     * @param  Collection<int, CatalogAttribute>  $attributes
     * @param  Collection<int, AttributeValue>  $values
     * @param  Collection<int, ProductAttribute>  $current
     *
     * @throws ValidationException
     */
    private function ensureAllowedValues(array $declared, Collection $attributes, Collection $values, Collection $current): void
    {
        $declaresFabric = $attributes->contains(fn (CatalogAttribute $attribute): bool => $attribute->special_use === AttributeSpecialUse::Fabric);

        foreach ($declared as $index => $entry) {
            $attribute = $attributes->get($entry['attribute_id']);
            $field = "attributes.{$index}.allowed_value_ids";

            if ($attribute->presentation === AttributePresentation::Color && $declaresFabric) {
                if ($entry['value_ids'] !== []) {
                    throw ValidationException::withMessages([$field => __('validation.structure_color_values_not_allowed')]);
                }

                continue;
            }

            $previous = $current->get($entry['attribute_id'])?->allowedValues->modelKeys() ?? [];
            $hasActive = false;

            foreach ($entry['value_ids'] as $valueId) {
                $value = $values->get($valueId);

                if ($value === null || $value->catalog_attribute_id !== $attribute->id) {
                    throw ValidationException::withMessages([$field => __('validation.structure_value_foreign')]);
                }

                if ($value->status !== CatalogStatus::Active && ! in_array($valueId, $previous, true)) {
                    throw ValidationException::withMessages([$field => __('validation.structure_value_inactive')]);
                }

                $hasActive = $hasActive || $value->status === CatalogStatus::Active;
            }

            if (! $hasActive) {
                throw ValidationException::withMessages([$field => __('validation.structure_values_required')]);
            }
        }
    }

    /**
     * @param  list<array{attribute_id: int, role: AttributeRole, value_ids: list<int>}>  $declared
     * @param  Collection<int, CatalogAttribute>  $attributes
     *
     * @throws ValidationException
     */
    private function ensureRoles(array $declared, Collection $attributes): void
    {
        $violation = ProductRules::roleViolation(array_map(fn (array $entry): array => [
            'attribute_id' => $entry['attribute_id'],
            'role' => $entry['role'],
            'presentation' => $attributes->get($entry['attribute_id'])->presentation,
            'special_use' => $attributes->get($entry['attribute_id'])->special_use,
        ], $declared));

        if ($violation === null) {
            return;
        }

        $index = array_search($violation, array_column($declared, 'attribute_id'), true);
        $message = $attributes->get($violation)->special_use === AttributeSpecialUse::Fabric
            ? __('validation.structure_role_fabric')
            : __('validation.structure_role_color');

        throw ValidationException::withMessages(["attributes.{$index}.role" => $message]);
    }

    /**
     * While the product has combinations (any status) no axis can be added, no role can change and
     * no axis can be removed (DEC-PRD-41, E-60). Order attributes and the order of the axes stay free.
     *
     * @param  list<array{attribute_id: int, role: AttributeRole, value_ids: list<int>}>  $declared
     * @param  Collection<int, ProductAttribute>  $current
     *
     * @throws ValidationException
     */
    private function ensureStructureNotFrozen(Product $product, array $declared, Collection $current): void
    {
        if (! $product->combinations()->exists()) {
            return;
        }

        $newRoles = [];

        foreach ($declared as $entry) {
            $newRoles[$entry['attribute_id']] = $entry['role'];
        }

        foreach ($newRoles as $attributeId => $role) {
            $previous = $current->get($attributeId)?->role;

            if ($previous !== $role && ($role === AttributeRole::Axis || $previous !== null)) {
                throw ValidationException::withMessages(['attributes' => __('validation.structure_frozen')]);
            }
        }

        foreach ($current as $attributeId => $declaredAttribute) {
            if ($declaredAttribute->role === AttributeRole::Axis && ! isset($newRoles[$attributeId])) {
                throw ValidationException::withMessages(['attributes' => __('validation.structure_axis_removal_frozen')]);
            }
        }
    }

    /**
     * Allowed values the new structure no longer admits, including every value of a removed attribute.
     *
     * @param  list<array{attribute_id: int, role: AttributeRole, value_ids: list<int>}>  $declared
     * @param  Collection<int, ProductAttribute>  $current
     * @return list<int>
     */
    private function removedValueIds(array $declared, Collection $current): array
    {
        $kept = [];

        foreach ($declared as $entry) {
            $kept[$entry['attribute_id']] = $entry['value_ids'];
        }

        $removed = [];

        foreach ($current as $attributeId => $declaredAttribute) {
            foreach ($declaredAttribute->allowedValues->modelKeys() as $valueId) {
                if (! in_array($valueId, $kept[$attributeId] ?? [], true)) {
                    $removed[] = $valueId;
                }
            }
        }

        return $removed;
    }

    /**
     * @param  list<int>  $removedValueIds
     *
     * @throws ValidationException naming the combinations that use a removed value
     */
    private function ensureRemovedValuesNotUsedByCombinations(Product $product, array $removedValueIds): void
    {
        if ($removedValueIds === []) {
            return;
        }

        $combinationIds = DB::table('combination_values')
            ->join('combinations', 'combinations.id', '=', 'combination_values.combination_id')
            ->where('combinations.product_id', $product->id)
            ->whereIn('combination_values.attribute_value_id', $removedValueIds)
            ->distinct()
            ->pluck('combination_values.combination_id')
            ->all();

        if ($combinationIds === []) {
            return;
        }

        $codes = CatalogCode::query()->whereIn('combination_id', $combinationIds)->orderBy('code')->pluck('code', 'combination_id');
        $names = array_map(fn (int $id): string => $codes->get($id, "#{$id}"), $combinationIds);
        sort($names);

        throw ValidationException::withMessages(['attributes' => __('validation.structure_value_in_use', ['combinations' => CatalogUsage::quote($names)])]);
    }

    /**
     * Attributes of the current structure that the new one no longer declares.
     *
     * @param  list<array{attribute_id: int, role: AttributeRole, value_ids: list<int>}>  $declared
     * @param  Collection<int, ProductAttribute>  $current
     * @return list<int>
     */
    private function removedAttributeIds(array $declared, Collection $current): array
    {
        return array_values(array_diff($current->keys()->all(), array_column($declared, 'attribute_id')));
    }

    /**
     * DEC-PRD-37, DEC-PRD-44 / E-24, E-57: removing an allowed value, or a whole attribute, that a
     * combo component of this product restricts is rejected naming the combos. The attribute is
     * checked as well because the color of a product with fabric has no allowed values of its own
     * (DEC-PRD-35): a component restricts it to colors the fabrics offer, so removing the attribute
     * is the only way to break that restriction.
     *
     * @param  list<int>  $removedValueIds
     * @param  list<int>  $removedAttributeIds
     *
     * @throws ValidationException naming the combos that restrict a removed value or attribute
     */
    private function ensureNoComboUsesRemovedValues(Product $product, array $removedValueIds, array $removedAttributeIds): void
    {
        if ($removedValueIds === [] && $removedAttributeIds === []) {
            return;
        }

        $combos = CatalogUsage::combosRestricting($product->id, $removedValueIds, $removedAttributeIds);

        if ($combos !== []) {
            throw ValidationException::withMessages(['attributes' => __('validation.structure_value_in_combos', ['combos' => CatalogUsage::quote($combos)])]);
        }
    }

    /**
     * Hook for PRD-020 / E-40: a newly admitted value whose `svg_layer` is missing from any template
     * of the product must be rejected naming the layer. Templates arrive in Phase 22, which fills
     * this hook (task 22.7) with `TemplateLayerRequirements::missing()`.
     *
     * @param  list<array{attribute_id: int, role: AttributeRole, value_ids: list<int>}>  $declared
     * @param  Collection<int, ProductAttribute>  $current
     */
    private function ensureLayerGuard(Product $product, array $declared, Collection $current): void
    {
        // Intentionally empty until task 22.7.
    }

    /**
     * Hook for DEC-PRD-52 / spec 003 section 10: spec 008 blocks removing a size while stock of that
     * size exists. There is no stock yet, so nothing is checked.
     *
     * @param  list<int>  $removedValueIds
     */
    private function ensureNoStockForRemovedSizes(Product $product, array $removedValueIds): void
    {
        // Intentionally empty until spec 008.
    }

    /**
     * @param  list<array{attribute_id: int, role: AttributeRole, value_ids: list<int>}>  $declared
     * @param  Collection<int, ProductAttribute>  $current
     */
    private function persist(Product $product, array $declared, Collection $current): void
    {
        $keptIds = array_column($declared, 'attribute_id');

        foreach ($current as $attributeId => $declaredAttribute) {
            if (! in_array($attributeId, $keptIds, true)) {
                $declaredAttribute->delete();
            }
        }

        foreach ($declared as $position => $entry) {
            $row = $current->get($entry['attribute_id']) ?? new ProductAttribute(['product_id' => $product->id, 'catalog_attribute_id' => $entry['attribute_id']]);
            $row->fill(['role' => $entry['role'], 'sort_order' => $position + 1])->save();
            $row->allowedValues()->sync($entry['value_ids']);
        }
    }

    /**
     * Audit form of the structure: attribute name, role and sorted value names, in display order.
     *
     * @param  iterable<ProductAttribute>  $declaredAttributes
     * @return list<array{attribute: string, role: string, values: list<string>}>
     */
    private function structure(iterable $declaredAttributes): array
    {
        $structure = [];

        foreach ($declaredAttributes as $declaredAttribute) {
            $names = array_map(fn (AttributeValue $value): string => $value->name, $declaredAttribute->allowedValues->all());
            sort($names);

            $structure[] = [
                'attribute' => $declaredAttribute->catalogAttribute->name,
                'role' => $declaredAttribute->role->value,
                'values' => $names,
            ];
        }

        return $structure;
    }
}
