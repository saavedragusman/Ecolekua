<?php

namespace App\Actions\Products;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AttributeSpecialUse;
use App\Enums\AuditAction;
use App\Models\Product;
use App\Models\ProductTemplate;
use App\Models\User;
use App\Support\Products\Assets\InvalidSvgException;
use App\Support\Products\Assets\SvgLayerDetector;
use App\Support\Products\Assets\SvgTemplateSanitizer;
use App\Support\Products\Assets\TemplateLayerRequirements;
use App\Support\Products\Assets\TemplateStore;
use App\Support\Products\CatalogUsage;
use App\Support\Products\ProductAudit;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Stores the SVG template of a product, the general one or the one of a gender value the product
 * admits (PRD-020, E-37..E-41, DEC-PRD-29, DEC-PRD-49, design Decision 18). The file is sanitized
 * first and the layers are read from the sanitized bytes (E-39); then, under the product lock, the
 * variant and the required layers are checked (`cuerpo`, the layer of every admitted value and of
 * every admitted detail location) before anything is written. A new upload for the same variant
 * replaces the previous one: the new file is written first, the transaction stores its path, on any
 * exception the new file is deleted, and the replaced file is deleted after the commit.
 */
class UploadProductTemplate
{
    public function __construct(
        private readonly SvgTemplateSanitizer $sanitizer,
        private readonly SvgLayerDetector $detector,
        private readonly TemplateStore $store,
        private readonly RecordAuditEvent $audit,
    ) {}

    /**
     * @param  int|null  $variantValueId  the gender-use value the template is for, or null for the general template
     *
     * @throws ValidationException when the file is not a safe SVG, lacks required layers or the variant is not admitted
     */
    public function handle(Product $product, UploadedFile $file, ?int $variantValueId, User $actor): ProductTemplate
    {
        try {
            $svg = $this->sanitizer->sanitize($file->getContent());
        } catch (InvalidSvgException $exception) {
            throw ValidationException::withMessages(['template' => __('validation.template_'.$exception->reason)]);
        }

        $layers = $this->detector->layers($svg);
        $name = mb_substr($file->getClientOriginalName(), 0, 255);
        $written = null;

        try {
            return DB::transaction(function () use ($product, $variantValueId, $actor, $svg, $layers, $name, &$written): ProductTemplate {
                $product = Product::query()->lockForUpdate()->findOrFail($product->id);

                $variantName = $this->ensureVariantAdmitted($product, $variantValueId);
                $this->ensureRequiredLayers($product, $layers);

                $previous = $product->templates()->with('variantValue')->where('variant_key', $variantValueId ?? 0)->first();
                $replacedPath = $previous?->svg_path;
                $old = $previous === null ? [] : ProductAudit::template($previous);
                $written = $this->store->put($product->id, $svg);

                $template = $previous ?? new ProductTemplate(['product_id' => $product->id, 'variant_value_id' => $variantValueId]);
                $template->fill(['svg_path' => $written, 'original_name' => $name, 'layers' => $layers])->save();

                $this->audit->handle(
                    AuditAction::ProductTemplateUploaded,
                    $actor,
                    $product,
                    oldValues: $old,
                    newValues: ['file' => $name, 'variant' => $variantName, 'layers' => $layers],
                );

                if ($replacedPath !== null) {
                    $this->store->deleteAfterCommit($replacedPath);
                }

                return $template;
            });
        } catch (Throwable $exception) {
            if ($written !== null) {
                $this->store->delete($written);
            }

            throw $exception;
        }
    }

    /**
     * A variant must be a value of the product's gender-use attribute that the product admits
     * (E-41, DEC-PRD-49). A product without that attribute only takes the general template.
     *
     * @return string|null the name of the variant value, null for the general template
     *
     * @throws ValidationException on `variant_value_id`
     */
    private function ensureVariantAdmitted(Product $product, ?int $variantValueId): ?string
    {
        if ($variantValueId === null) {
            return null;
        }

        $value = DB::table('product_attributes')
            ->join('catalog_attributes', 'catalog_attributes.id', '=', 'product_attributes.catalog_attribute_id')
            ->join('product_attribute_values', 'product_attribute_values.product_attribute_id', '=', 'product_attributes.id')
            ->join('attribute_values', 'attribute_values.id', '=', 'product_attribute_values.attribute_value_id')
            ->where('product_attributes.product_id', $product->id)
            ->where('catalog_attributes.special_use', AttributeSpecialUse::Gender->value)
            ->where('attribute_values.id', $variantValueId)
            ->value('attribute_values.name');

        if ($value === null) {
            throw ValidationException::withMessages(['variant_value_id' => __('validation.template_variant_invalid')]);
        }

        return (string) $value;
    }

    /**
     * @param  list<string>  $layers
     *
     * @throws ValidationException on `template`, naming the missing layers (E-38)
     */
    private function ensureRequiredLayers(Product $product, array $layers): void
    {
        $missing = TemplateLayerRequirements::missing($layers, CatalogUsage::requiredTemplateLayers($product->id));

        if ($missing !== []) {
            throw ValidationException::withMessages(['template' => __('validation.template_missing_layers', ['layers' => implode(', ', $missing)])]);
        }
    }
}
