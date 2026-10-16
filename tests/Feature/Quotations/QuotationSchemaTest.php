<?php

use App\Models\AttributeValue;
use App\Models\Combination;
use App\Models\Combo;
use App\Models\ComboComponent;
use App\Models\Customer;
use App\Models\Product;
use App\Models\SizeGroup;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

// Database guarantees of the quotation tables (COT-013, COT-014, COT-024, design Decision 4). Raw inserts,
// so the schema is tested without the models; setup rows are inserted outside every `toThrow`, so a missing
// table fails the test instead of passing it.

/**
 * @param  array<string, mixed>  $overrides
 */
function quoteRow(array $overrides = []): int
{
    static $number = 0;

    return DB::table('quotations')->insertGetId(array_merge([
        'number' => ++$number + 1000,
        'status' => 'draft',
        'origin' => 'advisor',
        'customer_id' => null,
        'prospect_name' => 'Prospecto ficticio',
        'created_by' => User::factory()->create()->id,
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));
}

/**
 * @param  array<string, mixed>  $overrides
 */
function quoteLineRow(int $quotationId, array $overrides = []): int
{
    return DB::table('quotation_lines')->insertGetId(array_merge([
        'quotation_id' => $quotationId,
        'position' => 1,
        'kind' => 'product',
        'product_id' => Product::factory()->create()->id,
        'combo_id' => null,
        'selection' => json_encode([]),
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));
}

/**
 * @param  array<string, mixed>  $overrides
 */
function quoteRowRow(int $lineId, array $overrides = []): int
{
    return DB::table('quotation_line_rows')->insertGetId(array_merge([
        'quotation_line_id' => $lineId,
        'size_value_id' => null,
        'quantity' => 1,
    ], $overrides));
}

/**
 * @param  array<string, mixed>  $overrides
 */
function quoteNegotiatedRow(int $lineId, array $overrides = []): int
{
    return DB::table('quotation_line_negotiated_prices')->insertGetId(array_merge([
        'quotation_line_id' => $lineId,
        'size_group_id' => null,
        'unit_price' => '29.00',
    ], $overrides));
}

it('COT-013 keeps the quotation number unique', function () {
    quoteRow(['number' => 123]);

    expect(fn () => quoteRow(['number' => 123]))->toThrow(UniqueConstraintViolationException::class);
});

it('COT-013 seeds the number sequence with a single row at zero', function () {
    expect(DB::table('quotation_sequences')->get(['id', 'last_number'])->map(fn ($row) => (array) $row)->all())
        ->toBe([['id' => 1, 'last_number' => 0]]);
});

it('COT-013 requires exactly one recipient: a customer or a prospect', function () {
    $customer = Customer::factory()->create();

    expect(quoteRow(['customer_id' => $customer->id, 'prospect_name' => null]))->toBeInt()
        ->and(quoteRow())->toBeInt()
        ->and(fn () => quoteRow(['customer_id' => null, 'prospect_name' => null]))->toThrow(QueryException::class)
        ->and(fn () => quoteRow(['customer_id' => $customer->id, 'prospect_name' => 'Los dos']))->toThrow(QueryException::class);
});

it('COT-013 a product line has a product and no combo, a combo line a combo and no product', function () {
    $quotation = quoteRow();
    $combo = Combo::factory()->create();
    $product = Product::factory()->create();

    expect(quoteLineRow($quotation, ['position' => 1, 'kind' => 'combo', 'product_id' => null, 'combo_id' => $combo->id]))->toBeInt()
        ->and(fn () => quoteLineRow($quotation, ['position' => 2, 'kind' => 'product', 'product_id' => null]))->toThrow(QueryException::class)
        ->and(fn () => quoteLineRow($quotation, ['position' => 3, 'kind' => 'combo', 'product_id' => $product->id, 'combo_id' => $combo->id]))->toThrow(QueryException::class)
        ->and(fn () => quoteLineRow($quotation, ['position' => 4, 'kind' => 'otro']))->toThrow(QueryException::class);
});

it('COT-013 keeps the line position unique within a quotation', function () {
    $quotation = quoteRow();
    quoteLineRow($quotation, ['position' => 1]);

    expect(quoteLineRow(quoteRow(), ['position' => 1]))->toBeInt()
        ->and(fn () => quoteLineRow($quotation, ['position' => 1]))->toThrow(UniqueConstraintViolationException::class);
});

it('COT-014 keeps one row per size in a line, including two rows without size', function () {
    $line = quoteLineRow(quoteRow());
    $size = AttributeValue::factory()->create();
    quoteRowRow($line, ['size_value_id' => $size->id]);
    quoteRowRow($line);

    expect(fn () => quoteRowRow($line, ['size_value_id' => $size->id]))->toThrow(UniqueConstraintViolationException::class)
        ->and(fn () => quoteRowRow($line))->toThrow(UniqueConstraintViolationException::class);
});

it('COT-014 requires a row quantity of at least one', function () {
    $line = quoteLineRow(quoteRow());

    expect(fn () => quoteRowRow($line, ['quantity' => 0]))->toThrow(QueryException::class);
});

it('COT-012 requires a positive negotiated price, one per size group of a line', function () {
    $line = quoteLineRow(quoteRow());
    $group = SizeGroup::factory()->create()->id;
    quoteNegotiatedRow($line);

    expect(fn () => quoteNegotiatedRow($line))->toThrow(UniqueConstraintViolationException::class)
        ->and(fn () => quoteNegotiatedRow($line, ['size_group_id' => $group, 'unit_price' => '0.00']))->toThrow(QueryException::class);
});

it('COT-024 restricts deleting a customer, product, combination, combo or service referenced by a quotation', function () {
    $customer = Customer::factory()->create();
    $quotation = quoteRow(['customer_id' => $customer->id, 'prospect_name' => null]);
    $combination = Combination::factory()->create();
    $service = Product::factory()->service()->create();
    $combo = Combo::factory()->create();
    $line = quoteLineRow($quotation, ['product_id' => $combination->product_id, 'combination_id' => $combination->id]);
    quoteLineRow($quotation, ['position' => 2, 'kind' => 'combo', 'product_id' => null, 'combo_id' => $combo->id]);
    DB::table('quotation_line_customizations')->insert(['quotation_line_id' => $line, 'service_product_id' => $service->id, 'quantity_per_garment' => 1]);

    expect(fn () => DB::table('customers')->where('id', $customer->id)->delete())->toThrow(QueryException::class)
        ->and(fn () => DB::table('combinations')->where('id', $combination->id)->delete())->toThrow(QueryException::class)
        ->and(fn () => DB::table('combos')->where('id', $combo->id)->delete())->toThrow(QueryException::class)
        ->and(fn () => DB::table('products')->where('id', $service->id)->delete())->toThrow(QueryException::class);
});

it('COT-024 restricts deleting a combination used only inside a combo line component (N-4)', function () {
    $combination = Combination::factory()->create();
    $line = quoteLineRow(quoteRow(), ['kind' => 'combo', 'product_id' => null, 'combo_id' => Combo::factory()->create()->id]);
    DB::table('quotation_line_components')->insert([
        'quotation_line_id' => $line,
        'combo_component_id' => null,
        'combination_id' => $combination->id,
        'position' => 1,
        'selection' => json_encode([]),
    ]);

    expect(fn () => DB::table('combinations')->where('id', $combination->id)->delete())->toThrow(QueryException::class);
});

it('COT-024 restricts deleting the product of a product line', function () {
    $product = Product::factory()->create();
    quoteLineRow(quoteRow(), ['product_id' => $product->id]);

    expect(fn () => DB::table('products')->where('id', $product->id)->delete())->toThrow(QueryException::class);
});

it('DEC-COT-55 restricts deleting the combination a negotiated price was negotiated for', function () {
    $combination = Combination::factory()->create();
    quoteLineRow(quoteRow(), ['negotiated_combination_id' => $combination->id]);

    expect(fn () => DB::table('combinations')->where('id', $combination->id)->delete())->toThrow(QueryException::class);
});

it('Decision 4 a negotiated price goes with its size group (CD-02)', function () {
    $group = SizeGroup::factory()->create();
    $line = quoteLineRow(quoteRow());
    quoteNegotiatedRow($line, ['size_group_id' => $group->id]);
    quoteNegotiatedRow($line);

    DB::table('size_groups')->where('id', $group->id)->delete();

    expect(DB::table('quotation_line_negotiated_prices')->pluck('size_group_id')->all())->toBe([null]);
});

it('Decision 4 deletes the lines, rows, customizations, components and negotiated prices with their quotation', function () {
    $quotation = quoteRow();
    $line = quoteLineRow($quotation);
    quoteRowRow($line);
    quoteNegotiatedRow($line);
    DB::table('quotation_line_customizations')->insert(['quotation_line_id' => $line, 'service_product_id' => Product::factory()->service()->create()->id, 'quantity_per_garment' => 2]);
    DB::table('quotation_line_components')->insert([
        'quotation_line_id' => $line,
        'combo_component_id' => ComboComponent::factory()->create()->id,
        'combination_id' => null,
        'position' => 1,
        'selection' => json_encode([]),
    ]);

    DB::table('quotations')->where('id', $quotation)->delete();

    expect(collect(['quotation_lines', 'quotation_line_rows', 'quotation_line_customizations', 'quotation_line_components', 'quotation_line_negotiated_prices'])
        ->mapWithKeys(fn (string $table) => [$table => DB::table($table)->count()])->all())
        ->toBe([
            'quotation_lines' => 0,
            'quotation_line_rows' => 0,
            'quotation_line_customizations' => 0,
            'quotation_line_components' => 0,
            'quotation_line_negotiated_prices' => 0,
        ]);
});

it('DT-01 stores the money columns of a quotation as exact decimals', function () {
    $quotation = quoteRow(['total' => '315234.00', 'rate_value' => '875.65', 'total_bs' => '275963645.10']);

    expect((array) DB::table('quotations')->where('id', $quotation)->first(['total', 'rate_value', 'total_bs']))
        ->toBe(['total' => '315234.00', 'rate_value' => '875.65', 'total_bs' => '275963645.10']);
});
