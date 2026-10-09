<?php

use App\Support\Products\ComboMembership;

// Restrictions and axes are `attributeId => valueIds`; attribute 1 = Talla, 2 = Género in these tests.

it('DEC-PRD-42 includes a combination when every axis is unrestricted', function () {
    expect(ComboMembership::includes([], [1 => [10], 2 => [20]], [1, 2]))->toBeTrue()
        ->and(ComboMembership::includes([1 => []], [1 => [10]], [1]))->toBeTrue();
});

it('DEC-PRD-42 includes a combination that shares a value with the component subset on every restricted axis', function () {
    expect(ComboMembership::includes([1 => [10, 11], 2 => [20]], [1 => [11, 12], 2 => [20, 21]], [1, 2]))->toBeTrue();
});

it('DEC-PRD-42 leaves out a combination that shares no value on one restricted axis', function () {
    expect(ComboMembership::includes([1 => [10], 2 => [20]], [1 => [10], 2 => [21]], [1, 2]))->toBeFalse()
        ->and(ComboMembership::includes([1 => [10]], [1 => [11]], [1]))->toBeFalse();
});

it('DEC-PRD-42 mixes a restricted axis with an unrestricted one', function () {
    expect(ComboMembership::includes([1 => [10]], [1 => [10], 2 => [99]], [1, 2]))->toBeTrue()
        ->and(ComboMembership::includes([1 => [10]], [1 => [11], 2 => [20]], [1, 2]))->toBeFalse();
});

it('DEC-PRD-42 looks only at the axes of the product, not at the restrictions on order attributes', function () {
    // Attribute 3 (color, an order attribute) is restricted by the component but is not an axis.
    expect(ComboMembership::includes([3 => [30]], [1 => [10]], [1]))->toBeTrue();
});

it('DEC-PRD-42 leaves out a combination with no value on a restricted axis', function () {
    expect(ComboMembership::includes([1 => [10]], [], [1]))->toBeFalse();
});

it('DEC-PRD-42 includes every combination of a product without axes', function () {
    expect(ComboMembership::includes([1 => [10]], [], []))->toBeTrue();
});
