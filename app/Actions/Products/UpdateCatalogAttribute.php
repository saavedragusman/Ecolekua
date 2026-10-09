<?php

namespace App\Actions\Products;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AttributePresentation;
use App\Enums\AttributeSpecialUse;
use App\Enums\AuditAction;
use App\Models\CatalogAttribute;
use App\Models\User;
use App\Support\Products\CatalogRules;
use App\Support\Products\CatalogUsage;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Edits the name, presentation and special use of an attribute (PRD-002). Order and status have
 * their own Actions. The audit row carries only the fields that changed and nothing is written when
 * nothing changed. Becoming color clears the SVG layer of the attribute's values (DEC-PRD-97).
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

                $this->clearLayersForColor($attribute, $data, $actor);

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
     * DEC-PRD-97: the values of a color attribute carry no SVG layer, so when an attribute becomes
     * color its values lose theirs in the same transaction, one audit row per value with the
     * previous layer. Runs after the in-use check, so only attributes no product declares get here.
     *
     * @param  array<string, mixed>  $data
     */
    private function clearLayersForColor(CatalogAttribute $attribute, array $data, User $actor): void
    {
        if ($data['presentation'] !== AttributePresentation::Color->value || $attribute->presentation === AttributePresentation::Color) {
            return;
        }

        $layered = $attribute->values()->lockForUpdate()->whereNotNull('svg_layer')->orderBy('id')->get();

        foreach ($layered as $value) {
            $previous = $value->svg_layer;
            $value->forceFill(['svg_layer' => null])->save();

            $this->audit->handle(
                AuditAction::CatalogUpdated,
                $actor,
                $value,
                oldValues: ['svg_layer' => $previous],
                newValues: ['svg_layer' => null],
            );
        }
    }

    /**
     * DEC-PRD-51 / E-69: changing the presentation or the special use (assigning, changing or
     * removing it) is rejected while any product, active or not, declares the attribute. The error
     * names the products on the offending field; a rename is not affected.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    private function ensureNotDeclaredByProducts(CatalogAttribute $attribute, array $data): void
    {
        $changed = [];

        if ($data['presentation'] !== $attribute->presentation->value) {
            $changed[] = 'presentation';
        }

        if (($data['special_use'] ?? null) !== $attribute->special_use?->value) {
            $changed[] = 'special_use';
        }

        if ($changed === []) {
            return;
        }

        $products = CatalogUsage::productsDeclaring($attribute);

        if ($products !== []) {
            $message = __('validation.attribute_in_use_change', ['products' => CatalogUsage::quote($products)]);

            throw ValidationException::withMessages(array_fill_keys($changed, $message));
        }
    }
}
