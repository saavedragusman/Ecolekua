<?php

use App\Support\Pricing\Money;
use Brick\Math\BigDecimal;
use PHPUnit\Framework\ExpectationFailedException;

// Self-test of the `toBeMoneyString()` expectation declared in tests/Pest.php (design Decision 3).

it('DT-01 toBeMoneyString accepts a canonical two-decimal string', function (string $value) {
    expect($value)->toBeMoneyString();
})->with(['40.00', '0.01', '1150.00', '99999999.99']);

it('DT-01 toBeMoneyString accepts the canonical string produced by Money::toStorage', function () {
    expect(Money::toStorage(BigDecimal::of('41'), 2))->toBeMoneyString();
});

it('DT-01 toBeMoneyString rejects numbers, wrong scales, separators and signs', function (mixed $value) {
    expect(fn () => expect($value)->toBeMoneyString())->toThrow(ExpectationFailedException::class);
})->with([
    'float' => [40.0],
    'integer' => [40],
    'null' => [null],
    'no decimals' => ['40'],
    'one decimal' => ['40.5'],
    'three decimals' => ['40.005'],
    'comma' => ['40,00'],
    'thousands' => ['1.150,00'],
    'negative' => ['-40.00'],
    'blank' => [''],
]);

it('DT-01 toBeMoneyString takes the scale for rates and percentages', function () {
    expect('875.6505')->toBeMoneyString(4)
        ->and('50.00')->toBeMoneyString(2)
        ->and(fn () => expect('875.65')->toBeMoneyString(4))->toThrow(ExpectationFailedException::class)
        ->and(fn () => expect('875.6505')->toBeMoneyString())->toThrow(ExpectationFailedException::class);
});
