<?php

namespace App\Enums;

use Illuminate\Support\Str;

/**
 * The 24 federal entities of Venezuela: 23 states plus the Distrito Capital (DEC-CLI-33, CLI-006).
 * The backed value is stored in `customer_addresses.state`; the city stays free text.
 */
enum VenezuelanState: string
{
    case Amazonas = 'amazonas';
    case Anzoategui = 'anzoategui';
    case Apure = 'apure';
    case Aragua = 'aragua';
    case Barinas = 'barinas';
    case Bolivar = 'bolivar';
    case Carabobo = 'carabobo';
    case Cojedes = 'cojedes';
    case DeltaAmacuro = 'delta_amacuro';
    case DistritoCapital = 'distrito_capital';
    case Falcon = 'falcon';
    case Guarico = 'guarico';
    case LaGuaira = 'la_guaira';
    case Lara = 'lara';
    case Merida = 'merida';
    case Miranda = 'miranda';
    case Monagas = 'monagas';
    case NuevaEsparta = 'nueva_esparta';
    case Portuguesa = 'portuguesa';
    case Sucre = 'sucre';
    case Tachira = 'tachira';
    case Trujillo = 'trujillo';
    case Yaracuy = 'yaracuy';
    case Zulia = 'zulia';

    public function label(): string
    {
        return match ($this) {
            self::Amazonas => 'Amazonas',
            self::Anzoategui => 'Anzoátegui',
            self::Apure => 'Apure',
            self::Aragua => 'Aragua',
            self::Barinas => 'Barinas',
            self::Bolivar => 'Bolívar',
            self::Carabobo => 'Carabobo',
            self::Cojedes => 'Cojedes',
            self::DeltaAmacuro => 'Delta Amacuro',
            self::DistritoCapital => 'Distrito Capital',
            self::Falcon => 'Falcón',
            self::Guarico => 'Guárico',
            self::LaGuaira => 'La Guaira',
            self::Lara => 'Lara',
            self::Merida => 'Mérida',
            self::Miranda => 'Miranda',
            self::Monagas => 'Monagas',
            self::NuevaEsparta => 'Nueva Esparta',
            self::Portuguesa => 'Portuguesa',
            self::Sucre => 'Sucre',
            self::Tachira => 'Táchira',
            self::Trujillo => 'Trujillo',
            self::Yaracuy => 'Yaracuy',
            self::Zulia => 'Zulia',
        };
    }

    /**
     * Finds the state whose label matches the text, ignoring case, accents and extra spaces
     * (used by the import to map the `direccion_estado` column, DEC-CLI-33).
     */
    public static function fromLabel(string $label): ?self
    {
        $wanted = self::fold($label);

        if ($wanted === '') {
            return null;
        }

        foreach (self::cases() as $case) {
            if (self::fold($case->label()) === $wanted) {
                return $case;
            }
        }

        return null;
    }

    private static function fold(string $text): string
    {
        return Str::lower(trim((string) preg_replace('/\s+/u', ' ', Str::ascii($text))));
    }
}
