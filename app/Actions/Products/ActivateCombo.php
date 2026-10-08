<?php

namespace App\Actions\Products;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Enums\CatalogStatus;
use App\Models\Combo;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Reactivates a combo (PRD-013, design Decision 16). It locks the combo, changes only `status` and
 * audits it; an already active combo is a no-op with no audit row. Nothing else is cascaded:
 * whether the combo is offered is computed from its components (PRD-010).
 */
class ActivateCombo
{
    public function __construct(private readonly RecordAuditEvent $audit) {}

    public function handle(Combo $combo, User $actor): Combo
    {
        return DB::transaction(function () use ($combo, $actor): Combo {
            $combo = Combo::query()->lockForUpdate()->findOrFail($combo->id);

            if ($combo->status === CatalogStatus::Active) {
                return $combo;
            }

            $combo->forceFill(['status' => CatalogStatus::Active])->save();

            $this->audit->handle(
                AuditAction::ComboActivated,
                $actor,
                $combo,
                oldValues: ['status' => CatalogStatus::Inactive->value],
                newValues: ['status' => CatalogStatus::Active->value],
            );

            return $combo;
        });
    }
}
