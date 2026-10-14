<?php

namespace App\Actions\Products;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AttributeRole;
use App\Enums\AttributeSpecialUse;
use App\Enums\AuditAction;
use App\Enums\SupplyMode;
use App\Models\Combination;
use App\Models\Product;
use App\Models\StockMinimumOverride;
use App\Models\User;
use App\Support\Products\StockMinimum;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Replaces the own minimum stock of the articles of a product (PRD-009, design Decision 13). The set
 * is rewritten in one transaction under the product lock, which serializes it with a mode change and
 * with a structure edit of the same product:
 *
 * - the product must be in `stock_with_minimum` (E-20); an empty list only clears;
 * - each combination belongs to the product and each article appears once;
 * - when the product declares the size-use attribute as an order attribute the size is required, one
 *   of its allowed values and inside the combination restriction; otherwise it must be absent (E-65).
 *
 * The audit row `products.stock_minimums_updated` carries the previous and new rows and is written
 * only when the set changed.
 */
class SyncStockMinimumOverrides
{
    public function __construct(private readonly RecordAuditEvent $audit) {}

    /**
     * @param  list<array{combination_id: int|string, size_value_id?: int|string|null, minimum: int|string}>  $rows  validated input
     *
     * @throws ValidationException when a rule rejects the set
     */
    public function handle(Product $product, array $rows, User $actor): Product
    {
        return DB::transaction(function () use ($product, $rows, $actor): Product {
            $product = Product::query()->lockForUpdate()->findOrFail($product->id);
            $rows = array_map(fn (array $row): array => [
                'combination_id' => (int) $row['combination_id'],
                'size_value_id' => ($row['size_value_id'] ?? null) === null ? null : (int) $row['size_value_id'],
                'minimum' => (int) $row['minimum'],
            ], $rows);

            $this->ensureMode($product, $rows);
            $this->ensureArticles($product, $rows);

            $before = StockMinimum::snapshot($product);
            StockMinimum::deleteOverrides($product);

            foreach ($rows as $row) {
                StockMinimumOverride::query()->create($row);
            }

            $after = StockMinimum::snapshot($product);

            if ($after !== $before) {
                $this->audit->handle(
                    AuditAction::ProductStockMinimumsUpdated,
                    $actor,
                    $product,
                    oldValues: ['stock_minimum_overrides' => $before],
                    newValues: ['stock_minimum_overrides' => $after],
                );
            }

            return $product;
        });
    }

    /**
     * @param  list<array{combination_id: int, size_value_id: int|null, minimum: int}>  $rows
     *
     * @throws ValidationException on `overrides`
     */
    private function ensureMode(Product $product, array $rows): void
    {
        if ($rows !== [] && $product->supply_mode !== SupplyMode::StockWithMinimum) {
            throw ValidationException::withMessages(['overrides' => __('validation.stock_minimum_mode')]);
        }
    }

    /**
     * @param  list<array{combination_id: int, size_value_id: int|null, minimum: int}>  $rows
     *
     * @throws ValidationException on `overrides.{i}.combination_id` or `overrides.{i}.size_value_id`
     */
    private function ensureArticles(Product $product, array $rows): void
    {
        $combinationIds = Combination::query()->where('product_id', $product->id)->pluck('id')->all();
        $sizeAttribute = $product->productAttributes()
            ->where('role', AttributeRole::Order->value)
            ->whereHas('catalogAttribute', fn ($query) => $query->where('special_use', AttributeSpecialUse::Size->value))
            ->with('allowedValues')
            ->first();
        $allowed = $sizeAttribute?->allowedValues->modelKeys() ?? [];
        $seen = [];

        foreach ($rows as $index => $row) {
            if (! in_array($row['combination_id'], $combinationIds, true)) {
                throw ValidationException::withMessages(["overrides.{$index}.combination_id" => __('validation.stock_minimum_combination_foreign')]);
            }

            $key = $row['combination_id'].':'.($row['size_value_id'] ?? 0);

            if (isset($seen[$key])) {
                throw ValidationException::withMessages(["overrides.{$index}.combination_id" => __('validation.stock_minimum_repeated')]);
            }

            $seen[$key] = true;

            if ($sizeAttribute === null) {
                if ($row['size_value_id'] !== null) {
                    throw ValidationException::withMessages(["overrides.{$index}.size_value_id" => __('validation.stock_minimum_size_unexpected')]);
                }

                continue;
            }

            if ($row['size_value_id'] === null) {
                throw ValidationException::withMessages(["overrides.{$index}.size_value_id" => __('validation.stock_minimum_size_required')]);
            }

            if (! in_array($row['size_value_id'], $allowed, true)) {
                throw ValidationException::withMessages(["overrides.{$index}.size_value_id" => __('validation.stock_minimum_size_not_allowed')]);
            }

            $restriction = DB::table('combination_values')
                ->where('combination_id', $row['combination_id'])
                ->where('catalog_attribute_id', $sizeAttribute->catalog_attribute_id)
                ->pluck('attribute_value_id')
                ->map(fn (mixed $id): int => (int) $id)
                ->all();

            if ($restriction !== [] && ! in_array($row['size_value_id'], $restriction, true)) {
                throw ValidationException::withMessages(["overrides.{$index}.size_value_id" => __('validation.stock_minimum_size_restricted')]);
            }
        }
    }
}
