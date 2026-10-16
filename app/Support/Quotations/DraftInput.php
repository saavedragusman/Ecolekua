<?php

namespace App\Support\Quotations;

use App\Enums\LineKind;
use App\Models\Quotation;
use App\Models\QuotationLine;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * The calculation input of a stored draft (COT-013, design Decision 17): every line as it was saved, in order,
 * with its id and, when it has one, its stored negotiated price and the basis it was negotiated for. The draft
 * save, the negotiation and the open recalculation take the negotiated price from here, never from a request.
 */
final class DraftInput
{
    /**
     * @return array{lines: list<array<string, mixed>>}
     */
    public static function from(Quotation $quotation): array
    {
        $quotation->loadMissing(['lines.rows', 'lines.components']);
        $lineIds = $quotation->lines->pluck('id')->all();

        $prices = DB::table('quotation_line_negotiated_prices')
            ->whereIn('quotation_line_id', $lineIds)
            ->orderBy('id')
            ->get(['quotation_line_id', 'size_group_id', 'unit_price'])
            ->groupBy('quotation_line_id');

        $customizations = DB::table('quotation_line_customizations')
            ->whereIn('quotation_line_id', $lineIds)
            ->orderBy('service_product_id')
            ->get(['quotation_line_id', 'service_product_id', 'quantity_per_garment'])
            ->groupBy('quotation_line_id');

        return ['lines' => array_values($quotation->lines->map(fn (QuotationLine $line): array => [
            'id' => $line->id,
            ...self::line($line, array_values(($customizations[$line->id] ?? collect())->map(fn (stdClass $row): array => [
                'service_product_id' => (int) $row->service_product_id,
                'quantity_per_garment' => (int) $row->quantity_per_garment,
            ])->all())),
            'negotiated' => isset($prices[$line->id]) ? [
                'prices' => array_values($prices[$line->id]->map(fn (stdClass $price): array => [
                    'size_group_id' => $price->size_group_id === null ? null : (int) $price->size_group_id,
                    'price' => (string) $price->unit_price,
                ])->all()),
                'confirmed' => $line->negotiation_confirmed,
                'basis' => $line->negotiated_combination_id === null ? null : [
                    'combination_id' => $line->negotiated_combination_id,
                    'size_group_ids' => $line->negotiated_size_group_ids ?? [],
                ],
            ] : null,
        ])->all())];
    }

    /**
     * The input of one stored line, without negotiation.
     *
     * @param  list<array{service_product_id: int, quantity_per_garment: int}>  $customizations
     * @return array<string, mixed>
     */
    private static function line(QuotationLine $line, array $customizations): array
    {
        if ($line->kind === LineKind::Combo) {
            $components = [];

            foreach ($line->components as $component) {
                if ($component->combo_component_id !== null) {
                    $components[$component->combo_component_id] = $component->selection;
                }
            }

            return ['kind' => 'combo', 'combo_id' => $line->combo_id, 'combo_quantity' => $line->combo_quantity, 'components' => $components, 'note' => $line->note];
        }

        return [
            'kind' => 'product',
            'product_id' => $line->product_id,
            'selection' => $line->selection,
            'rows' => array_values($line->rows->map(fn ($row): array => ['size_value_id' => $row->size_value_id, 'quantity' => $row->quantity])->all()),
            'customizations' => $customizations,
            'note' => $line->note,
        ];
    }
}
