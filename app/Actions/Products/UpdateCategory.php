<?php

namespace App\Actions\Products;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Models\ProductCategory;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Renames a product category (PRD-001). Only `name` is editable here; order and status have their
 * own Actions. The audit row carries only the fields that changed and nothing is written when
 * nothing changed (same rule as `UpdateCustomer`).
 */
class UpdateCategory
{
    public function __construct(private readonly RecordAuditEvent $audit) {}

    /**
     * @param  array<string, mixed>  $data  validated input (`name`)
     *
     * @throws ValidationException when the name is taken by a concurrent request
     */
    public function handle(ProductCategory $category, array $data, User $actor): ProductCategory
    {
        try {
            return DB::transaction(function () use ($category, $data, $actor): ProductCategory {
                $category = ProductCategory::query()->lockForUpdate()->findOrFail($category->id);
                $before = ['name' => $category->name];

                $category->fill(['name' => $data['name']])->save();

                $after = ['name' => $category->name];
                $changed = array_keys(array_filter($after, fn (mixed $value, string $field): bool => $value !== $before[$field], ARRAY_FILTER_USE_BOTH));

                if ($changed !== []) {
                    $this->audit->handle(
                        AuditAction::CatalogUpdated,
                        $actor,
                        $category,
                        oldValues: array_intersect_key($before, array_flip($changed)),
                        newValues: array_intersect_key($after, array_flip($changed)),
                    );
                }

                return $category;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => __('validation.category_name_unique')]);
        }
    }
}
