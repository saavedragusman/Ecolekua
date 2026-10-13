<?php

use App\Support\Products\CombinationOverlap;

// Axes are `attributeId => valueIds`; attribute 1 = Tela, 2 = Género in these tests.

it('DEC-PRD-39 reports the first combination whose value sets intersect on every axis', function () {
    $others = [
        10 => [1 => [100], 2 => [200]],
        11 => [1 => [101, 102], 2 => [201]],
    ];

    expect(CombinationOverlap::firstOverlap([1 => [102, 103], 2 => [201, 202]], $others))->toBe(11);
});

it('DEC-PRD-39 detects an overlap with multi-valued axes sharing one value per axis (E-48)', function () {
    // 159-1: Tela Drill or Gabardina, Género Caballero. Candidate: Tela Drill, Género Caballero.
    $others = [159 => [1 => [100, 101], 2 => [200]]];

    expect(CombinationOverlap::firstOverlap([1 => [100], 2 => [200]], $others))->toBe(159);
});

it('DEC-PRD-39 finds no overlap when one axis is disjoint', function () {
    $others = [159 => [1 => [100, 101], 2 => [200]]];

    expect(CombinationOverlap::firstOverlap([1 => [101], 2 => [201]], $others))->toBeNull();
});

it('DEC-PRD-39 finds no overlap when every axis differs or there are no other combinations', function () {
    expect(CombinationOverlap::firstOverlap([1 => [100], 2 => [200]], [5 => [1 => [101], 2 => [201]]]))->toBeNull()
        ->and(CombinationOverlap::firstOverlap([1 => [100], 2 => [200]], []))->toBeNull();
});

it('DEC-PRD-39 treats a missing axis on the other combination as no shared value', function () {
    expect(CombinationOverlap::firstOverlap([1 => [100], 2 => [200]], [5 => [1 => [100]]]))->toBeNull();
});

it('N-2 overlaps vacuously on a product without axes, so it admits one active combination', function () {
    expect(CombinationOverlap::firstOverlap([], [7 => []]))->toBe(7)
        ->and(CombinationOverlap::firstOverlap([], []))->toBeNull();
});

it('DEC-PRD-39 accepts any iterable of other combinations and keeps their order', function () {
    $others = (function (): Generator {
        yield 3 => [1 => [100]];
        yield 4 => [1 => [100]];
    })();

    expect(CombinationOverlap::firstOverlap([1 => [100]], $others))->toBe(3);
});
