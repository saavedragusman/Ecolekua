<?php

namespace App\Actions\Products;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Enums\CatalogStatus;
use App\Models\Combo;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Deactivates a combo (PRD-013, design Decision 16). It locks the combo, changes only `status` and
 * audits it; an already inactive combo is a no-op with no audit row. Its code stays reserved in the
 * registry (DEC-PRD-01) and its components are untouched.
 */
class DeactivateCombo
{
    public function __construct(private readonly RecordAuditEvent $audit) {}

    public function handle(Combo $combo, User $actor): Combo
    {
        return DB::transaction(function () use ($combo, $actor): Combo {
            $combo = Combo::query()->lockForUpdate()->findOrFail($combo->id);

            if ($combo->status === CatalogStatus::Inactive) {
                return $combo;
            }

            $combo->forceFill(['status' => CatalogStatus::Inactive])->save();

            $this->audit->handle(
                AuditAction::ComboDeactivated,
                $actor,
                $combo,
                oldValues: ['status' => CatalogStatus::Active->value],
                newValues: ['status' => CatalogStatus::Inactive->value],
            );

            return $combo;
        });
    }
}
