<?php

namespace App\Actions\Products;

use App\Support\Products\Selection\CatalogSnapshotLoader;
use App\Support\Products\Selection\ResolvedSelection;
use App\Support\Products\Selection\SelectionRules;
use Illuminate\Validation\ValidationException;

/**
 * Validates a selection of a product and resolves it to the combination that is sold (PRD-011, DT-02).
 * It is the one operation 004, 005 and 006 use, so no frontend reimplements the rules (AGENTS.md
 * section 7.1). It reads the catalog only: no price and no stock (design Decision 14).
 *
 * Input: `product_id`, `axes` and `order` (`attributeId => valueId`, or `custom` for the color of
 * the garment), `custom_color` (`tone`, `note`), `details` (`location_id`, `color_value_id`) and
 * `customizations` (service product ids). An invalid selection raises a `ValidationException` keyed
 * by field, so a controller can return it unchanged. Combos arrive with unit 14b.
 */
final class ResolveSelection
{
    public function __construct(private readonly CatalogSnapshotLoader $loader) {}

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function handle(array $input): ResolvedSelection
    {
        $productId = $input['product_id'] ?? null;
        $productId = is_string($productId) && ctype_digit($productId) ? (int) $productId : $productId;
        $snapshot = is_int($productId) ? $this->loader->forProduct($productId) : null;

        if ($snapshot === null) {
            throw ValidationException::withMessages(['product' => __('validation.selection_unavailable')]);
        }

        $details = $input['details'] ?? null;
        $result = SelectionRules::validate($snapshot, $input, is_array($details) && $details !== [] ? $this->loader->palette() : []);

        if (is_array($result)) {
            throw ValidationException::withMessages(array_map(fn (string $code): string => __("validation.$code"), $result));
        }

        return $result;
    }
}
