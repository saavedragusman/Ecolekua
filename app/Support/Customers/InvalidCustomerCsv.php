<?php

namespace App\Support\Customers;

use RuntimeException;

/**
 * The import file cannot be used as a whole (path, encoding, header or row shape, DEC-CLI-20, E-39).
 * The reasons never contain cell values, only column names and line numbers.
 */
final class InvalidCustomerCsv extends RuntimeException
{
    /**
     * @param  list<string>  $problems  Spanish reasons shown to the operator
     */
    public function __construct(public readonly array $problems)
    {
        parent::__construct(implode(' ', $problems));
    }
}
