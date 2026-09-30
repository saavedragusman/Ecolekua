<?php

namespace App\Actions\Auth;

/**
 * Outcome of AttemptLogin. The action never throws from inside its transaction, so failure
 * audit rows are committed before the controller turns a rejection into a validation error.
 */
final readonly class LoginResult
{
    private function __construct(
        public string $status,
        public ?int $lockedMinutes = null,
    ) {}

    public static function succeeded(): self
    {
        return new self('succeeded');
    }

    public static function failed(): self
    {
        return new self('failed');
    }

    public static function locked(int $minutes): self
    {
        return new self('locked', $minutes);
    }

    public function isSuccess(): bool
    {
        return $this->status === 'succeeded';
    }

    /**
     * Message for the `email` field of a rejected attempt.
     */
    public function message(): string
    {
        return $this->status === 'locked'
            ? trans_choice('auth.locked', $this->lockedMinutes, ['minutes' => $this->lockedMinutes])
            : __('auth.failed');
    }
}
