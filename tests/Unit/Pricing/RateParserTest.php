<?php

use App\Support\Pricing\RateParser;

// DT-01 (design Decision 3) and DEC-COT-60: the API `promedio` is read from the raw JSON number token,
// never through a float, and rounded HALF_UP to 2 decimals. Anything that is not a positive number the
// rate history can store gives null.

it('DEC-COT-60 reads the promedio of E-63 rounded to two decimals', function () {
    expect(RateParser::promedio('{"promedio": 875.6505, "fechaActualizacion": "2026-10-09T00:00:00-04:00"}')->toString())->toBe('875.65');
});

it('DEC-COT-60 rounds a promedio with more than two decimals HALF_UP from the raw token', function (string $token, string $expected) {
    expect(RateParser::promedio('{"nombre":"Oficial","promedio":'.$token.'}')->toString())->toBe($expected);
})->with([
    'half goes up' => ['875.645', '875.65'],
    'below half goes down' => ['875.6449999999999999', '875.64'],
    'above half goes up' => ['875.6451', '875.65'],
    'beyond float precision' => ['36.124999999999999999', '36.12'],
]);

it('DEC-COT-60 pads an integer or short promedio to two decimals', function (string $token, string $expected) {
    expect(RateParser::promedio('{"promedio":'.$token.'}')->toString())->toBe($expected);
})->with([
    'integer' => ['875', '875.00'],
    'one decimal' => ['36.5', '36.50'],
    'exponent' => ['8.756505e2', '875.65'],
]);

it('DT-01 gives null for a missing, zero, negative or non-numeric promedio and for malformed JSON', function (string $body) {
    expect(RateParser::promedio($body))->toBeNull();
})->with([
    'missing' => ['{"compra": 870.1, "venta": 880.2}'],
    'null' => ['{"promedio": null}'],
    'zero' => ['{"promedio": 0}'],
    'rounds to zero' => ['{"promedio": 0.004}'],
    'negative' => ['{"promedio": -875.65}'],
    'string' => ['{"promedio": "875.6505"}'],
    'boolean' => ['{"promedio": true}'],
    'malformed json' => ['{"promedio": 875.6505'],
    'not an object' => ['[875.6505]'],
    'html error page' => ['<html>502 Bad Gateway</html>'],
    'empty body' => [''],
]);

it('DT-01 ignores a "promedio" that is only text inside another key', function () {
    expect(RateParser::promedio('{"\"promedio":5,"promedio":875.6505}')->toString())->toBe('875.65');
});

it('DT-01 takes the last of a repeated promedio key, as the JSON decoder does', function () {
    expect(RateParser::promedio('{"promedio":1,"promedio":875.6505}')->toString())->toBe('875.65');
});

it('DT-01 gives null for a promedio that does not fit the stored rate (more than 12 integer digits)', function (string $token, ?string $expected) {
    expect(RateParser::promedio('{"promedio":'.$token.'}')?->toString())->toBe($expected);
})->with([
    'largest storable' => ['999999999999.99', '999999999999.99'],
    'rounds over the limit' => ['999999999999.995', null],
    'thirteen digits' => ['1234567890123', null],
    'huge' => ['12345678901234.5', null],
]);

it('DT-01 reads the top-level promedio, not one nested in another object', function () {
    expect(RateParser::promedio('{"fuente":{"promedio": 1.5},"promedio": 875.6505}')->toString())->toBe('875.65');
});
