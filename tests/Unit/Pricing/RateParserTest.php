<?php

use App\Support\Pricing\RateParser;

// DT-01 (design Decision 3): the API `promedio` is read from the raw JSON number token, never through a
// float, and rounded HALF_UP to 4 decimals. Anything that is not a positive number gives null.

it('DT-01 reads the promedio of E-63 exactly', function () {
    expect(RateParser::promedio('{"promedio": 875.6505, "fechaActualizacion": "2026-10-09T00:00:00-04:00"}')->toString())->toBe('875.6505');
});

it('DT-01 rounds a promedio with more than four decimals HALF_UP from the raw token', function (string $token, string $expected) {
    expect(RateParser::promedio('{"nombre":"Oficial","promedio":'.$token.'}')->toString())->toBe($expected);
})->with([
    'rounds up at 5' => ['875.65049999999999999', '875.6505'],
    'half goes up' => ['875.65055', '875.6506'],
    'below half goes down' => ['875.65054', '875.6505'],
    'beyond float precision' => ['36.123456789012345678', '36.1235'],
]);

it('DT-01 pads an integer or short promedio to four decimals', function (string $token, string $expected) {
    expect(RateParser::promedio('{"promedio":'.$token.'}')->toString())->toBe($expected);
})->with([
    'integer' => ['875', '875.0000'],
    'one decimal' => ['36.5', '36.5000'],
    'exponent' => ['8.756505e2', '875.6505'],
]);

it('DT-01 gives null for a missing, zero, negative or non-numeric promedio and for malformed JSON', function (string $body) {
    expect(RateParser::promedio($body))->toBeNull();
})->with([
    'missing' => ['{"compra": 870.1, "venta": 880.2}'],
    'null' => ['{"promedio": null}'],
    'zero' => ['{"promedio": 0}'],
    'zero with decimals' => ['{"promedio": 0.00001}'],
    'negative' => ['{"promedio": -875.65}'],
    'string' => ['{"promedio": "875.6505"}'],
    'boolean' => ['{"promedio": true}'],
    'malformed json' => ['{"promedio": 875.6505'],
    'not an object' => ['[875.6505]'],
    'html error page' => ['<html>502 Bad Gateway</html>'],
    'empty body' => [''],
]);

it('DT-01 ignores a "promedio" that is only text inside another key', function () {
    expect(RateParser::promedio('{"\"promedio":5,"promedio":875.6505}')->toString())->toBe('875.6505');
});

it('DT-01 takes the last of a repeated promedio key, as the JSON decoder does', function () {
    expect(RateParser::promedio('{"promedio":1,"promedio":875.6505}')->toString())->toBe('875.6505');
});

it('DT-01 gives null for a promedio that does not fit the stored rate (more than 10 integer digits)', function (string $token, ?string $expected) {
    expect(RateParser::promedio('{"promedio":'.$token.'}')?->toString())->toBe($expected);
})->with([
    'largest storable' => ['9999999999.9999', '9999999999.9999'],
    'rounds over the limit' => ['9999999999.99995', null],
    'eleven digits' => ['12345678901', null],
    'huge' => ['12345678901234.5', null],
]);

it('DT-01 reads the top-level promedio, not one nested in another object', function () {
    expect(RateParser::promedio('{"fuente":{"promedio": 1.5},"promedio": 875.6505}')->toString())->toBe('875.6505');
});
