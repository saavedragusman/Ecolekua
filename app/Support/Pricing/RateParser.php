<?php

namespace App\Support\Pricing;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;

/**
 * Reads the `promedio` of the exchange rate API (COT-030) without ever going through a float (DT-01,
 * design Decision 3). The body is decoded only to check its structure; the value comes from the raw JSON
 * number token, parsed with `BigDecimal` and rounded HALF_UP to 4 decimals.
 */
final class RateParser
{
    public const SCALE = 4;

    private const NUMBER_TOKEN = '/"promedio"\s*:\s*(-?(?:0|[1-9]\d*)(?:\.\d+)?(?:[eE][+-]?\d+)?)/';

    /**
     * The rate, or null when the body is not a JSON object with a positive number `promedio`.
     */
    public static function promedio(string $rawJsonBody): ?BigDecimal
    {
        $decoded = json_decode($rawJsonBody, true);

        if (! is_array($decoded) || array_is_list($decoded) || ! isset($decoded['promedio'])) {
            return null;
        }

        $promedio = $decoded['promedio'];

        if (! is_int($promedio) && ! is_float($promedio)) {
            return null;
        }

        if (preg_match_all(self::NUMBER_TOKEN, $rawJsonBody, $matches, PREG_OFFSET_CAPTURE) === false) {
            return null;
        }

        // Nested objects may carry a `promedio` too: the value is the token of the top-level key.
        foreach ($matches[1] as $index => [$token]) {
            if (self::depthAt($rawJsonBody, $matches[0][$index][1]) !== 1) {
                continue;
            }

            try {
                $value = BigDecimal::of($token)->toScale(self::SCALE, RoundingMode::HalfUp);
            } catch (MathException) {
                return null;
            }

            return $value->isPositive() ? $value : null;
        }

        return null;
    }

    /**
     * How many objects or arrays are open at a byte offset of the body, ignoring brackets inside strings.
     */
    private static function depthAt(string $body, int $offset): int
    {
        $depth = 0;
        $inString = false;

        for ($i = 0; $i < $offset; $i++) {
            $char = $body[$i];

            if ($inString) {
                if ($char === '\\') {
                    $i++;
                } elseif ($char === '"') {
                    $inString = false;
                }
            } elseif ($char === '"') {
                $inString = true;
            } elseif ($char === '{' || $char === '[') {
                $depth++;
            } elseif ($char === '}' || $char === ']') {
                $depth--;
            }
        }

        return $depth;
    }
}
