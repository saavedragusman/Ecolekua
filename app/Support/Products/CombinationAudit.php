<?php

namespace App\Support\Products;

use App\Enums\AttributeRole;
use App\Models\Combination;
use App\Models\ProductAttribute;
use Illuminate\Support\Facades\DB;

/**
 * Audit form of a combination (design "Audit payloads"): code, description and the axis values and
 * order restrictions by attribute and value name, in the display order of the product, and the
 * included customizations by name (PRD-008). So
 * `products.combination_created` carries every field and `products.combination_updated` the
 * previous and new values of the fields that changed.
 */
final class CombinationAudit
{
    /**
     * @return array{code: string|null, description: string|null, axes: array<string, list<string>>, restrictions: array<string, list<string>>, included_customizations: list<string>}
     */
    public static function snapshot(Combination $combination): array
    {
        $combination->load('catalogCode');

        $axes = [];
        $restrictions = [];
        $namesByAttribute = [];

        $rows = DB::table('combination_values')
            ->join('attribute_values', 'attribute_values.id', '=', 'combination_values.attribute_value_id')
            ->where('combination_values.combination_id', $combination->id)
            ->get(['combination_values.catalog_attribute_id', 'attribute_values.name']);

        foreach ($rows as $valueRow) {
            $namesByAttribute[(int) $valueRow->catalog_attribute_id][] = (string) $valueRow->name;
        }

        $declared = $combination->product->productAttributes()->with('catalogAttribute')->orderBy('sort_order')->orderBy('id')->get();

        /** @var ProductAttribute $row */
        foreach ($declared as $row) {
            $names = $namesByAttribute[$row->catalog_attribute_id] ?? [];

            if ($names === []) {
                continue;
            }

            sort($names);

            if ($row->role === AttributeRole::Axis) {
                $axes[$row->catalogAttribute->name] = $names;
            } else {
                $restrictions[$row->catalogAttribute->name] = $names;
            }
        }

        $included = $combination->customizations()->pluck('products.name')->map(fn (mixed $name): string => (string) $name)->all();
        sort($included);

        return [
            'code' => $combination->catalogCode?->code,
            'description' => $combination->description,
            'axes' => $axes,
            'restrictions' => $restrictions,
            'included_customizations' => $included,
        ];
    }
}
