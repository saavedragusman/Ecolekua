<?php

namespace App\Actions\Products;

use App\Support\Products\Selection\CatalogSnapshotLoader;
use App\Support\Products\Selection\ComboSelectionRules;
use App\Support\Products\Selection\SelectionOptions;
use App\Support\Products\Selection\SelectionRules;
use Illuminate\Validation\ValidationException;

/**
 * Lists the options available for a partial selection of a product, or of one component of a combo
 * (PRD-019, DT-02). It is a query without effects that 004 and 005 use to show each step of the
 * quoter, with the same data and criteria as `ResolveSelection`, so no frontend computes options
 * (AGENTS.md section 7.1). It reads the catalog only: no price and no stock (design Decision 14).
 *
 * Input: `product_id`, or `combo_id` and `component_id`, and `axes` (`attributeId => valueId`), which
 * must be the first axes of the product in order. The result is `SelectionOptions`: the next axis
 * while axes are missing, or the combination and the order options once all are chosen. An invalid
 * query raises a `ValidationException` keyed by field, like `ResolveSelection`.
 */
final class ListSelectionOptions
{
    public function __construct(private readonly CatalogSnapshotLoader $loader) {}

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function handle(array $input): SelectionOptions
    {
        $palette = $this->loader->palette(...);
        $axes = ['axes' => $input['axes'] ?? null];

        if (array_key_exists('combo_id', $input)) {
            $comboId = self::id($input['combo_id']);
            $combo = $comboId === null ? null : $this->loader->forCombo($comboId);
            $result = $combo === null
                ? ['combo' => 'selection_unavailable']
                : ComboSelectionRules::options($combo, self::id($input['component_id'] ?? null), $axes, $palette);
        } else {
            $productId = self::id($input['product_id'] ?? null);
            $snapshot = $productId === null ? null : $this->loader->forProduct($productId);
            $result = $snapshot === null ? ['product' => 'selection_unavailable'] : SelectionRules::options($snapshot, $axes, $palette);
        }

        return is_array($result)
            ? throw ValidationException::withMessages(array_map(fn (string $code): string => __("validation.$code"), $result))
            : $result;
    }

    /**
     * An id sent as an integer or as digits (DEC-PRD-83).
     */
    private static function id(mixed $input): ?int
    {
        return is_string($input) && ctype_digit($input) ? (int) $input : (is_int($input) ? $input : null);
    }
}
