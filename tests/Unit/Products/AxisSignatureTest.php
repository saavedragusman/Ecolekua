<?php

use App\Support\Products\AxisSignature;

it('Decision 5 builds a SHA-256 signature of the sorted attributeId:valueId pairs', function () {
    $expected = hash('sha256', '1:100,1:101,2:200');

    expect(AxisSignature::of([1 => [100, 101], 2 => [200]]))->toBe($expected)
        ->and(strlen(AxisSignature::of([1 => [100]])))->toBe(64);
});

it('Decision 5 gives the same signature whatever the order of values and attributes', function () {
    $signature = AxisSignature::of([1 => [100, 101], 2 => [200]]);

    expect(AxisSignature::of([2 => [200], 1 => [101, 100]]))->toBe($signature);
});

it('Decision 5 gives different signatures when any pair differs', function () {
    expect(AxisSignature::of([1 => [100], 2 => [200]]))->not->toBe(AxisSignature::of([1 => [100], 2 => [201]]))
        ->and(AxisSignature::of([1 => [100]]))->not->toBe(AxisSignature::of([1 => [100, 101]]));
});

it('Decision 5 ignores a repeated value and gives a stable signature to a product without axes', function () {
    expect(AxisSignature::of([1 => [100, 100]]))->toBe(AxisSignature::of([1 => [100]]))
        ->and(AxisSignature::of([]))->toBe(hash('sha256', ''));
});
