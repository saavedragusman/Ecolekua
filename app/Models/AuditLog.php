<?php

namespace App\Models;

use App\Exceptions\AuditLogIsImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    /** Audit rows are never updated, so there is no updated_at column. */
    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $guarded = [];

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw AuditLogIsImmutable::forOperation('update');
        });

        static::deleting(function (): never {
            throw AuditLogIsImmutable::forOperation('delete');
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'old_values' => 'array',
            'new_values' => 'array',
            'context' => 'array',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
