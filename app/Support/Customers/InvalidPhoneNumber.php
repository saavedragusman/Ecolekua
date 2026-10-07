<?php

namespace App\Support\Customers;

use RuntimeException;

/**
 * Thrown by PhoneNumber::parse(). The message never contains the submitted number (it may end up in
 * logs); callers translate the reason code into a user-facing validation message.
 */
final class InvalidPhoneNumber extends RuntimeException
{
    /** The number belongs to another country (DEC-CLI-25). */
    public const FOREIGN = 'foreign';

    /** The number is not a well-formed Venezuelan number. */
    public const FORMAT = 'format';

    private function __construct(public readonly string $reason)
    {
        parent::__construct("Invalid phone number: {$reason}.");
    }

    public static function foreign(): self
    {
        return new self(self::FOREIGN);
    }

    public static function format(): self
    {
        return new self(self::FORMAT);
    }
}
