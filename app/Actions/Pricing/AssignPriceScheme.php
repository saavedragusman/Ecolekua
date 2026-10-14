<?php

namespace App\Actions\Pricing;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Enums\CatalogStatus;
use App\Models\CombinationPrice;
use App\Models\PriceScheme;
use App\Models\Product;
use App\Models\User;
use App\Support\Audit\AuditOrigin;
use App\Support\Pricing\AffectedPrices;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Assigns an active price scheme to one or many products (COT-002, DEC-COT-22, E-04, design Decision 9).
 * The product price page header (one id) and the bulk action of the pricing list (many ids) share it.
 *
 * - Lock order (Decision 10): the scheme row, then the product rows in id order.
 * - Products already on the scheme are skipped: nothing is written and nothing is audited.
 * - A product that changes scheme and holds prices (any combination, tier or size group) needs
 *   `confirm_price_removal` (DEC-COT-23). Without it the answer is a validation error on
 *   `confirm_price_removal` and nothing changes; with it those prices are deleted in the same
 *   transaction and listed in the audit of that product under `old_values.deleted_prices`.
 * - One `pricing.product_scheme_assigned` audit row per product with the scheme name before and after.
 * - Combos have no scheme: only product ids are accepted.
 */
class AssignPriceScheme
{
    public function __construct(private readonly RecordAuditEvent $audit) {}

    /**
     * @param  list<int>  $productIds
     * @param  array<string, mixed>  $auditContext
     *
     * @throws ValidationException when the scheme is inactive, a product does not exist or prices would be deleted unconfirmed
     */
    public function handle(
        array $productIds,
        PriceScheme $scheme,
        bool $confirmPriceRemoval,
        User $actor,
        ?AuditOrigin $origin = null,
        array $auditContext = [],
    ): void {
        $productIds = array_values(array_unique($productIds));
        sort($productIds);

        DB::transaction(function () use ($productIds, $scheme, $confirmPriceRemoval, $actor, $origin, $auditContext): void {
            $scheme = PriceScheme::query()->lockForUpdate()->findOrFail($scheme->id);

            if ($scheme->status !== CatalogStatus::Active) {
                throw ValidationException::withMessages(['price_scheme_id' => __('validation.scheme_inactive')]);
            }

            $products = Product::query()->with('priceScheme')->whereKey($productIds)->orderBy('id')->lockForUpdate()->get();

            if ($products->count() !== count($productIds)) {
                throw ValidationException::withMessages(['product_ids' => __('validation.exists', ['attribute' => __('validation.attributes.product_ids')])]);
            }

            $changing = $products->filter(fn (Product $product): bool => $product->price_scheme_id !== $scheme->id);

            $affected = AffectedPrices::forProducts(array_values(array_map(fn (Product $product): int => $product->id, $changing->all())));

            if ($affected !== [] && ! $confirmPriceRemoval) {
                throw AffectedPrices::confirmationRequired($affected, 'confirm_price_removal');
            }

            CombinationPrice::query()->whereKey(array_column($affected, 'id'))->delete();
            $deletedByProduct = AffectedPrices::auditByProduct($affected);

            foreach ($changing as $product) {
                $oldValues = ['scheme' => $product->priceScheme?->name];

                if (isset($deletedByProduct[$product->id])) {
                    $oldValues['deleted_prices'] = $deletedByProduct[$product->id];
                }

                $product->forceFill(['price_scheme_id' => $scheme->id])->save();

                $this->audit->handle(
                    AuditAction::ProductSchemeAssigned,
                    $actor,
                    $product,
                    oldValues: $oldValues,
                    newValues: ['scheme' => $scheme->name],
                    context: $auditContext,
                    origin: $origin,
                );
            }
        });
    }
}
