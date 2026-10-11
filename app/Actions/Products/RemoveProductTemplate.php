<?php

namespace App\Actions\Products;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Models\Product;
use App\Models\ProductTemplate;
use App\Models\User;
use App\Support\Products\Assets\TemplateStore;
use App\Support\Products\ProductAudit;
use Illuminate\Support\Facades\DB;

/**
 * Removes one SVG template of a product (PRD-020, N-5). The row goes in the transaction and the file
 * after the commit; the audit row `products.template_removed` keeps the file name, variant and
 * layers. Removing a template never changes combinations or the catalog: the preview simply has no
 * template for that variant.
 */
class RemoveProductTemplate
{
    public function __construct(
        private readonly TemplateStore $store,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(Product $product, ProductTemplate $template, User $actor): void
    {
        DB::transaction(function () use ($product, $template, $actor): void {
            $product = Product::query()->lockForUpdate()->findOrFail($product->id);
            $template = $product->templates()->with('variantValue')->whereKey($template->id)->first();

            // Already removed by a concurrent request: nothing to do and nothing to audit.
            if ($template === null) {
                return;
            }

            $old = ProductAudit::template($template);
            $path = $template->svg_path;

            $template->delete();

            $this->audit->handle(AuditAction::ProductTemplateRemoved, $actor, $product, oldValues: $old);

            $this->store->deleteAfterCommit($path);
        });
    }
}
