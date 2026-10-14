<?php

namespace App\Actions\Products;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Enums\CatalogStatus;
use App\Enums\SupplyMode;
use App\Models\Product;
use App\Models\User;
use App\Support\Audit\AuditOrigin;
use App\Support\Products\ProductAudit;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Registers a product (PRD-003, design Decision 10). The product starts active and the audit row
 * carries every created value plus the category name (E-01). The default minimum stock only exists
 * in `stock_with_minimum` (DEC-PRD-46) and custom color is stored only for `on_demand`, where it
 * defaults to true unless the team disables it (DEC-PRD-34); portal visibility defaults to true
 * (DEC-PRD-19). The case-insensitive unique index on `name` (DEC-PRD-45) is the backstop of the
 * request rule: a concurrent duplicate becomes the same field error.
 */
class CreateProduct
{
    public function __construct(private readonly RecordAuditEvent $audit) {}

    /**
     * @param  array<string, mixed>  $data  validated input (see CatalogRules::productRules())
     * @param  array<string, mixed>  $auditContext
     *
     * @throws ValidationException when the name is taken by a concurrent request
     */
    public function handle(array $data, User $actor, ?AuditOrigin $origin = null, array $auditContext = []): Product
    {
        $mode = SupplyMode::from($data['supply_mode']);

        try {
            return DB::transaction(function () use ($data, $mode, $actor, $origin, $auditContext): Product {
                $product = Product::query()->create([
                    'name' => $data['name'],
                    'description' => $data['description'] ?? null,
                    'product_category_id' => $data['product_category_id'],
                    'business_line' => $data['business_line'],
                    'supply_mode' => $mode,
                    'min_stock_default' => $mode === SupplyMode::StockWithMinimum ? $data['min_stock_default'] : null,
                    'allows_custom_color' => $mode === SupplyMode::OnDemand && filter_var($data['allows_custom_color'] ?? true, FILTER_VALIDATE_BOOLEAN),
                    'portal_visible' => $data['portal_visible'] ?? true,
                    'status' => CatalogStatus::Active,
                ])->load('category');

                $this->audit->handle(
                    AuditAction::ProductCreated,
                    $actor,
                    $product,
                    newValues: ProductAudit::snapshot($product),
                    context: $auditContext,
                    origin: $origin,
                );

                return $product;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => __('validation.product_name_unique')]);
        }
    }
}
