<?php

namespace App\Actions\Pricing;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Enums\CatalogStatus;
use App\Exceptions\BusinessRuleViolation;
use App\Models\PriceScheme;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Deactivates a price scheme (COT-001, E-03, design Decision 8). A scheme assigned to any active
 * product is refused, naming those products; inactive products do not block. An already inactive
 * scheme is a no-op with no audit row.
 */
class DeactivatePriceScheme
{
    public function __construct(private readonly RecordAuditEvent $audit) {}

    /**
     * @throws BusinessRuleViolation when an active product uses the scheme
     */
    public function handle(PriceScheme $scheme, User $actor): PriceScheme
    {
        return DB::transaction(function () use ($scheme, $actor): PriceScheme {
            $scheme = PriceScheme::query()->lockForUpdate()->findOrFail($scheme->id);

            if ($scheme->status === CatalogStatus::Inactive) {
                return $scheme;
            }

            $products = Product::query()
                ->where('price_scheme_id', $scheme->id)
                ->where('status', CatalogStatus::Active->value)
                ->orderBy('name')
                ->pluck('name');

            if ($products->isNotEmpty()) {
                throw new BusinessRuleViolation('El esquema está asignado a productos activos: '.$products->implode(', ').'.');
            }

            $scheme->forceFill(['status' => CatalogStatus::Inactive])->save();

            $this->audit->handle(
                AuditAction::PriceSchemeDeactivated,
                $actor,
                $scheme,
                oldValues: ['status' => CatalogStatus::Active->value],
                newValues: ['status' => CatalogStatus::Inactive->value],
            );

            return $scheme;
        });
    }
}
