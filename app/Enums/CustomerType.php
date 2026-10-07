<?php

namespace App\Enums;

/**
 * Customer type (DEC-CLI-01, CLI-002). The backed value is stored in `customers.type`.
 */
enum CustomerType: string
{
    case Natural = 'natural';
    case Company = 'company';

    public function label(): string
    {
        return match ($this) {
            self::Natural => 'Persona natural',
            self::Company => 'Empresa',
        };
    }
}
