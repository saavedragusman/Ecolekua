<?php

namespace App\Support\Csv;

use RuntimeException;

/**
 * A CSV file cannot be used as a whole (path, encoding, header or row shape; design Decision 19).
 * The reasons never contain cell values, only column names and line numbers.
 */
final class InvalidCsv extends RuntimeException
{
    /**
     * @param  list<string>  $problems  Spanish reasons shown to the operator
     */
    public function __construct(public readonly array $problems)
    {
        parent::__construct(implode(' ', $problems));
    }
}
