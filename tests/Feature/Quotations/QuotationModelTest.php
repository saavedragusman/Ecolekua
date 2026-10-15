<?php

use App\Enums\LineKind;
use App\Enums\QuotationOrigin;
use App\Enums\QuotationStatus;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\QuotationLine;
use App\Models\QuotationLineComponent;
use App\Models\QuotationLineRow;
use Brick\Math\BigDecimal;

// The quotation models over the schema of design Decision 4 (COT-013, COT-014): number display, casts,
// relations and the fictitious factories later phases build on.

it('COT-013 shows the number with the COT prefix and six digits', function () {
    expect(Quotation::factory()->create(['number' => 123])->displayNumber())->toBe('COT-000123')
        ->and(Quotation::factory()->create(['number' => 1234567])->displayNumber())->toBe('COT-1234567');
});

it('COT-013 a quotation is a draft for a customer by default, or for a prospect', function () {
    $draft = Quotation::factory()->create();
    $prospect = Quotation::factory()->prospect()->create();

    expect($draft->status)->toBe(QuotationStatus::Draft)
        ->and($draft->origin)->toBe(QuotationOrigin::Advisor)
        ->and($draft->customer)->toBeInstanceOf(Customer::class)
        ->and($draft->prospect_name)->toBeNull()
        ->and($prospect->customer_id)->toBeNull()
        ->and($prospect->prospect_name)->not->toBeNull()
        ->and($prospect->prospect_phone)->toStartWith('+58');
});

it('COT-016 the issued and expired states carry their dates', function () {
    $issued = Quotation::factory()->issued()->create();
    $expired = Quotation::factory()->expired()->create();

    expect($issued->status)->toBe(QuotationStatus::Issued)
        ->and($issued->issued_at)->not->toBeNull()
        ->and($issued->expires_on)->not->toBeNull()
        ->and($expired->status)->toBe(QuotationStatus::Expired)
        ->and($expired->expired_at)->not->toBeNull();
});

it('DT-01 reads the money columns as exact decimals', function () {
    $quotation = Quotation::factory()->create(['total' => '360.00', 'rate_value' => '875.65', 'total_bs' => '315234.00']);

    expect($quotation->fresh()->total)->toBeInstanceOf(BigDecimal::class)
        ->and((string) $quotation->fresh()->total)->toBe('360.00')
        ->and((string) $quotation->fresh()->rate_value)->toBe('875.65')
        ->and((string) $quotation->fresh()->total_bs)->toBe('315234.00');
});

it('COT-014 a quotation has its lines in order, each with rows, components and added customizations', function () {
    $quotation = Quotation::factory()->create();
    $second = QuotationLine::factory()->for($quotation)->create(['position' => 2]);
    $first = QuotationLine::factory()->for($quotation)->create(['position' => 1, 'selection' => ['axes' => [5 => 9]]]);
    QuotationLineRow::factory()->for($first, 'line')->create(['quantity' => 3]);
    $service = Product::factory()->service()->create();
    $first->customizations()->attach($service->id, ['quantity_per_garment' => 2]);
    $combo = QuotationLine::factory()->combo()->for($quotation)->create(['position' => 3]);
    QuotationLineComponent::factory()->for($combo, 'line')->create(['position' => 1]);

    $lines = $quotation->fresh()->lines;

    expect($lines->pluck('id')->all())->toBe([$first->id, $second->id, $combo->id])
        ->and($lines[0]->kind)->toBe(LineKind::Product)
        ->and($lines[0]->selection)->toBe(['axes' => [5 => 9]])
        ->and($lines[0]->rows->sole()->quantity)->toBe(3)
        ->and($lines[0]->customizations->sole()->pivot->quantity_per_garment)->toBe(2)
        ->and($lines[2]->kind)->toBe(LineKind::Combo)
        ->and($lines[2]->components)->toHaveCount(1)
        ->and($lines[2]->product_id)->toBeNull();
});

it('COT-020 a customer lists its quotations', function () {
    $customer = Customer::factory()->create();
    $quotation = Quotation::factory()->for($customer)->create();

    expect($customer->quotations->pluck('id')->all())->toBe([$quotation->id]);
});
