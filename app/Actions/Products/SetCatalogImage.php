<?php

namespace App\Actions\Products;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Models\AttributeValue;
use App\Models\DetailLocation;
use App\Models\User;
use App\Support\Products\Assets\ImageStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * Sets or replaces the image of an attribute value or of a detail location (PRD-020, E-35, design
 * Decision 18). The audit row `catalog.updated` carries the previous and the new display path
 * under `image`.
 */
class SetCatalogImage
{
    public function __construct(
        private readonly ImageStore $images,
        private readonly RecordAuditEvent $audit,
    ) {}

    /**
     * @throws ValidationException when the file is not an acceptable image
     */
    public function handle(AttributeValue|DetailLocation $owner, UploadedFile $file, User $actor): void
    {
        $directory = $owner instanceof AttributeValue ? 'catalog/values' : 'catalog/locations';

        $this->images->replace(
            $owner,
            "{$directory}/{$owner->id}",
            $file,
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
