<?php

namespace App\Actions\Products;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Enums\CatalogStatus;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Deactivates a product (PRD-013, design Decision 16). Only `status` changes: data and structure
 * stay as they are, and the combinations keep their own status because nothing is cascaded (E-25);
 * the offer takes them out by computing availability. An already inactive product is a no-op with
 * no audit row (same rule as `DeactivateCustomer`).
 */
class DeactivateProduct
{
    public function __construct(private readonly RecordAuditEvent $audit) {}

    public function handle(Product $product, User $actor): Product
    {
        return DB::transaction(function () use ($product, $actor): Product {
            $product = Product::query()->lockForUpdate()->findOrFail($product->id);

            if ($product->status === CatalogStatus::Inactive) {
                return $product;
            }

            $product->forceFill(['status' => CatalogStatus::Inactive])->save();

            $this->audit->handle(
                AuditAction::ProductDeactivated,
                $actor,
                $product,
                oldValues: ['status' => CatalogStatus::Active->value],
                newValues: ['status' => CatalogStatus::Inactive->value],
            );

            return $product;
        });
    }
}
