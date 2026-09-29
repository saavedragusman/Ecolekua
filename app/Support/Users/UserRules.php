<?php

namespace App\Support\Users;

use App\Models\User;
use Closure;
use Illuminate\Support\Str;

/**
 * Identity validation shared by StoreUserRequest, UpdateUserRequest and the first-administrator
 * console command (FND-007, FND-008). The email is compared after the same normalization the
 * User model applies when storing it, so ` ANA@x.com ` collides with `ana@x.com`.
 */
final class UserRules
{
    public const NAME_MAX_LENGTH = 100;

    public const EMAIL_MAX_LENGTH = 255;

    /**
     * @return array<string, list<mixed>>
     */
    public static function identity(?User $ignoring = null): array
    {
        return [
            'first_name' => ['required', 'string', 'max:'.self::NAME_MAX_LENGTH],
            'last_name' => ['required', 'string', 'max:'.self::NAME_MAX_LENGTH],
            'email' => [
                'required',
                'string',
                'email',
                'max:'.self::EMAIL_MAX_LENGTH,
                self::uniqueAfterNormalization($ignoring),
            ],
        ];
    }

    public static function normalizeEmail(string $email): string
    {
        return Str::lower(trim($email));
    }

    /**
     * Uniqueness covers active and inactive users alike (FND-008).
     */
    private static function uniqueAfterNormalization(?User $ignoring): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($ignoring): void {
            if (! is_string($value)) {
                return;
            }

            $taken = User::query()
                ->where('email', self::normalizeEmail($value))
                ->when($ignoring !== null, fn ($query) => $query->whereKeyNot($ignoring->getKey()))
                ->exists();

            if ($taken) {
                $fail('validation.unique')->translate();
            }
        };
    }
}
