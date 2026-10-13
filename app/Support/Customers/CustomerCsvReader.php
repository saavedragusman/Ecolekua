<?php

namespace App\Support\Customers;

use App\Support\Csv\CsvReader;
use App\Support\Csv\InvalidCsv;

/**
 * Reads the one-time customer import file (design Decision 14). The parsing (byte order mark,
 * UTF-8 check, `,` or `;` detection, exact header in any order and case, multiline cells) lives in
 * the generic `CsvReader` (002 Decision 14 extracted by 003 Decision 19); this class only fixes the
 * customer columns and keeps its public API and `InvalidCustomerCsv`. Rows carry their physical
 * line number (header = 1) for the report.
 *
 * It parses only; every business rule belongs to `ImportCustomers` and `CustomerRules`.
 */
final class CustomerCsvReader
{
    /** Expected header columns, in the order used to list the missing ones. */
    public const COLUMNS = [
        'tipo', 'nombre', 'tipo_documento', 'numero_documento', 'telefono', 'correo',
        'cumpleanos_dia', 'cumpleanos_mes', 'aniversario_dia', 'aniversario_mes', 'observaciones',
        'contacto_nombre', 'contacto_cargo', 'contacto_telefono', 'contacto_correo',
        'direccion', 'direccion_ciudad', 'direccion_estado', 'direccion_referencia',
        'correo_asesora',
    ];

    /**
     * @return non-empty-list<array{line: int, cells: array<string, string>}> non-blank data rows
     *
     * @throws InvalidCustomerCsv
     */
    public function read(string $path): array
    {
        try {
            /** @var non-empty-list<array{line: int, cells: array<string, string>}> */
            return (new CsvReader)->read($path, self::COLUMNS);
        } catch (InvalidCsv $invalid) {
            throw new InvalidCustomerCsv($invalid->problems);
        }
    }
}
