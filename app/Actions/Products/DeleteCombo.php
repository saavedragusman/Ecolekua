<?php

namespace App\Actions\Products;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Exceptions\BusinessRuleViolation;
use App\Models\Combo;
use App\Models\User;
use App\Support\Products\ComboAudit;
use Illuminate\Support\Facades\DB;

/**
 * Deletes a combo that has no history (PRD-014, design Decision 17). Its components, the values they
 * admit and its registry row go with it through the foreign keys' cascade, which frees the code and
 * the name; the component products are untouched. The audit row keeps the full copy.
 */
class DeleteCombo
{
    public function __construct(private readonly RecordAuditEvent $audit) {}

    /**
     * @throws BusinessRuleViolation when the combo has history
     */
    public function handle(Combo $combo, User $actor): void
    {
        DB::transaction(function () use ($combo, $actor): void {
            $combo = Combo::query()->lockForUpdate()->findOrFail($combo->id);

            $this->ensureHasNoHistory($combo);

            $copy = ComboAudit::snapshot($combo);

            $combo->delete();

            $this->audit->handle(AuditAction::ComboDeleted, $actor, $combo, oldValues: $copy);
        });
    }

    /**
     * Blocks the delete when the combo has history (E-29). No condition exists in 003 because no
     * table references a combo yet. Specs 004 (quotations), 006 (orders) and 008 add their condition
     * here, throwing a `BusinessRuleViolation` that suggests deactivating, and bring their own E-29
     * test. Their tables must reference `combos.id` with `restrictOnDelete()`.
     */
    private function ensureHasNoHistory(Combo $combo): void
    {
        // Intentionally empty in 003.
    }
}
