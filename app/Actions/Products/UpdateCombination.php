<?php

namespace App\Actions\Products;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Enums\CatalogStatus;
use App\Models\CatalogCode;
use App\Models\Combination;
use App\Models\Product;
use App\Models\User;
use App\Support\Products\AxisSignature;
use App\Support\Products\CombinationAudit;
use App\Support\Products\CombinationRules;
use App\Support\Products\ProductCombinations;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Edits a combination (PRD-012, design Decision 12) with the same rules as the creation (E-68):
 * code, description, axes and restrictions. Status has its own Actions. The product is locked first
 * and the combination second. The overlap check only applies while the combination is active (two
 * ACTIVE combinations cannot coincide, DEC-PRD-39); an inactive one is checked when it is reactivated
 * (E-59). The audit row carries only the fields that changed and nothing is written when nothing
 * changed (E-68, as `UpdateProduct`).
 */
class UpdateCombination
{
    public function __construct(private readonly RecordAuditEvent $audit) {}

    /**
     * @param  array<string, mixed>  $data  validated input (see CombinationRules::rules()); `description` keeps its value when not sent
     *
     * @throws ValidationException when a combination rule rejects the data
     */
    public function handle(Combination $combination, array $data, User $actor): Combination
    {
        try {
            return DB::transaction(fn (): Combination => $this->update($combination, $data, $actor));
        } catch (UniqueConstraintViolationException $exception) {
            throw CombinationRules::duplicateKeyError($exception);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function update(Combination $combination, array $data, User $actor): Combination
    {
        $product = Product::query()->lockForUpdate()->findOrFail($combination->product_id);
        $combination = Combination::query()->lockForUpdate()->findOrFail($combination->id);
        $combination->setRelation('product', $product);

        $this->ensureEditableWithoutHistory($combination);

        $before = CombinationAudit::snapshot($combination);

        ['axes' => $axes, 'restrictions' => $restrictions] = CombinationRules::validate(
            ProductCombinations::declared($product),
            $data['axes'] ?? [],
            $data['restrictions'] ?? [],
        );

        if ($combination->status === CatalogStatus::Active) {
            $overlapping = ProductCombinations::overlappingCode($product, $axes, $combination->id);

            if ($overlapping !== null) {
                throw ValidationException::withMessages(['axes' => __('validation.combination_overlap', ['code' => $overlapping])]);
            }
        }

        $combination->fill([
            'description' => array_key_exists('description', $data) ? $data['description'] : $combination->description,
            'axis_signature' => AxisSignature::of($axes),
        ])->save();

        $registry = CatalogCode::query()->where('combination_id', $combination->id)->firstOrFail();

        if ($registry->code !== $data['code']) {
            $registry->forceFill(['code' => $data['code']])->save();
        }

        ProductCombinations::syncValues($combination, $axes, $restrictions);

        if (array_key_exists('included_customization_ids', $data)) {
            ProductCombinations::syncCustomizations($combination, $data['included_customization_ids']);
        }

        $after = CombinationAudit::snapshot($combination);
        $changed = array_keys(array_filter($after, fn (mixed $value, string $field): bool => $value !== $before[$field], ARRAY_FILTER_USE_BOTH));

        if ($changed !== []) {
            $combination->touch();

            $this->audit->handle(
                AuditAction::CombinationUpdated,
                $actor,
                $combination,
                oldValues: array_intersect_key($before, array_flip($changed)),
                newValues: array_intersect_key($after, array_flip($changed)),
            );
        }

        return $combination;
    }

    /**
     * Hook for DEC-PRD-21: the code and the axis values of a combination with history (quotes,
     * orders or stock movements) cannot change; a new combination is created and the old one
     * deactivated. No history exists in 003, so the hook is empty: 004, 006 and 008 fill it with
     * their condition and the E-29 scenario.
     */
    private function ensureEditableWithoutHistory(Combination $combination): void
    {
        // Intentionally empty until 004, 006 and 008.
    }
}
