<?php

namespace App\Actions\Products;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Models\DetailLocation;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Edits a detail location (PRD-007): name and SVG layer. Only the keys present in the input are
 * edited, so an omitted `svg_layer` is kept and an explicit null clears it. The audit row carries
 * the old and new value of the fields that changed and nothing is written when nothing changed.
 * Status has its own Actions.
 */
class UpdateDetailLocation
{
    /** Editable columns, in audit order. */
    private const FIELDS = ['name', 'svg_layer'];

    public function __construct(private readonly RecordAuditEvent $audit) {}

    /**
     * @param  array<string, mixed>  $data  validated input (`name` and optionally `svg_layer`)
     *
     * @throws ValidationException when the name is taken by a concurrent request
     */
    public function handle(DetailLocation $location, array $data, User $actor): DetailLocation
    {
        try {
            return DB::transaction(function () use ($location, $data, $actor): DetailLocation {
                $location = DetailLocation::query()->lockForUpdate()->findOrFail($location->id);
                $before = $this->snapshot($location);

                $input = array_intersect_key($data, array_flip(self::FIELDS));

                if (isset($input['svg_layer'])) {
                    $this->ensureNoProductMissesLayer($location, $input['svg_layer']);
                }

                $location->fill($input)->save();

                $after = $this->snapshot($location);
                $changed = array_keys(array_filter($after, fn (mixed $field, string $key): bool => $field !== $before[$key], ARRAY_FILTER_USE_BOTH));

                if ($changed !== []) {
                    $this->audit->handle(
                        AuditAction::CatalogUpdated,
                        $actor,
                        $location,
                        oldValues: array_intersect_key($before, array_flip($changed)),
                        newValues: array_intersect_key($after, array_flip($changed)),
                    );
                }

                return $location;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => __('validation.location_name_unique')]);
        }
    }

    /**
     * @return array<string, string|null>
     */
    private function snapshot(DetailLocation $location): array
    {
        return array_intersect_key($location->only(self::FIELDS), array_flip(self::FIELDS));
    }

    /**
     * Hook for DEC-PRD-53 / E-72: assigning or changing `svg_layer` to a non-null layer must be
     * rejected while a product that admits the location has a template without that layer, naming
     * the products. Templates arrive in Phase 21; task 22.7 fills this call site. Clearing the
     * layer is always allowed.
     */
    private function ensureNoProductMissesLayer(DetailLocation $location, string $layer): void
    {
        // Intentionally empty until task 22.7.
    }
}
