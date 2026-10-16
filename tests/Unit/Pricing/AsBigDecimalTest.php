<?php

use App\Casts\AsBigDecimal;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\RoundingNecessaryException;
use Illuminate\Database\Eloquent\Model;

// DT-01 (design Decision 3): a DECIMAL column is read as BigDecimal and written at its scale, never as float.

/**
 * A model with one amount column cast at scale 2, so the cast is exercised the way real models use it.
 */
function bigDecimalProbe(): Model
{
    return new class extends Model
    {
        protected $guarded = [];

        /**
         * @return array<string, string>
         */
        protected function casts(): array
        {
            return ['amount' => AsBigDecimal::class.':2'];
        }
    };
}

it('DT-01 reads a DECIMAL value as a BigDecimal at the column scale', function (string $stored, int $scale, string $expected) {
    $value = (new AsBigDecimal($scale))->get(bigDecimalProbe(), 'amount', $stored, []);

    expect($value)->toBeInstanceOf(BigDecimal::class)
        ->and($value->getScale())->toBe($scale)
        ->and($value->toString())->toBe($expected);
})->with([
    'price' => ['40.00', 2, '40.00'],
    'rate' => ['875.6505', 4, '875.6505'],
    'integer from the driver' => ['40', 2, '40.00'],
]);

it('DT-01 reads NULL as null', function () {
    expect((new AsBigDecimal(2))->get(bigDecimalProbe(), 'amount', null, []))->toBeNull();
});

it('DT-01 writes a string or an integer as the canonical string at the scale', function (string|int $input, string $expected) {
    expect((new AsBigDecimal(2))->set(bigDecimalProbe(), 'amount', $input, []))->toBe($expected);
})->with([
    'string' => ['36.5', '36.50'],
    'integer' => [32, '32.00'],
    'already at scale' => ['40.00', '40.00'],
]);

it('DT-01 writes a BigDecimal as the canonical string at the scale', function () {
    expect((new AsBigDecimal(2))->set(bigDecimalProbe(), 'amount', BigDecimal::of('41'), []))->toBe('41.00');
});

it('DT-01 writes null as null', function () {
    expect((new AsBigDecimal(2))->set(bigDecimalProbe(), 'amount', null, []))->toBeNull();
});

it('DT-01 throws instead of rounding when the value has more decimals than the scale', function () {
    expect(fn () => (new AsBigDecimal(2))->set(bigDecimalProbe(), 'amount', '40.005', []))
        ->toThrow(RoundingNecessaryException::class)
        ->and(fn () => (new AsBigDecimal(4))->set(bigDecimalProbe(), 'amount', BigDecimal::of('1.23456'), []))
        ->toThrow(RoundingNecessaryException::class);
});

it('DT-01 is usable as a model cast with a scale argument', function () {
    $model = bigDecimalProbe();
    $model->amount = '40';

    expect($model->amount)->toBeInstanceOf(BigDecimal::class)
        ->and($model->amount->toString())->toBe('40.00')
        ->and($model->getAttributes()['amount'])->toBe('40.00');
});
