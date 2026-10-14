<?php

use App\Support\Pricing\InvalidAmount;
use App\Support\Pricing\Money;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\RoundingNecessaryException;

// DT-01 (design Decision 3): exact money. Every amount is a BigDecimal, never a float.

it('DT-01 parses a comma or a dot as the decimal separator', function (string $input, int $scale, string $expected) {
    $amount = Money::parse($input, $scale);

    expect($amount)->toBeInstanceOf(BigDecimal::class)
        ->and(Money::toStorage($amount, $scale))->toBe($expected);
})->with([
    'comma' => ['40,00', 2, '40.00'],
    'dot' => ['40.00', 2, '40.00'],
    'one decimal' => ['40,5', 2, '40.50'],
    'integer' => ['40', 2, '40.00'],
    'surrounding spaces' => [' 12.34 ', 2, '12.34'],
    'rate with four decimals' => ['875,6505', 4, '875.6505'],
]);

it('DT-01 parses a negative amount and leaves the sign rule to the caller', function () {
    $amount = Money::parse('-5,00', 2);

    expect($amount->isNegative())->toBeTrue()
        ->and(Money::toStorage($amount, 2))->toBe('-5.00');
});

it('DT-01 rejects exponents, thousands separators, blanks and garbage', function (string $input) {
    Money::parse($input, 2);
})->with([
    'exponent' => '1e2',
    'upper exponent' => '1E2',
    'thousands with dot and comma' => '1.150,00',
    'thousands with comma and dot' => '1,150.00',
    'empty' => '',
    'blank' => '   ',
    'letters' => 'abc',
    'two separators' => '1.2.3',
    'trailing separator' => '40.',
    'leading separator' => ',5',
    'plus sign' => '+5',
])->throws(InvalidAmount::class);

it('DT-01 rejects more decimals than the maximum scale and accepts exactly the maximum', function () {
    expect(Money::toStorage(Money::parse('40,05', 2), 2))->toBe('40.05')
        ->and(Money::toStorage(Money::parse('40.0050', 4), 4))->toBe('40.0050');

    expect(fn () => Money::parse('40.005', 2))->toThrow(InvalidAmount::class)
        ->and(fn () => Money::parse('1.23456', 4))->toThrow(InvalidAmount::class);
});

it('DT-01 rounds half up to the requested scale', function (string $input, int $scale, string $expected) {
    expect(Money::toStorage(Money::round(BigDecimal::of($input), $scale), $scale))->toBe($expected);
})->with([
    'half rounds up' => ['0.125', 2, '0.13'],
    'below half rounds down' => ['0.124', 2, '0.12'],
    'default-size rate' => ['875.65055', 4, '875.6506'],
    'already exact' => ['10.50', 2, '10.50'],
    'to integer' => ['2.5', 0, '3'],
]);

it('DT-01 round defaults to two decimals', function () {
    expect(Money::round(BigDecimal::of('1.005'))->toString())->toBe('1.01');
});

it('DT-01 stores the canonical string at the requested scale and never rounds silently', function () {
    expect(Money::toStorage(BigDecimal::of('40'), 2))->toBe('40.00')
        ->and(Money::toStorage(BigDecimal::of('875.6505'), 4))->toBe('875.6505')
        ->and(Money::toStorage(BigDecimal::of('50'), 2))->toBe('50.00')
        ->and(fn () => Money::toStorage(BigDecimal::of('1.005'), 2))->toThrow(RoundingNecessaryException::class);
});

it('DT-01 displays amounts with the es-VE format', function (string $amount, int $scale, string $expected) {
    expect(Money::display($amount, $scale))->toBe($expected);
})->with([
    'thousands' => ['1150.00', 2, '1.150,00'],
    'millions' => ['1234567.50', 2, '1.234.567,50'],
    'below a thousand' => ['40.00', 2, '40,00'],
    'zero' => ['0.00', 2, '0,00'],
    'negative' => ['-1150.00', 2, '-1.150,00'],
    'rate' => ['875.6505', 4, '875,6505'],
    'no decimals' => ['1000', 0, '1.000'],
]);

it('DT-01 displays a BigDecimal the same way as its string', function () {
    expect(Money::display(BigDecimal::of('1150.5')))->toBe('1.150,50');
});

it('DT-01 computes a percentage of an amount exactly, without rounding', function (string $percent, string $amount, string $expected) {
    $result = Money::percentOf(BigDecimal::of($percent), BigDecimal::of($amount));

    expect($result->isEqualTo($expected))->toBeTrue()
        ->and($result->getScale())->toBeGreaterThanOrEqual(strlen(explode('.', $expected)[1] ?? ''));
})->with([
    'half' => ['50.00', '100.00', '50'],
    'keeps every decimal' => ['33.33', '10.01', '3.336333'],
    'zero percent' => ['0.00', '99.99', '0'],
    'full' => ['100.00', '12.34', '12.34'],
]);
