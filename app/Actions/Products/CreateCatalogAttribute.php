<?php

namespace App\Actions\Products;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AttributePresentation;
use App\Enums\AttributeSpecialUse;
use App\Enums\AuditAction;
use App\Enums\CatalogStatus;
use App\Models\CatalogAttribute;
use App\Models\User;
use App\Support\Audit\AuditOrigin;
use App\Support\Products\CatalogRules;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates a catalog attribute (PRD-002, design Decision 9). The new row goes to the end of the
 * order and starts active. The unique indexes on `name`, `special_use` and the generated
 * `color_marker` (E-45, E-58, DEC-PRD-49) are the backstop of the request rules: a concurrent
 * duplicate becomes the same field error instead of a server error.
 */
class CreateCatalogAttribute
{
    public function __construct(private readonly RecordAuditEvent $audit) {}

    /**
     * @param  array<string, mixed>  $data  validated input (`name`, `presentation`, optional `special_use`)
     * @param  array<string, mixed>  $auditContext
     *
     * @throws ValidationException when a unique index rejects the attribute
     */
    public function handle(array $data, User $actor, ?AuditOrigin $origin = null, array $auditContext = []): CatalogAttribute
    {
        try {
            return DB::transaction(function () use ($data, $actor, $origin, $auditContext): CatalogAttribute {
                $attribute = CatalogAttribute::query()->create([
                    'name' => $data['name'],
                    'presentation' => AttributePresentation::from($data['presentation']),
                    'special_use' => isset($data['special_use']) ? AttributeSpecialUse::from($data['special_use']) : null,
                    'sort_order' => ((int) CatalogAttribute::query()->lockForUpdate()->max('sort_order')) + 1,
                    'status' => CatalogStatus::Active,
                ]);

                $this->audit->handle(
                    AuditAction::CatalogCreated,
                    $actor,
                    $attribute,
                    newValues: [
                        'name' => $attribute->name,
                        'presentation' => $attribute->presentation->value,
                        'special_use' => $attribute->special_use?->value,
                        'sort_order' => $attribute->sort_order,
                        'status' => $attribute->status->value,
                    ],
                    context: $auditContext,
                    origin: $origin,
                );

                return $attribute;
            });
        } catch (UniqueConstraintViolationException $exception) {
            throw ValidationException::withMessages(CatalogRules::attributeUniqueErrors($exception));
        }
    }
}
