<?php

namespace App\Actions\Products;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Enums\CatalogStatus;
use App\Models\DetailLocation;
use App\Models\User;
use App\Support\Audit\AuditOrigin;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates a detail location, a place on a garment where a detail can go (PRD-007). It starts
 * active with an optional SVG layer. Locations have no order in the spec, so they are listed by
 * name. The case-insensitive unique index on `name` (DEC-PRD-45, E-64) is the backstop of the
 * request rule: a concurrent duplicate becomes the same field error instead of a server error.
 */
class CreateDetailLocation
{
    public function __construct(private readonly RecordAuditEvent $audit) {}

    /**
     * @param  array<string, mixed>  $data  validated input (`name`, optional `svg_layer`)
     * @param  array<string, mixed>  $auditContext
     *
     * @throws ValidationException when the name is taken by a concurrent request
     */
    public function handle(array $data, User $actor, ?AuditOrigin $origin = null, array $auditContext = []): DetailLocation
    {
        try {
            return DB::transaction(function () use ($data, $actor, $origin, $auditContext): DetailLocation {
                $location = DetailLocation::query()->create([
                    'name' => $data['name'],
                    'svg_layer' => $data['svg_layer'] ?? null,
                    'status' => CatalogStatus::Active,
                ]);

                $this->ensureNoProductMissesLayer($location);

                $this->audit->handle(
                    AuditAction::CatalogCreated,
                    $actor,
                    $location,
                    newValues: [
                        'name' => $location->name,
                        'svg_layer' => $location->svg_layer,
                        'status' => $location->status->value,
                    ],
                    context: $auditContext,
                    origin: $origin,
                );

                return $location;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => __('validation.location_name_unique')]);
        }
    }

    /**
     * Hook for DEC-PRD-53 / E-72: assigning a non-null `svg_layer` must be rejected while a product
     * that admits the location has a template without that layer. A new location is admitted by no
     * product yet, so this call site only matters once imports can create it already linked; task
     * 22.7 fills it with `CatalogUsage::productsMissingLayer()`.
     */
    private function ensureNoProductMissesLayer(DetailLocation $location): void
    {
        // Intentionally empty until task 22.7.
    }
}
