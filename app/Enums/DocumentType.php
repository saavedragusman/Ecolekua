<?php

namespace App\Enums;

/**
 * Identification document types (DEC-CLI-02, CLI-003). The backed value is stored in
 * `customers.document_type`; cédula V and RIF V (and E) are different types on purpose.
 */
enum DocumentType: string
{
    case CedulaV = 'cedula_v';
    case CedulaE = 'cedula_e';
    case Passport = 'passport';
    case RifJ = 'rif_j';
    case RifG = 'rif_g';
    case RifV = 'rif_v';
    case RifE = 'rif_e';
    case RifP = 'rif_p';

    public function label(): string
    {
        return match ($this) {
            self::CedulaV => 'Cédula V',
            self::CedulaE => 'Cédula E',
            self::Passport => 'Pasaporte',
            self::RifJ => 'RIF J',
            self::RifG => 'RIF G',
            self::RifV => 'RIF V',
            self::RifE => 'RIF E',
            self::RifP => 'RIF P',
        };
    }

    /**
     * Document types a customer of the given type may use (CLI-003).
     *
     * @return list<self>
     */
    public static function allowedFor(CustomerType $type): array
    {
        return match ($type) {
            CustomerType::Natural => [self::CedulaV, self::CedulaE, self::Passport],
            CustomerType::Company => [self::RifJ, self::RifG, self::RifV, self::RifE, self::RifP],
        };
    }

    /**
     * Prefix letter of cédula and RIF numbers; passports have none.
     */
    public function letter(): ?string
    {
        return match ($this) {
            self::CedulaV, self::RifV => 'V',
            self::CedulaE, self::RifE => 'E',
            self::RifJ => 'J',
            self::RifG => 'G',
            self::RifP => 'P',
            self::Passport => null,
        };
    }

    public function isRif(): bool
    {
        return match ($this) {
            self::RifJ, self::RifG, self::RifV, self::RifE, self::RifP => true,
            default => false,
        };
    }
}
