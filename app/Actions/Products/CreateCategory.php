<?php

namespace App\Actions\Products;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Enums\CatalogStatus;
use App\Models\ProductCategory;
use App\Models\User;
use App\Support\Audit\AuditOrigin;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates a product category (PRD-001, design Decision 9). The new row goes to the end of the
 * order (`max(sort_order) + 1`) and starts active. The case-insensitive unique index on `name`
 * (DEC-PRD-45) is the backstop of the request rule: a concurrent duplicate becomes the same
 * field error instead of a server error.
 */
class CreateCategory
{
    public function __construct(private readonly RecordAuditEvent $audit) {}

    /**
     * @param  array<string, mixed>  $data  validated input (`name`)
     * @param  array<string, mixed>  $auditContext
     *
     * @throws ValidationException when the name is taken by a concurrent request
     */
    public function handle(array $data, User $actor, ?AuditOrigin $origin = null, array $auditContext = []): ProductCategory
    {
        try {
            return DB::transaction(function () use ($data, $actor, $origin, $auditContext): ProductCategory {
                $category = ProductCategory::query()->create([
                    'name' => $data['name'],
                    'sort_order' => ((int) ProductCategory::query()->lockForUpdate()->max('sort_order')) + 1,
                    'status' => CatalogStatus::Active,
                ]);

                $this->audit->handle(
                    AuditAction::CatalogCreated,
                    $actor,
                    $category,
                    newValues: [
                        'name' => $category->name,
                        'sort_order' => $category->sort_order,
                        'status' => $category->status->value,
                    ],
                    context: $auditContext,
                    origin: $origin,
                );

                return $category;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => __('validation.category_name_unique')]);
        }
    }
}
