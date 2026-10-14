<?php

namespace App\Actions\Products;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Models\AttributeValue;
use App\Models\DetailLocation;
use App\Models\User;
use App\Support\Products\Assets\ImageStore;
use Illuminate\Database\Eloquent\Model;

/**
 * Removes the image of an attribute value or of a detail location (PRD-020). The files are deleted
 * after the commit and the removal is audited as `catalog.updated` with `image` going from the
 * display path to null.
 */
class RemoveCatalogImage
{
    public function __construct(
        private readonly ImageStore $images,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(AttributeValue|DetailLocation $owner, User $actor): void
    {
        $this->images->remove(
            $owner,
            fn (Model $locked, ?string $previous, ?string $current) => $this->audit->handle(
                AuditAction::CatalogUpdated,
                $actor,
                $locked,
                oldValues: ['image' => $previous],
                newValues: ['image' => $current],
            ),
        );
    }
}
