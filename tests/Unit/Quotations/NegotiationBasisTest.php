<?php

use App\Support\Quotations\Calculation\NegotiationBasis;

// DEC-COT-55, design Decision 17: what a negotiated price was negotiated for, the resolved combination and
// the distinct size groups of the line's rows. Quantities and sizes within a group are not part of it.

it('E-72 la base guarda la combinación y los grupos distintos ordenados, sin nulos', function () {
    $basis = NegotiationBasis::of(184, [11, null, 10, 11, 10]);

    expect($basis->combinationId)->toBe(184)
        ->and($basis->sizeGroupIds)->toBe([10, 11]);
});

it('E-72 una combinación sin grupos tiene la lista de grupos vacía', function () {
    expect(NegotiationBasis::of(110, [null, null])->sizeGroupIds)->toBe([]);
});

it('E-72 otra combinación no coincide con la base negociada', function () {
    expect(NegotiationBasis::of(110, [])->matches(NegotiationBasis::of(111, [])))->toBeFalse();
});

it('E-72 una fila en otro grupo de talla no coincide con la base negociada', function () {
    expect(NegotiationBasis::of(184, [10])->matches(NegotiationBasis::of(184, [10, 11])))->toBeFalse();
});

it('E-72 cambiar cantidades o tallas dentro del mismo grupo conserva la base', function () {
    $negotiated = NegotiationBasis::of(184, [10, 10, 11]);
    $current = NegotiationBasis::of(184, [11, 10]);

    expect($negotiated->matches($current))->toBeTrue()
        ->and($current->matches($negotiated))->toBeTrue();
});
