<?php

namespace App\Support\Pricing;

/**
 * Pure validation of the size groups proposed for a combination (COT-004, E-08, design Decision 10):
 * the groups must partition the sizes the combination admits, so no size is in two groups and none is
 * left without one. An empty list is valid: it removes the grouping. The Actions run it before
 * computing the plan, so the preview and the save apply exactly the same rules.
 *
 * Errors are keyed `groups` for the set (a repeated size, a size left out) and `groups.{i}` for one
 * group (empty, or holding a size the combination does not admit), the keys the validation bag reads.
 */
final class SizeGroupRules
{
    /**
     * @param  list<list<int>>  $groups  proposed groups, each a list of size value ids
     * @param  array<int, string>  $admitted  size value id => name of the sizes the combination admits, in size order
     * @return array<string, string> field => message (empty when valid)
     */
    public static function validate(array $groups, array $admitted): array
    {
        if ($groups === []) {
            return [];
        }

        $errors = [];
        $seen = [];
        $repeated = [];

        foreach ($groups as $index => $sizeIds) {
            if ($sizeIds === []) {
                $errors["groups.{$index}"] = 'El grupo está vacío.';

                continue;
            }

            foreach ($sizeIds as $sizeId) {
                if (! isset($admitted[$sizeId])) {
                    $errors["groups.{$index}"] = 'Una talla del grupo no pertenece a la combinación.';

                    continue;
                }

                if (isset($seen[$sizeId])) {
                    $repeated[$sizeId] = true;
                }

                $seen[$sizeId] = true;
            }
        }

        $messages = [];

        if ($repeated !== []) {
            $messages[] = self::sentence($repeated, $admitted, 'está en más de un grupo', 'están en más de un grupo');
        }

        $missing = array_diff_key($admitted, $seen);

        if ($missing !== []) {
            $messages[] = self::sentence($missing, $admitted, 'está sin grupo', 'están sin grupo');
        }

        if ($messages !== []) {
            $errors['groups'] = implode(' ', $messages);
        }

        return $errors;
    }

    /**
     * "La talla 36 está …" or "Las tallas 36, 38 están …", the names in the order of the admitted sizes.
     *
     * @param  array<int, mixed>  $sizeIds  keys are the size ids
     * @param  array<int, string>  $admitted
     */
    private static function sentence(array $sizeIds, array $admitted, string $singular, string $plural): string
    {
        $names = implode(', ', array_intersect_key($admitted, $sizeIds));

        return count($sizeIds) === 1 ? "La talla {$names} {$singular}." : "Las tallas {$names} {$plural}.";
    }
}
