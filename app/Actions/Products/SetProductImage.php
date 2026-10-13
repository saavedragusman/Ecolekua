<?php

namespace App\Actions\Products;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Models\Product;
use App\Models\User;
use App\Support\Products\Assets\ImageStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * Sets or replaces the main image of a product (PRD-020, E-35, design Decision 18). The audit row
 * `products.updated` carries the previous and the new display path under `image`.
 */
class SetProductImage
{
    public function __construct(
        private readonly ImageStore $images,
        private readonly RecordAuditEvent $audit,
    ) {}

    /**
     * @throws ValidationException when the file is not an acceptable image
     */
    public function handle(Product $product, UploadedFile $file, User $actor): void
    {
        $this->images->replace(
            $product,
            "catalog/products/{$product->id}",
            $file,
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
