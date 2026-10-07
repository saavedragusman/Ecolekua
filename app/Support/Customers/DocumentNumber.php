<?php

namespace App\Support\Customers;

use App\Enums\DocumentType;

/**
 * Canonical form, format check and display of customer identification documents (CLI-003,
 * DEC-CLI-30). The stored form includes the type letter and no separators (`V12345678`,
 * `J123456784`); passports are stored uppercased without separators.
 */
final class DocumentNumber
{
    private const SEPARATORS = [' ', '.', '-'];

    /** SENIAT letter values for the check digit. */
    private const RIF_LETTER_VALUES = ['V' => 1, 'E' => 2, 'J' => 3, 'P' => 4, 'G' => 5];

    private const RIF_LETTER_WEIGHT = 4;

    /** Weights applied to the eight digits of a RIF, in order. */
    private const RIF_DIGIT_WEIGHTS = [3, 2, 7, 6, 5, 4, 3, 2];

    private const RIF_MODULUS = 11;

    /**
     * Removes spaces, dots and hyphens and uppercases. For cédula and RIF a number typed without
     * its letter gets the type's letter; a wrong letter is kept so isValid() rejects it.
     */
    public static function normalize(DocumentType $type, string $raw): string
    {
        $normalized = mb_strtoupper(str_replace(self::SEPARATORS, '', trim($raw)));
        $letter = $type->letter();

        if ($letter !== null && $normalized !== '' && ctype_digit($normalized[0])) {
            return $letter.$normalized;
        }

        return $normalized;
    }

    /**
     * Format of an already normalized document, including the SENIAT check digit for RIFs.
     */
    public static function isValid(DocumentType $type, string $normalized): bool
    {
        $letter = $type->letter();

        if ($letter === null) {
            return preg_match('/^[A-Z0-9]{5,20}$/', $normalized) === 1;
        }

        if ($type->isRif()) {
            if (preg_match('/^'.$letter.'(\d{8})(\d)$/', $normalized, $parts) !== 1) {
                return false;
            }

            return self::rifCheckDigit($letter, $parts[1]) === (int) $parts[2];
        }

        return preg_match('/^'.$letter.'\d{6,9}$/', $normalized) === 1;
    }

    /**
     * SENIAT check digit: letter value × 4 plus the eight digits weighted 3, 2, 7, 6, 5, 4, 3, 2;
     * `11 − (sum mod 11)`, and a result of 10 or more becomes 0.
     */
    public static function rifCheckDigit(string $letter, string $eightDigits): int
    {
        $sum = self::RIF_LETTER_VALUES[$letter] * self::RIF_LETTER_WEIGHT;

        foreach (str_split($eightDigits) as $position => $digit) {
            $sum += (int) $digit * self::RIF_DIGIT_WEIGHTS[$position];
        }

        $check = self::RIF_MODULUS - ($sum % self::RIF_MODULUS);

        return $check >= 10 ? 0 : $check;
    }

    /**
     * Readable form: `V-12345678`, `J-12345678-4`, passports unchanged.
     */
    public static function display(DocumentType $type, string $normalized): string
    {
        if ($type->letter() === null) {
            return $normalized;
        }

        if ($type->isRif()) {
            return substr($normalized, 0, 1).'-'.substr($normalized, 1, 8).'-'.substr($normalized, 9);
        }

        return substr($normalized, 0, 1).'-'.substr($normalized, 1);
    }
}
