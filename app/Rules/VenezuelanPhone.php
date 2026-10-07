<?php

namespace App\Rules;

use App\Support\Customers\InvalidPhoneNumber;
use App\Support\Customers\PhoneNumber;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Accepts only Venezuelan phone numbers (DEC-CLI-25). The customer phone must be a mobile
 * (`allowLandline: false`, DEC-CLI-26); the contact person phone may be a landline (E-38).
 */
class VenezuelanPhone implements ValidationRule
{
    public function __construct(private readonly bool $allowLandline) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('validation.venezuelan_phone.format')->translate();

            return;
        }

        try {
            $phone = PhoneNumber::parse($value);
        } catch (InvalidPhoneNumber $exception) {
            $fail("validation.venezuelan_phone.{$exception->reason}")->translate();

            return;
        }

        if (! $this->allowLandline && ! $phone->isMobile()) {
            $fail('validation.venezuelan_phone.mobile_only')->translate();
        }
    }
}
