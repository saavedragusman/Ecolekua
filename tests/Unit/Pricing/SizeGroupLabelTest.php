<?php

use App\Support\Pricing\SizeGroupLabel;

// COT-004 (design Decision 10): the label of a size group is derived from its sizes, never stored.
// Runs are contiguous in the order of the sizes the combination admits (the palette), joined with
// "–"; runs are separated by ", ".

/**
 * Sizes 28 to 44 in steps of 2, keyed by value id (1 to 9), in size-value order.
 *
 * @return array<int, string>
 */
function labelPalette(): array
{
    return [1 => '28', 2 => '30', 3 => '32', 4 => '34', 5 => '36', 6 => '38', 7 => '40', 8 => '42', 9 => '44'];
}

it('DEC-COT-53 labels a single contiguous run as "28–36"', function () {
    expect(SizeGroupLabel::of([1, 2, 3, 4, 5], labelPalette()))->toBe('28–36')
        ->and(SizeGroupLabel::of([6, 7, 8, 9], labelPalette()))->toBe('38–44');
});

it('DEC-COT-53 separates runs with a comma when the palette has a gap inside the group', function () {
    expect(SizeGroupLabel::of([1, 2, 3, 5], labelPalette()))->toBe('28–32, 36')
        ->and(SizeGroupLabel::of([1, 3, 5, 9], labelPalette()))->toBe('28, 32, 36, 44')
        ->and(SizeGroupLabel::of([1, 2, 4, 5, 9], labelPalette()))->toBe('28–30, 34–36, 44');
});

it('DEC-COT-53 labels one size on its own and two neighbours as a range', function () {
    expect(SizeGroupLabel::of([5], labelPalette()))->toBe('36')
        ->and(SizeGroupLabel::of([1, 2], labelPalette()))->toBe('28–30');
});

it('DEC-COT-53 orders the sizes by the palette, not by the order they are given', function () {
    expect(SizeGroupLabel::of([5, 1, 3, 2], labelPalette()))->toBe('28–32, 36')
        ->and(SizeGroupLabel::of([9, 6, 8, 7], labelPalette()))->toBe('38–44');
});

it('DEC-COT-53 judges contiguity by the palette, so a size outside the group breaks the run', function () {
    // 38 and 44 are far apart in this palette: 40 and 42 admit no group member in between.
    $palette = [6 => '38', 7 => '40', 8 => '42', 9 => '44'];

    expect(SizeGroupLabel::of([6, 9], $palette))->toBe('38, 44')
        ->and(SizeGroupLabel::of([6, 7], $palette))->toBe('38–40');
});

it('DEC-COT-53 counts a repeated size once', function () {
    expect(SizeGroupLabel::of([1, 1, 2], labelPalette()))->toBe('28–30');
});

it('DEC-COT-53 uses the names as they are, so non numeric sizes get the same shape', function () {
    $palette = [10 => 'XS', 11 => 'S', 12 => 'M', 13 => 'L', 14 => 'XL'];

    expect(SizeGroupLabel::of([10, 11, 12], $palette))->toBe('XS–M')
        ->and(SizeGroupLabel::of([10, 12, 14], $palette))->toBe('XS, M, XL');
});

it('DEC-COT-53 refuses a size that is not in the palette and an empty group', function (array $ids) {
    expect(fn () => SizeGroupLabel::of($ids, labelPalette()))->toThrow(InvalidArgumentException::class);
})->with([
    'unknown size' => [[1, 99]],
    'empty group' => [[]],
]);
