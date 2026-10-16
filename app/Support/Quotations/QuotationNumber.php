<?php

namespace App\Support\Quotations;

use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * The next quotation number (spec §12, design Decision 19): the single `quotation_sequences` row is locked,
 * incremented and read inside the caller's transaction, so two concurrent creations never get the same number
 * and a discarded draft never gives its number back (DEC-COT-45). `quotations.number` unique is the backstop.
 */
final class QuotationNumber
{
    private const SEQUENCE_ID = 1;

    /**
     * @throws LogicException when called outside a transaction (the lock would be released at once)
     */
    public static function next(): int
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('QuotationNumber::next() must run inside the transaction that creates the quotation.');
        }

        $last = (int) DB::table('quotation_sequences')->where('id', self::SEQUENCE_ID)->lockForUpdate()->value('last_number');
        $next = $last + 1;

        DB::table('quotation_sequences')->where('id', self::SEQUENCE_ID)->update(['last_number' => $next]);

        return $next;
    }
}
