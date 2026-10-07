<?php

namespace App\Actions\Products;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AttributePresentation;
use App\Enums\AttributeSpecialUse;
use App\Enums\AuditAction;
use App\Enums\CatalogStatus;
use App\Models\AttributeValue;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Replaces the set of colors Ecolekua offers in a fabric (PRD-002, DEC-PRD-32, E-46). The value must
 * belong to the fabric attribute; every chosen color must be a value of the color attribute, and the
 * colors being added must be active. Removing a color only affects new selections: nothing else is
 * touched. A request that changes nothing writes no audit row.
 */
class SyncFabricOfferedColors
{
    public function __construct(private readonly RecordAuditEvent $audit) {}

    /**
     * @param  list<int>  $colorIds
     *
     * @throws ValidationException when the value is not a fabric or a color is invalid
     */
    public function handle(AttributeValue $fabricValue, array $colorIds, User $actor): void
    {
        DB::transaction(function () use ($fabricValue, $colorIds, $actor): void {
            $fabricValue = AttributeValue::query()->with('catalogAttribute')->lockForUpdate()->findOrFail($fabricValue->id);

            if ($fabricValue->catalogAttribute->special_use !== AttributeSpecialUse::Fabric) {
                throw ValidationException::withMessages(['color_ids' => __('validation.offered_colors_not_fabric')]);
            }

            $colorIds = array_values(array_unique(array_map('intval', $colorIds)));

            $colors = AttributeValue::query()
                ->whereIn('id', $colorIds)
                ->whereHas('catalogAttribute', fn ($query) => $query->where('presentation', AttributePresentation::Color->value))
                ->get()
                ->keyBy('id');

            if ($colors->count() !== count($colorIds)) {
                throw ValidationException::withMessages(['color_ids' => __('validation.offered_colors_invalid')]);
            }

            $current = $fabricValue->offeredColors()->get()->keyBy('id');
            $added = $colors->diffKeys($current);
            $removed = $current->diffKeys($colors);

            if ($added->contains(fn (AttributeValue $color): bool => $color->status !== CatalogStatus::Active)) {
                throw ValidationException::withMessages(['color_ids' => __('validation.offered_colors_inactive')]);
            }

            if ($added->isEmpty() && $removed->isEmpty()) {
                return;
            }

            $fabricValue->offeredColors()->attach($added->keys()->all());
            $fabricValue->offeredColors()->detach($removed->keys()->all());

            $this->audit->handle(
                AuditAction::CatalogFabricColorsUpdated,
                $actor,
                $fabricValue,
                oldValues: ['removed' => $removed->pluck('name')->sort()->values()->all()],
                newValues: ['added' => $added->pluck('name')->sort()->values()->all()],
            );
        });
    }
}
