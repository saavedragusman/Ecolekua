<?php

namespace App\Actions\Pricing;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Enums\CatalogStatus;
use App\Models\PriceScheme;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Reactivates a price scheme (COT-001, design Decision 8). It has no precondition. An already active
 * scheme is a no-op with no audit row (same rule as `ActivateProduct`).
 */
class ActivatePriceScheme
{
    public function __construct(private readonly RecordAuditEvent $audit) {}

    public function handle(PriceScheme $scheme, User $actor): PriceScheme
    {
        return DB::transaction(function () use ($scheme, $actor): PriceScheme {
            $scheme = PriceScheme::query()->lockForUpdate()->findOrFail($scheme->id);

            if ($scheme->status === CatalogStatus::Active) {
                return $scheme;
            }

            $scheme->forceFill(['status' => CatalogStatus::Active])->save();

            $this->audit->handle(
                AuditAction::PriceSchemeActivated,
                $actor,
                $scheme,
                oldValues: ['status' => CatalogStatus::Inactive->value],
                newValues: ['status' => CatalogStatus::Active->value],
            );

            return $scheme;
        });
    }
}
