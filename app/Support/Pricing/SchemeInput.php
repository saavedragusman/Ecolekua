<?php

namespace App\Support\Pricing;

use App\Enums\PriceTierKind;
use App\Models\PriceScheme;
use Illuminate\Validation\Rule;

/**
 * Shape rules of the scheme payload shared by the create and update form requests (COT-001). They
 * only check types and bounds; the tier set as a whole is checked by `SchemeRules` inside the
 * Actions, so the seeder applies the same criteria.
 */
final class SchemeInput
{
    public const NAME_MAX_LENGTH = 60;

    /** Largest quantity an unsigned 32-bit tier limit can hold. */
    private const QUANTITY_MAX = 4294967295;

    /**
     * The `name` column is case-insensitive, so the unique rule ignores letter case (COT-001).
     *
     * @return list<mixed>
     */
    public static function nameRules(?PriceScheme $scheme): array
    {
        return ['required', 'string', 'max:'.self::NAME_MAX_LENGTH, Rule::unique('price_schemes', 'name')->ignore($scheme?->id)];
    }

    /**
     * The validated tier list with each field in its own type and the label trimmed, in the order
     * submitted. Tiers without an `id` are new; an `id` that is absent reads as null.
     *
     * @return list<array{id: ?int, label: string, min: int, max: ?int, kind: string}>
     */
    public static function normalizeTiers(mixed $tiers): array
    {
        $rows = [];

        if (is_array($tiers)) {
            // `validated()` builds the list rule by rule, so tiers without an `id` can come after the ones that have it.
            ksort($tiers);
        }

        foreach (is_array($tiers) ? $tiers : [] as $tier) {
            $rows[] = [
                'id' => isset($tier['id']) ? (int) $tier['id'] : null,
                'label' => trim((string) $tier['label']),
                'min' => (int) $tier['min'],
                'max' => isset($tier['max']) ? (int) $tier['max'] : null,
                'kind' => (string) $tier['kind'],
            ];
        }

        return $rows;
    }

    /**
     * Shape of each tier of the submitted list.
     *
     * @return array<string, list<mixed>>
     */
    public static function tierRules(): array
    {
        return [
            'tiers.*' => ['array'],
            'tiers.*.label' => ['required', 'string'],
            'tiers.*.min' => ['required', 'integer', 'min:1', 'max:'.self::QUANTITY_MAX],
            'tiers.*.max' => ['nullable', 'integer', 'min:1', 'max:'.self::QUANTITY_MAX],
            'tiers.*.kind' => ['required', Rule::enum(PriceTierKind::class)],
        ];
    }
}
