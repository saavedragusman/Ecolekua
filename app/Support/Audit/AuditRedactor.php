<?php

namespace App\Support\Audit;

/**
 * Deny-list redaction for audit payloads (design Decision 9): drops every key that contains
 * `password` or equals `remember_token`, case-insensitively, at any depth.
 */
final class AuditRedactor
{
    /**
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    public static function redact(array $values): array
    {
        $result = [];

        foreach ($values as $key => $value) {
            if (is_string($key) && self::isSensitive($key)) {
                continue;
            }

            $result[$key] = is_array($value) ? self::redact($value) : $value;
        }

        return $result;
    }

    private static function isSensitive(string $key): bool
    {
        $normalized = strtolower($key);

        return $normalized === 'remember_token' || str_contains($normalized, 'password');
    }
}
