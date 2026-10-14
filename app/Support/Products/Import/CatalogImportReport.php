<?php

namespace App\Support\Products\Import;

/**
 * Outcome of `ImportCatalog` (design Decision 19). It carries file names, row numbers, CSV column
 * names and fixed reasons only, never cell values, so the console can print it as is.
 */
final readonly class CatalogImportReport
{
    /**
     * @param  list<string>  $problems  reasons that stop the run before any row is read (author, options, files)
     * @param  list<array{file: string, row: int, column: string, reason: string}>  $errors  row errors; any one blocks the import
     * @param  array<string, int>  $imported  rows created by kind (empty when the run did not import)
     */
    public function __construct(
        public array $problems = [],
        public array $errors = [],
        public array $imported = [],
    ) {}

    /**
     * @param  list<string>  $problems
     */
    public static function blocked(array $problems): self
    {
        return new self(problems: $problems);
    }

    public function hasBlockingFindings(): bool
    {
        return $this->problems !== [] || $this->errors !== [];
    }
}
