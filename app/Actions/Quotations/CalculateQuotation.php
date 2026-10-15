<?php

namespace App\Actions\Quotations;

use App\Actions\Products\ResolveSelection;
use App\Enums\AttributeRole;
use App\Enums\AttributeSpecialUse;
use App\Enums\BusinessLine;
use App\Enums\CatalogStatus;
use App\Enums\LineKind;
use App\Enums\PriceOrigin;
use App\Enums\PriceSchemeType;
use App\Enums\PriceTierKind;
use App\Enums\SupplyMode;
use App\Models\PriceScheme;
use App\Models\PriceTier;
use App\Support\Products\Selection\CatalogSnapshotLoader;
use App\Support\Products\Selection\ResolvedCombo;
use App\Support\Products\Selection\ResolvedSelection;
use App\Support\Quotations\Calculation\CalculationInput;
use App\Support\Quotations\Calculation\CalculationResult;
use App\Support\Quotations\Calculation\LineFacts;
use App\Support\Quotations\Calculation\LineResult;
use App\Support\Quotations\Calculation\PieceCounter;
use App\Support\Quotations\Calculation\PriceBook;
use App\Support\Quotations\Calculation\RowResult;
use App\Support\Quotations\Calculation\TierFacts;
use App\Support\Quotations\Calculation\TierSelector;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Calculates a quotation's lines without writing anything (COT-009, DT-02, design Decisions 14-17): resolves
 * each size row with the 003 selection engine, counts the pieces of each business line and of each service,
 * picks the tier of each line, prices every row from its combination (or its size group) and every combo from
 * its item prices, and flags the lines that require an advisor.
 *
 * Business problems are returned as errors keyed by field (`lines.{i}` for the line, `lines.{i}.rows.{j}.` for
 * a row), never thrown, so a draft can be saved with them. The queries do not depend on the number of lines or
 * rows of the same product: the catalog loader remembers what it read and prices are read once.
 */
final class CalculateQuotation
{
    public function __construct(private readonly CatalogSnapshotLoader $loader) {}

    public function handle(CalculationInput $input): CalculationResult
    {
        $loader = $this->loader->memoized();
        $resolver = new ResolveSelection($loader);

        $productIds = [];
        $comboIds = [];

        foreach ($input->lines as $line) {
            if ($line['kind'] === LineKind::Product && $line['product_id'] !== null) {
                $productIds[] = $line['product_id'];
            } elseif ($line['combo_id'] !== null) {
                $comboIds[] = $line['combo_id'];
            }
        }

        $loader->forProducts($productIds);
        $facts = $this->productFacts($productIds);

        $rows = [];
        $combos = [];
        $errors = [];

        foreach ($input->lines as $i => $line) {
            if ($line['kind'] === LineKind::Combo) {
                [$combos[$i], $errors[$i]] = $this->resolveCombo($resolver, $line, $i);
            } else {
                [$rows[$i], $errors[$i]] = $this->resolveRows($resolver, $loader, $line, $i);
            }
        }

        $book = PriceBook::load($this->combinationIds($rows), $comboIds);
        $count = PieceCounter::count($this->lineFacts($input, $facts, $errors));
        $lines = [];

        foreach ($input->lines as $i => $line) {
            $lines[] = $line['kind'] === LineKind::Combo
                ? $this->priceCombo($line, $combos[$i] ?? null, $errors[$i], $book, $i)
                : $this->priceProduct($line, $rows[$i] ?? [], $errors[$i], $facts[(int) $line['product_id']] ?? null, $count, $book, $i);
        }

        return new CalculationResult($lines, $count['lines']);
    }

    /**
     * Business line, supply mode and active scheme (with its tiers in order) of each line product; an inactive
     * scheme is no scheme (COT-025, DEC-COT-62).
     *
     * @param  list<int>  $productIds
     * @return array<int, array{business_line: BusinessLine, service: bool, scheme: ?PriceScheme}>
     */
    private function productFacts(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        $products = DB::table('products')->whereIn('id', array_values(array_unique($productIds)))->get(['id', 'business_line', 'supply_mode', 'price_scheme_id']);
        $schemeIds = $products->pluck('price_scheme_id')->filter()->unique()->values()->all();
        $schemes = $schemeIds === [] ? collect() : PriceScheme::query()
            ->with(['tiers' => fn ($tiers) => $tiers->orderBy('sort_order')])
            ->whereIn('id', $schemeIds)
            ->get()
            ->keyBy('id');

        $facts = [];

        foreach ($products as $product) {
            $scheme = $product->price_scheme_id === null ? null : $schemes->get((int) $product->price_scheme_id);

            $facts[(int) $product->id] = [
                'business_line' => BusinessLine::from((string) $product->business_line),
                'service' => $product->supply_mode === SupplyMode::Service->value,
                'scheme' => $scheme instanceof PriceScheme && $scheme->status === CatalogStatus::Active ? $scheme : null,
            ];
        }

        return $facts;
    }

    /**
     * Resolves each size row of a product line: the size is injected as the order choice of the product's size
     * attribute (COT-009 step 1, one call per row).
     *
     * @param  array<string, mixed>  $line
     * @return array{0: list<?ResolvedSelection>, 1: array<string, string>}
     */
    private function resolveRows(ResolveSelection $resolver, CatalogSnapshotLoader $loader, array $line, int $i): array
    {
        $snapshot = $line['product_id'] === null ? null : $loader->forProduct($line['product_id']);
        $sizeAttributeId = null;

        foreach ($snapshot->attributes ?? [] as $attribute) {
            if ($attribute->specialUse === AttributeSpecialUse::Size && $attribute->role === AttributeRole::Order) {
                $sizeAttributeId = $attribute->id;
            }
        }

        $base = [
            ...$line['selection'],
            'product_id' => $line['product_id'],
            'customizations' => array_map(fn (array $customization): int => $customization['service_product_id'], $line['customizations']),
        ];

        $resolved = [];
        $errors = [];

        foreach ($line['rows'] as $j => $row) {
            $choice = $base;

            if ($sizeAttributeId !== null && $row['size_value_id'] !== null) {
                $choice['order'] = (is_array($choice['order'] ?? null) ? $choice['order'] : []);
                $choice['order'][$sizeAttributeId] = $row['size_value_id'];
            }

            try {
                $selection = $resolver->handle($choice);
                $resolved[] = $selection instanceof ResolvedSelection ? $selection : null;
            } catch (ValidationException $exception) {
                $resolved[] = null;
                $errors += self::prefixed($exception, "lines.{$i}.rows.{$j}.");
            }
        }

        return [$resolved, $errors];
    }

    /**
     * @param  array<string, mixed>  $line
     * @return array{0: ?ResolvedCombo, 1: array<string, string>}
     */
    private function resolveCombo(ResolveSelection $resolver, array $line, int $i): array
    {
        try {
            $combo = $resolver->handle(['combo_id' => $line['combo_id'], 'components' => $line['components']]);

            return [$combo instanceof ResolvedCombo ? $combo : null, []];
        } catch (ValidationException $exception) {
            return [null, self::prefixed($exception, "lines.{$i}.")];
        }
    }

    /**
     * @param  array<int, list<ResolvedSelection|null>>  $rows  resolved rows by line
     * @return list<int>
     */
    private function combinationIds(array $rows): array
    {
        $ids = [];

        foreach ($rows as $line) {
            foreach ($line as $row) {
                if ($row !== null) {
                    $ids[] = $row->combinationId;
                }
            }
        }

        return $ids;
    }

    /**
     * @param  array<int, array{business_line: BusinessLine, service: bool, scheme: ?PriceScheme}>  $facts
     * @param  array<int, array<string, string>>  $errors
     * @return list<LineFacts>
     */
    private function lineFacts(CalculationInput $input, array $facts, array $errors): array
    {
        $lines = [];

        foreach ($input->lines as $i => $line) {
            $product = $line['product_id'] === null ? null : ($facts[$line['product_id']] ?? null);
            $rows = [];

            foreach ($line['rows'] as $j => $row) {
                $rows[] = ['quantity' => $row['quantity'], 'valid' => ! self::rowHasError($errors[$i], $i, $j)];
            }

            $lines[] = new LineFacts(
                kind: $line['kind'],
                productId: $line['product_id'],
                businessLine: $product['business_line'] ?? null,
                isService: $product['service'] ?? false,
                schemeType: ($product['scheme'] ?? null)?->type,
                rows: $rows,
            );
        }

        return $lines;
    }

    /**
     * Prices a product line (COT-009 steps 3-8): the tier from the counted pieces, then every valid row from its
     * combination and size group. A negotiation tier leaves the line without automatic price and requiring an
     * advisor; a custom color keeps the tier price as a reference (COT-012).
     *
     * @param  array<string, mixed>  $line
     * @param  list<?ResolvedSelection>  $resolved
     * @param  array<string, string>  $errors
     * @param  array{business_line: BusinessLine, service: bool, scheme: ?PriceScheme}|null  $facts
     * @param  array{lines: array<string, int>, services: array<int, int>}  $count
     */
    private function priceProduct(array $line, array $resolved, array $errors, ?array $facts, array $count, PriceBook $book, int $i): LineResult
    {
        $first = null;

        foreach ($resolved as $selection) {
            $first ??= $selection;
        }

        $scheme = $facts['scheme'] ?? null;
        $quantity = $facts === null ? 0 : ($facts['service']
            ? ($count['services'][(int) $line['product_id']] ?? 0)
            : ($count['lines'][$facts['business_line']->value] ?? 0));

        $tier = $scheme === null ? null : TierSelector::for(
            array_values($scheme->tiers->map(fn (PriceTier $tier): TierFacts => new TierFacts($tier->id, $tier->label, (int) $tier->min_quantity, $tier->max_quantity === null ? null : (int) $tier->max_quantity, $tier->kind))->all()),
            $quantity,
        );

        $negotiation = $tier?->kind === PriceTierKind::Negotiation;
        $customColor = array_filter($resolved, fn (?ResolvedSelection $selection): bool => $selection?->requiresAdvisor === true) !== [];
        $reason = match (true) {
            $negotiation && $facts !== null => $facts['service']
                ? self::message('calculation_advisor_service', ['min' => $tier->min, 'service' => mb_strtolower($first->productName ?? '')])
                : self::message('calculation_advisor_goods', ['min' => $tier->min, 'line' => mb_strtolower($facts['business_line']->label())]),
            $customColor => self::message('calculation_advisor_custom_color'),
            default => null,
        };

        $rows = [];
        $unpriced = $scheme === null || $tier === null;

        foreach ($line['rows'] as $j => $row) {
            $selection = $resolved[$j] ?? null;

            if ($selection?->combinationId === null || $tier === null || $negotiation) {
                $rows[] = new RowResult($row['size_value_id'], $row['quantity']);

                continue;
            }

            $groupId = $book->hasSizeGroups($selection->combinationId) ? $book->groupOf($selection->combinationId, $row['size_value_id']) : null;
            $price = $book->hasSizeGroups($selection->combinationId) && $groupId === null
                ? null
                : $book->price($selection->combinationId, $tier->id, $groupId);

            $unpriced = $unpriced || $price === null;
            $rows[] = new RowResult($row['size_value_id'], $row['quantity'], $price, $groupId === null ? null : $book->groupLabel($groupId));
        }

        if ($unpriced && ! $negotiation) {
            $errors = ["lines.{$i}" => self::message('calculation_unpriced'), ...$errors];
        }

        $subtotal = null;

        if ($errors === [] && ! $negotiation) {
            $subtotal = BigDecimal::zero();

            foreach ($rows as $rowResult) {
                $subtotal = $subtotal->plus($rowResult->subtotal() ?? BigDecimal::zero());
            }
        }

        $perGarment = [];

        foreach ($line['customizations'] as $customization) {
            $perGarment[$customization['service_product_id']] = $customization['quantity_per_garment'];
        }

        return new LineResult(
            kind: LineKind::Product,
            code: $first?->code,
            descriptiveName: $first?->descriptiveName,
            tierLabel: $tier?->label,
            priceOrigin: $negotiation || $scheme === null ? null : ($scheme->type === PriceSchemeType::Fixed ? PriceOrigin::Fixed : PriceOrigin::Tier),
            rows: $rows,
            unitPrice: null,
            subtotal: $subtotal,
            customizations: array_map(fn (array $service): array => [
                'id' => (int) $service['id'],
                'name' => (string) $service['name'],
                'quantity_per_garment' => $perGarment[(int) $service['id']] ?? 1,
            ], $first->customizations ?? []),
            includedCustomizations: array_map(fn (array $service): array => ['id' => (int) $service['id'], 'name' => (string) $service['name']], $first->includedCustomizations ?? []),
            requiresAdvisor: $reason !== null,
            reason: $reason,
            errors: $errors,
        );
    }

    /**
     * Prices a combo line from the item prices of its components (COT-005, COT-009 step 6). Combos never
     * require an advisor (DEC-PRD-87).
     *
     * @param  array<string, mixed>  $line
     * @param  array<string, string>  $errors
     */
    private function priceCombo(array $line, ?ResolvedCombo $combo, array $errors, PriceBook $book, int $i): LineResult
    {
        $unitPrice = null;

        if ($combo !== null) {
            $unitPrice = BigDecimal::zero();

            foreach ($combo->components as $component) {
                $itemPrice = $book->itemPrice((int) $component['component_id']);

                if ($itemPrice === null) {
                    $unitPrice = null;
                    $errors = ["lines.{$i}" => self::message('calculation_unpriced'), ...$errors];

                    break;
                }

                $unitPrice = $unitPrice->plus($itemPrice->multipliedBy((int) $component['quantity']));
            }
        }

        return new LineResult(
            kind: LineKind::Combo,
            code: $combo?->code,
            descriptiveName: $combo?->name,
            tierLabel: null,
            priceOrigin: PriceOrigin::Combo,
            rows: [],
            unitPrice: $unitPrice,
            subtotal: $errors === [] ? $unitPrice?->multipliedBy($line['combo_quantity']) : null,
            customizations: [],
            includedCustomizations: [],
            requiresAdvisor: false,
            reason: null,
            errors: $errors,
        );
    }

    /**
     * @param  array<string, string>  $errors
     */
    private static function rowHasError(array $errors, int $i, int $j): bool
    {
        foreach (array_keys($errors) as $key) {
            if (str_starts_with($key, "lines.{$i}.rows.{$j}.")) {
                return true;
            }
        }

        return false;
    }

    /**
     * A message of `lang/es/validation.php`; the key itself when it is not a single text.
     *
     * @param  array<string, int|string>  $replace
     */
    private static function message(string $key, array $replace = []): string
    {
        $text = __("validation.{$key}", $replace);

        return is_string($text) ? $text : $key;
    }

    /**
     * @return array<string, string>
     */
    private static function prefixed(ValidationException $exception, string $prefix): array
    {
        $errors = [];

        foreach ($exception->errors() as $field => $messages) {
            $errors[$prefix.$field] = (string) ($messages[0] ?? '');
        }

        return $errors;
    }
}
