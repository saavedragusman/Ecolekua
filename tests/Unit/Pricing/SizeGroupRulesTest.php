<?php

use App\Support\Pricing\SizeGroupRules;

// COT-004, E-08 (design Decision 10): the groups must partition the sizes the combination admits.
// Pure: the Actions pass the admitted sizes (id => name, in size order) and the proposed groups.

/**
 * Sizes 28 to 44 in steps of 2, keyed by value id, as `184` admits them.
 *
 * @return array<int, string>
 */
function rulesAdmitted(): array
{
    return [1 => '28', 2 => '30', 3 => '32', 4 => '34', 5 => '36', 6 => '38', 7 => '40', 8 => '42', 9 => '44'];
}

it('E-08 accepts groups 28–36 and 38–44 that partition the admitted sizes', function () {
    expect(SizeGroupRules::validate([[1, 2, 3, 4, 5], [6, 7, 8, 9]], rulesAdmitted()))->toBe([])
        ->and(SizeGroupRules::validate([[9, 8, 7, 6], [5, 4, 3, 2, 1]], rulesAdmitted()))->toBe([])
        ->and(SizeGroupRules::validate([[1, 2, 3, 4, 5, 6, 7, 8, 9]], rulesAdmitted()))->toBe([]);
});

it('E-08 accepts an empty list: it removes the grouping', function () {
    expect(SizeGroupRules::validate([], rulesAdmitted()))->toBe([]);
});

it('E-08 rejects a group that repeats 36 and names the size on groups', function () {
    $errors = SizeGroupRules::validate([[1, 2, 3, 4, 5], [5, 6, 7, 8, 9]], rulesAdmitted());

    expect($errors)->toHaveKey('groups')
        ->and($errors['groups'])->toContain('36')->toContain('más de un grupo')
        ->and($errors)->toHaveCount(1);
});

it('E-08 rejects groups that leave 44 without a group and names the size on groups', function () {
    $errors = SizeGroupRules::validate([[1, 2, 3, 4, 5], [6, 7, 8]], rulesAdmitted());

    expect($errors)->toHaveKey('groups')
        ->and($errors['groups'])->toContain('44')->toContain('sin grupo')
        ->and($errors)->toHaveCount(1);
});

it('E-08 reports the sizes left out in the order of the admitted sizes', function () {
    $errors = SizeGroupRules::validate([[1, 2, 3], [4]], rulesAdmitted());

    expect($errors['groups'])->toContain('36, 38, 40, 42, 44');
});

it('E-08 reports a repeated size and a size left out together in one message', function () {
    $errors = SizeGroupRules::validate([[1, 2, 3, 4, 5], [5, 6, 7, 8]], rulesAdmitted());

    expect($errors['groups'])->toContain('36')->toContain('44')
        ->and($errors['groups'])->toContain('más de un grupo')->toContain('sin grupo');
});

it('E-08 rejects a size repeated inside the same group as a repeated size', function () {
    $errors = SizeGroupRules::validate([[1, 1, 2, 3, 4, 5], [6, 7, 8, 9]], rulesAdmitted());

    expect($errors['groups'])->toContain('28')->toContain('más de un grupo');
});

it('E-08 rejects an empty group on groups.{i}', function () {
    $errors = SizeGroupRules::validate([[1, 2, 3, 4, 5], [], [6, 7, 8, 9]], rulesAdmitted());

    expect($errors)->toHaveKey('groups.1')
        ->and($errors)->not->toHaveKey('groups')
        ->and($errors['groups.1'])->toContain('vacío');
});

it('E-08 rejects a size the combination does not admit on the group that holds it', function () {
    $errors = SizeGroupRules::validate([[1, 2, 3, 4, 5, 99], [6, 7, 8, 9]], rulesAdmitted());

    expect($errors)->toHaveKey('groups.0')
        ->and($errors)->not->toHaveKey('groups.1')
        ->and($errors['groups.0'])->toContain('no pertenece a la combinación');
});
