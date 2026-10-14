<?php

namespace App\Support\Pricing;

/**
 * Pure validation of the ordered tier list of a price scheme (COT-001, E-02, design Decision 8). The
 * Actions run it before writing, so the seeder and the HTTP endpoints apply exactly the same rules.
 *
 * Tier shape: `label`, `min`, `max` (null in the open last tier) and `kind` (`priced` or
 * `negotiation`). Errors are keyed `tiers` for the set and `tiers.{i}.{field}` for one tier, the keys
 * of the validation bag the screens read.
 */
final class SchemeRules
{
    public const LABEL_MAX_LENGTH = 30;

    /**
     * Fixed headers of the price CSV (COT-027). Tier columns are mapped by label, so a label equal to
     * one of them (after `mb_strtolower(trim())`) would be ambiguous.
     */
    public const RESERVED_HEADERS = ['codigo', 'grupo_talla', 'producto', 'nombre', 'estado', 'precio', 'codigo_combo', 'componente', 'cantidad', 'precio_item'];

    private const KINDS = ['priced', 'negotiation'];

    /**
     * @param  list<array{id?: int, label: string, min: int, max: ?int, kind: string}>  $tiers
     * @return array<string, string> field => message (empty when valid)
     */
    public static function validateTiers(array $tiers): array
    {
        if ($tiers === []) {
            return ['tiers' => 'El esquema debe tener al menos un tramo.'];
        }

        $errors = [];
        $lastIndex = array_key_last($tiers);
        $seenLabels = [];

        foreach ($tiers as $index => $tier) {
            $isLast = $index === $lastIndex;

            self::checkLabel($errors, $index, $tier['label'], $seenLabels);
            self::checkKind($errors, $index, $tier['kind'], $isLast);
            self::checkLimits($errors, $index, $tier, $isLast, $index === 0 ? null : $tiers[$index - 1]);
        }

        return $errors;
    }

    /**
     * @param  array<string, string>  $errors
     * @param  array<string, true>  $seenLabels
     */
    private static function checkLabel(array &$errors, int $index, string $label, array &$seenLabels): void
    {
        $normalized = mb_strtolower(trim($label));
        $length = mb_strlen(trim($label));

        if ($length < 1 || $length > self::LABEL_MAX_LENGTH) {
            $errors["tiers.{$index}.label"] = 'El nombre del tramo debe tener entre 1 y '.self::LABEL_MAX_LENGTH.' caracteres.';

            return;
        }

        if (in_array($normalized, self::RESERVED_HEADERS, true)) {
            $errors["tiers.{$index}.label"] = 'El nombre del tramo coincide con una columna fija de la hoja de precios; use otro.';

            return;
        }

        if (isset($seenLabels[$normalized])) {
            $errors["tiers.{$index}.label"] = 'Ya existe un tramo con ese nombre en el esquema.';

            return;
        }

        $seenLabels[$normalized] = true;
    }

    /**
     * @param  array<string, string>  $errors
     */
    private static function checkKind(array &$errors, int $index, string $kind, bool $isLast): void
    {
        if (! in_array($kind, self::KINDS, true)) {
            $errors["tiers.{$index}.kind"] = 'El tipo de tramo no es válido.';

            return;
        }

        if ($kind === 'negotiation' && ! $isLast) {
            $errors["tiers.{$index}.kind"] = 'Solo el último tramo puede ser de negociación.';
        }
    }

    /**
     * @param  array<string, string>  $errors
     * @param  array{min: int, max: ?int}  $tier
     * @param  array{min: int, max: ?int}|null  $previous
     */
    private static function checkLimits(array &$errors, int $index, array $tier, bool $isLast, ?array $previous): void
    {
        if ($previous === null) {
            if ($tier['min'] !== 1) {
                $errors["tiers.{$index}.min"] = 'El primer tramo debe empezar en 1.';
            }
        } elseif ($previous['max'] !== null && $tier['min'] !== $previous['max'] + 1) {
            $errors["tiers.{$index}.min"] = 'El tramo debe empezar justo después del máximo del tramo anterior, sin huecos ni solapes.';
        }

        if ($isLast) {
            if ($tier['max'] !== null) {
                $errors["tiers.{$index}.max"] = 'El último tramo debe quedar abierto, sin máximo.';
            }

            return;
        }

        if ($tier['max'] === null) {
            $errors["tiers.{$index}.max"] = 'Solo el último tramo puede quedar sin máximo.';
        } elseif ($tier['max'] < $tier['min']) {
            $errors["tiers.{$index}.max"] = 'El máximo no puede ser menor que el mínimo.';
        }
    }
}
