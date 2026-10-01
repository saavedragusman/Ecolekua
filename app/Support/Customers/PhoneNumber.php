<?php

namespace App\Support\Customers;

/**
 * A Venezuelan phone number stored as E.164 (`+58` + 10-digit national significant number, DT-01).
 *
 * Only Venezuelan numbers are accepted (DEC-CLI-25). Mobile and landline are told apart by the
 * spec's literal prefix rule: national number starting with `4` (04xx) or `2` (02xx); no carrier
 * or area-code list is kept. The display string is produced here so the frontend never reformats.
 */
final readonly class PhoneNumber
{
    private const COUNTRY_CODE = '58';

    private const NATIONAL_LENGTH = 10;

    private const SEPARATORS = [' ', '.', '-', '(', ')', '/'];

    private const MIN_SEARCH_DIGITS = 3;

    private function __construct(private string $national) {}

    /**
     * @throws InvalidPhoneNumber with reason `foreign` or `format`
     */
    public static function parse(string $raw): self
    {
        $cleaned = str_replace(self::SEPARATORS, '', trim($raw));

        if (str_starts_with($cleaned, '+') || str_starts_with($cleaned, '00')) {
            $national = self::nationalFromInternational($cleaned);
        } else {
            $national = self::nationalFromLocal($cleaned);
        }

        if (strlen($national) !== self::NATIONAL_LENGTH || ! in_array($national[0], ['2', '4'], true)) {
            throw InvalidPhoneNumber::format();
        }

        return new self($national);
    }

    public function e164(): string
    {
        return '+'.self::COUNTRY_CODE.$this->national;
    }

    public function isMobile(): bool
    {
        return $this->national[0] === '4';
    }

    public function isLandline(): bool
    {
        return $this->national[0] === '2';
    }

    /**
     * Local readable form, e.g. `0414-123-4567` or `0212-555-1234`.
     */
    public function display(): string
    {
        return '0'.substr($this->national, 0, 3).'-'.substr($this->national, 3, 3).'-'.substr($this->national, 6);
    }

    /**
     * Digits to match with `LIKE %…%` against stored E.164 numbers (design.md Decision 13), or
     * null when the term is not a phone fragment: text, or fewer than 3 digits once the leading
     * `+58`, `0058` or trunk `0` is removed.
     */
    public static function searchFragment(string $term): ?string
    {
        $cleaned = str_replace(self::SEPARATORS, '', trim($term));

        if (preg_match('/^\+?\d+$/', $cleaned) !== 1) {
            return null;
        }

        $digits = match (true) {
            str_starts_with($cleaned, '+58') => substr($cleaned, 3),
            str_starts_with($cleaned, '0058') => substr($cleaned, 4),
            str_starts_with($cleaned, '0') => substr($cleaned, 1),
            default => ltrim($cleaned, '+'),
        };

        return strlen($digits) >= self::MIN_SEARCH_DIGITS ? $digits : null;
    }

    /**
     * Input starting with `+` or `00`: the country code must be 58 and the rest is the national number.
     */
    private static function nationalFromInternational(string $cleaned): string
    {
        $rest = str_starts_with($cleaned, '+') ? substr($cleaned, 1) : substr($cleaned, 2);

        if (preg_match('/^\d+$/', $rest) !== 1) {
            throw InvalidPhoneNumber::format();
        }

        if (! str_starts_with($rest, self::COUNTRY_CODE)) {
            throw InvalidPhoneNumber::foreign();
        }

        return substr($rest, strlen(self::COUNTRY_CODE));
    }

    /**
     * Digits-only input: trunk `0` + 10 digits, 10 digits, or `58` + 10 digits.
     */
    private static function nationalFromLocal(string $cleaned): string
    {
        if (preg_match('/^\d+$/', $cleaned) !== 1) {
            throw InvalidPhoneNumber::format();
        }

        return match (strlen($cleaned)) {
            self::NATIONAL_LENGTH + 1 => $cleaned[0] === '0' ? substr($cleaned, 1) : throw InvalidPhoneNumber::format(),
            self::NATIONAL_LENGTH => $cleaned,
            self::NATIONAL_LENGTH + 2 => str_starts_with($cleaned, self::COUNTRY_CODE)
                ? substr($cleaned, 2)
                : throw InvalidPhoneNumber::format(),
            default => throw InvalidPhoneNumber::format(),
        };
    }
}
