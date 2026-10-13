<?php

namespace App\Actions\Products;

use App\Support\Products\Selection\CatalogSnapshotLoader;
use App\Support\Products\Selection\ComboSelectionRules;
use App\Support\Products\Selection\ResolvedCombo;
use App\Support\Products\Selection\ResolvedSelection;
use App\Support\Products\Selection\SelectionRules;
use Illuminate\Validation\ValidationException;

/**
 * Validates a selection of a product or a combo and resolves it to the combination that is sold
 * (PRD-010, PRD-011, DT-02). It is the one operation 004, 005 and 006 use, so no frontend
 * reimplements the rules (AGENTS.md section 7.1). It reads the catalog only: no price and no stock
 * (design Decision 14).
 *
 * Product input: `product_id`, `axes` and `order` (`attributeId => valueId`, or `custom` for the
 * color of the garment), `custom_color` (`tone`, `note`), `details` (`location_id`,
 * `color_value_id`) and `customizations` (service product ids). Combo input: `combo_id` and
 * `components` (`componentId => ` the product input of that component, without `product_id`); when
 * `combo_id` is present the selection is the combo's. An invalid selection raises a
 * `ValidationException` keyed by field (`components.{componentId}.` prefixes the fields of a
 * component), so a controller can return it unchanged.
 */
final class ResolveSelection
{
    public function __construct(private readonly CatalogSnapshotLoader $loader) {}

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function handle(array $input): ResolvedSelection|ResolvedCombo
    {
        return array_key_exists('combo_id', $input) ? $this->resolveCombo($input) : $this->resolveProduct($input);
    }

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    private function resolveProduct(array $input): ResolvedSelection
    {
        $productId = self::id($input['product_id'] ?? null);
        $snapshot = $productId === null ? null : $this->loader->forProduct($productId);

        if ($snapshot === null) {
            throw ValidationException::withMessages(['product' => __('validation.selection_unavailable')]);
        }

        $result = SelectionRules::validate($snapshot, $input, self::hasDetails($input) ? $this->loader->palette() : []);

        return is_array($result) ? throw self::invalid($result) : $result;
    }

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    private function resolveCombo(array $input): ResolvedCombo
    {
        $comboId = self::id($input['combo_id']);
        $combo = $comboId === null ? null : $this->loader->forCombo($comboId);

        if ($combo === null) {
            throw ValidationException::withMessages(['combo' => __('validation.selection_unavailable')]);
        }

        $components = is_array($input['components'] ?? null) ? $input['components'] : [];
        $details = array_filter($components, fn (mixed $component): bool => is_array($component) && self::hasDetails($component));
        $result = ComboSelectionRules::validate($combo, $input, $details !== [] ? $this->loader->palette() : []);

        return is_array($result) ? throw self::invalid($result) : $result;
    }

    /**
     * An id sent as an integer or as digits (DEC-PRD-83).
     */
    private static function id(mixed $input): ?int
    {
        return is_string($input) && ctype_digit($input) ? (int) $input : (is_int($input) ? $input : null);
    }

    /**
     * @param  array<array-key, mixed>  $selection
     */
    private static function hasDetails(array $selection): bool
    {
        return is_array($selection['details'] ?? null) && $selection['details'] !== [];
    }

    /**
     * @param  array<string, string>  $codes  reason code by field key
     */
    private static function invalid(array $codes): ValidationException
    {
        return ValidationException::withMessages(array_map(fn (string $code): string => __("validation.$code"), $codes));
    }
}
