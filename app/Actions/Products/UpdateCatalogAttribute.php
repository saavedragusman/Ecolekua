<?php

namespace App\Actions\Products;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AttributePresentation;
use App\Enums\AttributeSpecialUse;
use App\Enums\AuditAction;
use App\Models\CatalogAttribute;
use App\Models\User;
use App\Support\Products\CatalogRules;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Edits the name, presentation and special use of an attribute (PRD-002). Order and status have
 * their own Actions. The audit row carries only the fields that changed and nothing is written when
 * nothing changed.
 */
class UpdateCatalogAttribute
{
    public function __construct(private readonly RecordAuditEvent $audit) {}

    /**
     * @param  array<string, mixed>  $data  validated input (`name`, `presentation`, `special_use`)
     *
     * @throws ValidationException when a unique index rejects the attribute or a product declares it
     */
    public function handle(CatalogAttribute $attribute, array $data, User $actor): CatalogAttribute
    {
        try {
            return DB::transaction(function () use ($attribute, $data, $actor): CatalogAttribute {
                $attribute = CatalogAttribute::query()->lockForUpdate()->findOrFail($attribute->id);
                $before = $this->snapshot($attribute);

                $this->ensureTonesForColor($attribute, $data);
                $this->ensureNotDeclaredByProducts($attribute, $data);

                $attribute->fill([
                    'name' => $data['name'],
                    'presentation' => AttributePresentation::from($data['presentation']),
                    'special_use' => isset($data['special_use']) ? AttributeSpecialUse::from($data['special_use']) : null,
                ])->save();

                $after = $this->snapshot($attribute);
                $changed = array_keys(array_filter($after, fn (mixed $value, string $field): bool => $value !== $before[$field], ARRAY_FILTER_USE_BOTH));

                if ($changed !== []) {
                    $this->audit->handle(
                        AuditAction::CatalogUpdated,
                        $actor,
                        $attribute,
                        oldValues: array_intersect_key($before, array_flip($changed)),
                        newValues: array_intersect_key($after, array_flip($changed)),
                    );
                }

                return $attribute;
            });
        } catch (UniqueConstraintViolationException $exception) {
            throw ValidationException::withMessages(CatalogRules::attributeUniqueErrors($exception));
        }
    }

    /**
     * @return array{name: string, presentation: string, special_use: string|null}
     */
    private function snapshot(CatalogAttribute $attribute): array
    {
        return [
            'name' => $attribute->name,
            'presentation' => $attribute->presentation->value,
            'special_use' => $attribute->special_use?->value,
        ];
    }

    /**
     * Switching to color presentation requires every value to have a tone (E-36). The request
     * checks it too, but only this check runs under the attribute lock, so a concurrent value
     * edit cannot slip a tone-less value past it. The values are locked so none changes meanwhile.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    private function ensureTonesForColor(CatalogAttribute $attribute, array $data): void
    {
        if ($data['presentation'] !== AttributePresentation::Color->value || $attribute->presentation === AttributePresentation::Color) {
            return;
        }

        $withoutTone = $attribute->values()->lockForUpdate()->whereNull('tone')->exists();

        if ($withoutTone) {
            throw ValidationException::withMessages(['presentation' => __('validation.attribute_color_requires_tones')]);
        }
    }

    /**
     * Hook for DEC-PRD-51 / E-69: changing the presentation or the special use (assigning,
     * changing or removing it) must be rejected while any product declares the attribute, naming
     * the products on the offending field. The product tables arrive in Phase 8 and
     * `CatalogUsage::productsDeclaring()` in Phase 9, which fills this hook (task 9.6).
     *
     * @param  array<string, mixed>  $data
     */
    private function ensureNotDeclaredByProducts(CatalogAttribute $attribute, array $data): void
    {
        // Intentionally empty until task 9.6.
    }
}
