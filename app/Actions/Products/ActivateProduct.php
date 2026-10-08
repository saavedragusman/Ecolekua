<?php

namespace App\Actions\Products;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Enums\CatalogStatus;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Reactivates a product (PRD-013, design Decision 16). Only `status` changes; each combination
 * returns to its own previous status because deactivating the product never touched them (E-25).
 * A product that declares an inactive attribute is reactivated all the same and stays unselectable
 * until the attribute is reactivated (design note N-7). An already active product is a no-op with no
 * audit row.
 */
class ActivateProduct
{
    public function __construct(private readonly RecordAuditEvent $audit) {}

    public function handle(Product $product, User $actor): Product
    {
        return DB::transaction(function () use ($product, $actor): Product {
            $product = Product::query()->lockForUpdate()->findOrFail($product->id);

            if ($product->status === CatalogStatus::Active) {
                return $product;
            }

            $product->forceFill(['status' => CatalogStatus::Active])->save();

            $this->audit->handle(
                AuditAction::ProductActivated,
                $actor,
                $product,
                oldValues: ['status' => CatalogStatus::Inactive->value],
                newValues: ['status' => CatalogStatus::Active->value],
            );

            return $product;
        });
    }
}
