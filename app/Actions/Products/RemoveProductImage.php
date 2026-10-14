<?php

namespace App\Actions\Products;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Models\Product;
use App\Models\User;
use App\Support\Products\Assets\ImageStore;
use Illuminate\Database\Eloquent\Model;

/**
 * Removes the main image of a product (PRD-020). The files are deleted after the commit and the
 * removal is audited as `products.updated` with `image` going from the display path to null.
 */
class RemoveProductImage
{
    public function __construct(
        private readonly ImageStore $images,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(Product $product, User $actor): void
    {
        $this->images->remove(
            $product,
            fn (Model $owner, ?string $previous, ?string $current) => $this->audit->handle(
                AuditAction::ProductUpdated,
                $actor,
                $owner,
                oldValues: ['image' => $previous],
                newValues: ['image' => $current],
            ),
        );
    }
}
