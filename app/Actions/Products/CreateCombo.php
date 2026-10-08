<?php

namespace App\Actions\Products;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Enums\CatalogStatus;
use App\Models\CatalogCode;
use App\Models\Combo;
use App\Models\User;
use App\Support\Audit\AuditOrigin;
use App\Support\Products\ComboAudit;
use App\Support\Products\ComboComponents;
use App\Support\Products\ComboRules;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates a combo of the diaper line (PRD-010, design Decisions 4 and 15). Everything runs in one
 * transaction: the component products are locked, every component is checked against its product
 * (E-22, E-62), and the combo, its components and its code in the shared registry are written
 * together or not at all (E-08). The combo starts active. The unique indexes are the backstop of the
 * name and code rules: a concurrent duplicate becomes the same field error.
 */
class CreateCombo
{
    public function __construct(private readonly RecordAuditEvent $audit) {}

    /**
     * @param  array<string, mixed>  $data  validated input (see ComboRules::rules()): name, code, portal_visible, components
     * @param  array<string, mixed>  $auditContext
     *
     * @throws ValidationException when a component rule rejects the data
     */
    public function handle(array $data, User $actor, ?AuditOrigin $origin = null, array $auditContext = []): Combo
    {
        try {
            return DB::transaction(fn (): Combo => $this->create($data, $actor, $origin, $auditContext));
        } catch (UniqueConstraintViolationException $exception) {
            throw ComboRules::duplicateKeyError($exception);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $auditContext
     */
    private function create(array $data, User $actor, ?AuditOrigin $origin, array $auditContext): Combo
    {
        ComboComponents::lockProducts($data['components']);
        $components = ComboRules::validateComponents($data['components']);

        $combo = Combo::query()->create([
            'name' => $data['name'],
            'portal_visible' => filter_var($data['portal_visible'] ?? true, FILTER_VALIDATE_BOOLEAN),
            'status' => CatalogStatus::Active,
        ]);

        CatalogCode::query()->create(['code' => $data['code'], 'combo_id' => $combo->id]);
        ComboComponents::replace($combo, $components);

        $this->audit->handle(
            AuditAction::ComboCreated,
            $actor,
            $combo,
            newValues: ComboAudit::snapshot($combo),
            context: $auditContext,
            origin: $origin,
        );

        return $combo;
    }
}
