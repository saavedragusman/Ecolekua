<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

/**
 * FND-013: when changing their password, a user cannot reuse the current one.
 */
class NotCurrentPassword implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $user = Auth::user();

        if ($user !== null && is_string($value) && Hash::check($value, $user->password)) {
            $fail('validation.not_current_password')->translate();
        }
    }
}
