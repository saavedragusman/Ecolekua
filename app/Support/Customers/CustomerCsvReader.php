<?php

namespace App\Support\Customers;

use SplFileObject;

/**
 * Reads the one-time customer import file (design Decision 14). The whole file is read at once
 * (a few thousand rows at most), a UTF-8 byte order mark is dropped and the text must be valid
 * UTF-8. The delimiter (`,` or `;`) is detected from the header, the header must contain exactly
 * the expected columns (any order, any case) and quoted cells may contain delimiters and line
 * breaks. Rows carry their physical line number (header = 1) for the report.
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

    private const DELIMITERS = [',', ';'];

    private const BOM = "\xEF\xBB\xBF";

    /**
     * @return non-empty-list<array{line: int, cells: array<string, string>}> non-blank data rows
     *
     * @throws InvalidCustomerCsv
     */
    public function read(string $path): array
    {
        $content = $this->contents($path);

        $file = new SplFileObject('php://memory', 'w+');
        $file->fwrite($content);
        $file->rewind();
        $file->setCsvControl($this->detectDelimiter($content), '"', '');

        $line = 1;
        $header = $this->nextRecord($file, $content, $line);
        $columns = $this->validatedColumns($header ?? []);

        $rows = [];
        $irregular = [];

        while (! $file->eof()) {
            $startLine = $line;
            $record = $this->nextRecord($file, $content, $line);

            if ($record === null) {
                continue;
            }

            if (count($record) !== count($columns)) {
                $irregular[] = $startLine;

                continue;
            }

            $rows[] = [
                'line' => $startLine,
                'cells' => array_combine($columns, array_map(trim(...), $record)),
            ];
        }

        if ($irregular !== []) {
            throw new InvalidCustomerCsv(['Filas con distinto número de columnas que el encabezado: '.implode(', ', $irregular).'.']);
        }

        if ($rows === []) {
            throw new InvalidCustomerCsv(['El archivo no contiene filas para importar.']);
        }

        return $rows;
    }

    /**
     * Whole file as valid UTF-8 text without a byte order mark.
     *
     * @throws InvalidCustomerCsv
     */
    private function contents(string $path): string
    {
        $content = is_file($path) && is_readable($path) ? file_get_contents($path) : false;

        if ($content === false) {
            throw new InvalidCustomerCsv(['No se pudo leer el archivo: la ruta no existe o no se puede leer.']);
        }

        if (str_starts_with($content, self::BOM)) {
            $content = substr($content, strlen(self::BOM));
        }

        if (! mb_check_encoding($content, 'UTF-8')) {
            throw new InvalidCustomerCsv(['El archivo no está codificado en UTF-8.']);
        }

        if (trim($content) === '') {
            throw new InvalidCustomerCsv(['El archivo está vacío.']);
        }

        return $content;
    }

    /**
     * The delimiter that splits the header line into the most expected columns; `,` on a tie.
     */
    private function detectDelimiter(string $content): string
    {
        $headerLine = rtrim((string) strtok($content, "\n"), "\r");
        $best = self::DELIMITERS[0];
        $bestScore = -1;

        foreach (self::DELIMITERS as $delimiter) {
            $matches = array_intersect($this->normalizedColumns(str_getcsv($headerLine, $delimiter, '"', '')), self::COLUMNS);

            if (count($matches) > $bestScore) {
                $best = $delimiter;
                $bestScore = count($matches);
            }
        }

        return $best;
    }

    /**
     * Next non-blank record, or `null` for a blank line and at the end of the file. `$line` is
     * advanced by the physical lines the record spans (quoted cells may contain line breaks).
     *
     * @return list<string>|null
     */
    private function nextRecord(SplFileObject $file, string $content, int &$line): ?array
    {
        $start = $file->ftell();
        $record = $file->fgetcsv();
        $end = $file->ftell();

        if ($start !== false && $end !== false) {
            $line += substr_count($content, "\n", $start, $end - $start);
        }

        if (! is_array($record)) {
            return null;
        }

        $cells = array_map(fn (mixed $cell): string => (string) $cell, $record);

        return array_filter($cells, fn (string $cell): bool => trim($cell) !== '') === [] ? null : $cells;
    }

    /**
     * @param  list<string>  $header
     * @return list<string> the normalized header columns
     *
     * @throws InvalidCustomerCsv
     */
    private function validatedColumns(array $header): array
    {
        $columns = $this->normalizedColumns($header);
        $problems = [];

        $missing = array_values(array_diff(self::COLUMNS, $columns));
        $unknown = array_values(array_unique(array_diff($columns, self::COLUMNS)));
        $repeated = array_keys(array_filter(array_count_values($columns), fn (int $count): bool => $count > 1));

        if ($missing !== []) {
            $problems[] = 'Faltan las columnas: '.implode(', ', $missing).'.';
        }

        if ($unknown !== []) {
            $problems[] = 'Columnas no reconocidas: '.implode(', ', $unknown).'.';
        }

        if ($repeated !== []) {
            $problems[] = 'Columnas repetidas: '.implode(', ', $repeated).'.';
        }

        if ($problems !== []) {
            throw new InvalidCustomerCsv($problems);
        }

        return $columns;
    }

    /**
     * @param  array<int, string|null>  $cells
     * @return list<string>
     */
    private function normalizedColumns(array $cells): array
    {
        return array_values(array_map(fn (?string $cell): string => mb_strtolower(trim((string) $cell)), $cells));
    }
}
