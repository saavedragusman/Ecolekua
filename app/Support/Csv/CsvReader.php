<?php

namespace App\Support\Csv;

use SplFileObject;

/**
 * Reads a one-time import file (design Decision 19), extracted from the 002 customer reader. The
 * whole file is read at once (a few thousand rows at most), a UTF-8 byte order mark is dropped and
 * the text must be valid UTF-8. The delimiter (`,` or `;`) is detected from the header, the header
 * must contain exactly the expected columns (any order, any case) and quoted cells may contain
 * delimiters and line breaks. Rows carry their physical line number (header = 1) for the report.
 *
 * It parses only; every business rule belongs to the caller.
 */
final class CsvReader
{
    private const DELIMITERS = [',', ';'];

    private const BOM = "\xEF\xBB\xBF";

    /**
     * @param  list<string>  $columns  expected header columns, in the order used to list the missing ones
     * @param  bool  $allowEmpty  a file with only a header reads as no rows instead of failing
     * @return list<array{line: int, cells: array<string, string>}> non-blank data rows
     *
     * @throws InvalidCsv
     */
    public function read(string $path, array $columns, bool $allowEmpty = false): array
    {
        $content = $this->contents($path);

        $file = new SplFileObject('php://memory', 'w+');
        $file->fwrite($content);
        $file->rewind();
        $file->setCsvControl($this->detectDelimiter($content, $columns), '"', '');

        $line = 1;
        $header = $this->nextRecord($file, $content, $line);
        $headerColumns = $this->validatedColumns($header ?? [], $columns);

        $rows = [];
        $irregular = [];

        while (! $file->eof()) {
            $startLine = $line;
            $record = $this->nextRecord($file, $content, $line);

            if ($record === null) {
                continue;
            }

            if (count($record) !== count($headerColumns)) {
                $irregular[] = $startLine;

                continue;
            }

            $rows[] = [
                'line' => $startLine,
                'cells' => array_combine($headerColumns, array_map(trim(...), $record)),
            ];
        }

        if ($irregular !== []) {
            throw new InvalidCsv(['Filas con distinto número de columnas que el encabezado: '.implode(', ', $irregular).'.']);
        }

        if ($rows === [] && ! $allowEmpty) {
            throw new InvalidCsv(['El archivo no contiene filas para importar.']);
        }

        return $rows;
    }

    /**
     * Whole file as valid UTF-8 text without a byte order mark.
     *
     * @throws InvalidCsv
     */
    private function contents(string $path): string
    {
        $content = is_file($path) && is_readable($path) ? file_get_contents($path) : false;

        if ($content === false) {
            throw new InvalidCsv(['No se pudo leer el archivo: la ruta no existe o no se puede leer.']);
        }

        if (str_starts_with($content, self::BOM)) {
            $content = substr($content, strlen(self::BOM));
        }

        if (! mb_check_encoding($content, 'UTF-8')) {
            throw new InvalidCsv(['El archivo no está codificado en UTF-8.']);
        }

        if (trim($content) === '') {
            throw new InvalidCsv(['El archivo está vacío.']);
        }

        return $content;
    }

    /**
     * The delimiter that splits the header line into the most expected columns; `,` on a tie.
     *
     * @param  list<string>  $expected
     */
    private function detectDelimiter(string $content, array $expected): string
    {
        $headerLine = rtrim((string) strtok($content, "\n"), "\r");
        $best = self::DELIMITERS[0];
        $bestScore = -1;

        foreach (self::DELIMITERS as $delimiter) {
            $matches = array_intersect($this->normalizedColumns(str_getcsv($headerLine, $delimiter, '"', '')), $expected);

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
     * @param  list<string>  $expected
     * @return list<string> the normalized header columns
     *
     * @throws InvalidCsv
     */
    private function validatedColumns(array $header, array $expected): array
    {
        $columns = $this->normalizedColumns($header);
        $problems = [];

        $missing = array_values(array_diff($expected, $columns));
        $unknown = array_values(array_unique(array_diff($columns, $expected)));
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
            throw new InvalidCsv($problems);
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
