<?php

namespace App\Actions\Products;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Enums\CatalogStatus;
use App\Models\AttributeValue;
use App\Models\CatalogAttribute;
use App\Models\User;
use App\Support\Audit\AuditOrigin;
use App\Support\Products\CatalogRules;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates a value of a catalog attribute (PRD-002, E-36). The new row goes to the end of the order
 * of its own attribute and starts active; the tone is stored in uppercase. The case-insensitive
 * unique index on `(catalog_attribute_id, name)` is the backstop of the request rule.
 */
class CreateAttributeValue
{
    public function __construct(private readonly RecordAuditEvent $audit) {}

    /**
     * @param  array<string, mixed>  $data  validated input (`name`, optional `description`, `tone`, `svg_layer`)
     * @param  array<string, mixed>  $auditContext
     *
     * @throws ValidationException when the name is taken in the attribute by a concurrent request
     */
    public function handle(CatalogAttribute $attribute, array $data, User $actor, ?AuditOrigin $origin = null, array $auditContext = []): AttributeValue
    {
        try {
            return DB::transaction(function () use ($attribute, $data, $actor, $origin, $auditContext): AttributeValue {
                $value = AttributeValue::query()->create([
                    'catalog_attribute_id' => $attribute->id,
                    'name' => $data['name'],
                    'description' => $data['description'] ?? null,
                    'tone' => CatalogRules::normalizeTone($data['tone'] ?? null),
                    'svg_layer' => $data['svg_layer'] ?? null,
                    'sort_order' => ((int) AttributeValue::query()->where('catalog_attribute_id', $attribute->id)->lockForUpdate()->max('sort_order')) + 1,
                    'status' => CatalogStatus::Active,
                ]);

                $this->ensureNoProductMissesLayer($value);

                $this->audit->handle(
                    AuditAction::CatalogCreated,
                    $actor,
                    $value,
                    newValues: [
                        'attribute' => $attribute->name,
                        'name' => $value->name,
                        'description' => $value->description,
                        'tone' => $value->tone,
                        'svg_layer' => $value->svg_layer,
                        'sort_order' => $value->sort_order,
                        'status' => $value->status->value,
                    ],
                    context: $auditContext,
                    origin: $origin,
                );

                return $value;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => __('validation.value_name_unique')]);
        }
    }

    /**
     * Hook for DEC-PRD-53 / E-72: assigning a non-null `svg_layer` must be rejected while a product
     * that admits the value has a template without that layer. Templates arrive in Phase 21; task
     * 22.7 fills this call site with `CatalogUsage::productsMissingLayer()`.
     */
    private function ensureNoProductMissesLayer(AttributeValue $value): void
    {
        // Intentionally empty until task 22.7.
    }
}
