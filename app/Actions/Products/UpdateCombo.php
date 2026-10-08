<?php

namespace App\Actions\Products;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Models\CatalogCode;
use App\Models\Combo;
use App\Models\User;
use App\Support\Products\ComboAudit;
use App\Support\Products\ComboComponents;
use App\Support\Products\ComboRules;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Edits a combo (PRD-012, design Decision 15) with the same rules as the creation: name, code,
 * portal visibility and the components, which replace the stored ones when they differ. Status has
 * its own Actions. The audit row carries only the fields that changed and nothing is written when
 * nothing changed (E-68, as `UpdateCombination`).
 */
class UpdateCombo
{
    public function __construct(private readonly RecordAuditEvent $audit) {}

    /**
     * @param  array<string, mixed>  $data  validated input (see ComboRules::rules()); `portal_visible` keeps its value when not sent
     *
     * @throws ValidationException when a combo rule rejects the data
     */
    public function handle(Combo $combo, array $data, User $actor): Combo
    {
        try {
            return DB::transaction(fn (): Combo => $this->update($combo, $data, $actor));
        } catch (UniqueConstraintViolationException $exception) {
            throw ComboRules::duplicateKeyError($exception);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function update(Combo $combo, array $data, User $actor): Combo
    {
        $combo = Combo::query()->lockForUpdate()->findOrFail($combo->id);
        ComboComponents::lockProducts($data['components']);

        $before = ComboAudit::snapshot($combo);
        // Components already in the combo keep their product even if it was deactivated (DEC-PRD-64).
        $held = array_values($combo->components()->pluck('product_id')->map(fn (mixed $id): int => (int) $id)->all());
        $components = ComboRules::validateComponents($data['components'], $held);

        $combo->fill([
            'name' => $data['name'],
            'portal_visible' => isset($data['portal_visible']) ? filter_var($data['portal_visible'], FILTER_VALIDATE_BOOLEAN) : $combo->portal_visible,
        ]);

        if ($combo->isDirty()) {
            $combo->save();
        }

        $registry = CatalogCode::query()->where('combo_id', $combo->id)->firstOrFail();

        if ($registry->code !== $data['code']) {
            $registry->forceFill(['code' => $data['code']])->save();
        }

        if (! ComboComponents::matches($combo, $components)) {
            ComboComponents::replace($combo, $components);
        }

        $after = ComboAudit::snapshot($combo);
        $changed = array_keys(array_filter($after, fn (mixed $value, string $field): bool => $value !== $before[$field], ARRAY_FILTER_USE_BOTH));

        if ($changed !== []) {
            $combo->touch();

            $this->audit->handle(
                AuditAction::ComboUpdated,
                $actor,
                $combo,
                oldValues: array_intersect_key($before, array_flip($changed)),
                newValues: array_intersect_key($after, array_flip($changed)),
            );
        }

        return $combo;
    }
}
