<?php

namespace App\Actions\Products;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Models\AttributeValue;
use App\Models\User;
use App\Support\Products\CatalogRules;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Edits a value of a catalog attribute (PRD-002): name (typo correction, reflected everywhere
 * because combinations reference the value by id), description, tone and SVG layer. Only the keys
 * present in the input are edited. The audit row carries the old and new value of the fields that
 * changed and nothing is written when nothing changed.
 */
class UpdateAttributeValue
{
    /** Editable columns, in audit order. */
    private const FIELDS = ['name', 'description', 'tone', 'svg_layer'];

    public function __construct(private readonly RecordAuditEvent $audit) {}

    /**
     * @param  array<string, mixed>  $data  validated input (`name` and any of `description`, `tone`, `svg_layer`)
     *
     * @throws ValidationException when the name is taken in the attribute by a concurrent request
     */
    public function handle(AttributeValue $value, array $data, User $actor): AttributeValue
    {
        try {
            return DB::transaction(function () use ($value, $data, $actor): AttributeValue {
                $value = AttributeValue::query()->lockForUpdate()->findOrFail($value->id);
                $before = $this->snapshot($value);

                $input = array_intersect_key($data, array_flip(self::FIELDS));

                if (array_key_exists('tone', $input)) {
                    $input['tone'] = CatalogRules::normalizeTone($input['tone']);
                }

                if (isset($input['svg_layer'])) {
                    $this->ensureNoProductMissesLayer($value, $input['svg_layer']);
                }

                $value->fill($input)->save();

                $after = $this->snapshot($value);
                $changed = array_keys(array_filter($after, fn (mixed $field, string $key): bool => $field !== $before[$key], ARRAY_FILTER_USE_BOTH));

                if ($changed !== []) {
                    $this->audit->handle(
                        AuditAction::CatalogUpdated,
                        $actor,
                        $value,
                        oldValues: array_intersect_key($before, array_flip($changed)),
                        newValues: array_intersect_key($after, array_flip($changed)),
                    );
                }

                return $value;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => __('validation.value_name_unique')]);
        }
    }

    /**
     * @return array<string, string|null>
     */
    private function snapshot(AttributeValue $value): array
    {
        return array_intersect_key($value->only(self::FIELDS), array_flip(self::FIELDS));
    }

    /**
     * Hook for DEC-PRD-53 / E-72: assigning or changing `svg_layer` to a non-null layer must be
     * rejected while a product that admits the value has a template without that layer, naming the
     * products. Templates arrive in Phase 21; task 22.7 fills this call site. Clearing the layer is
     * always allowed.
     */
    private function ensureNoProductMissesLayer(AttributeValue $value, string $layer): void
    {
        // Intentionally empty until task 22.7.
    }
}
