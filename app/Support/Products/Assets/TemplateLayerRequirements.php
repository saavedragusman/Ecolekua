<?php

namespace App\Support\Products\Assets;

/**
 * Which layers a product template must have and which ones it lacks (PRD-020, E-37, E-38, E-40).
 * Pure: shared by `UploadProductTemplate`, the structure and detail guards of the product and the
 * layer guard of the catalog (design Decisions 9, 11 and 18).
 */
final class TemplateLayerRequirements
{
    /** The base layer every template has; color values paint it (DEC-PRD-97). */
    public const BASE = 'cuerpo';

    /**
     * @param  list<string>  $valueLayers  layers of the allowed attribute values that have one
     * @param  list<string>  $locationLayers  layers of the admitted detail locations that have one
     * @return list<string> `cuerpo` first, then the distinct layers sorted
     */
    public static function required(array $valueLayers, array $locationLayers): array
    {
        $layers = array_filter(
            array_values(array_unique([...$valueLayers, ...$locationLayers])),
            fn (string $layer): bool => $layer !== '' && $layer !== self::BASE,
        );
        sort($layers, SORT_STRING);

        return [self::BASE, ...$layers];
    }

    /**
     * @param  list<string>  $templateLayers  layers found in the template
     * @param  list<string>  $required
     * @return list<string> the required layers the template lacks, sorted
     */
    public static function missing(array $templateLayers, array $required): array
    {
        $missing = array_values(array_unique(array_diff($required, $templateLayers)));
        sort($missing, SORT_STRING);

        return $missing;
    }
}
