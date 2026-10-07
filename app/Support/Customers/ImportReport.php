<?php

namespace App\Support\Customers;

/**
 * Outcome of `ImportCustomers` (design Decision 14). It carries row numbers, CSV column names and
 * reasons only, never cell values, so the console can print it as is.
 */
final readonly class ImportReport
{
    /**
     * @param  list<string>  $problems  reasons that stop the run before any row is read (author, file)
     * @param  list<array{row: int, column: string, reason: string}>  $errors  row errors; any one blocks the import
     * @param  list<array{row: int, reason: string}>  $warnings  repeated phones; block unless confirmed
     * @param  int  $imported  customers created (0 when the run did not import)
     */
    public function __construct(
        public array $problems = [],
        public array $errors = [],
        public array $warnings = [],
        public int $imported = 0,
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
