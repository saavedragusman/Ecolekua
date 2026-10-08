<?php

use App\Enums\AttributePresentation;
use App\Enums\AttributeRole;
use App\Enums\AttributeSpecialUse;
use App\Support\Products\ProductRules;

/**
 * @return array{attribute_id: int, role: AttributeRole, presentation: AttributePresentation, special_use: AttributeSpecialUse|null}
 */
function declaredAttribute(int $id, AttributeRole $role, AttributePresentation $presentation = AttributePresentation::Text, ?AttributeSpecialUse $use = null): array
{
    return ['attribute_id' => $id, 'role' => $role, 'presentation' => $presentation, 'special_use' => $use];
}

it('DEC-PRD-50 returns the fabric attribute when it is declared as an order attribute', function () {
    $declared = [
        declaredAttribute(1, AttributeRole::Axis),
        declaredAttribute(2, AttributeRole::Order, use: AttributeSpecialUse::Fabric),
    ];

    expect(ProductRules::roleViolation($declared))->toBe(2);
});

it('DEC-PRD-50 returns the color attribute when it is declared as an axis', function () {
    $declared = [
        declaredAttribute(1, AttributeRole::Axis, use: AttributeSpecialUse::Fabric),
        declaredAttribute(3, AttributeRole::Axis, AttributePresentation::Color),
    ];

    expect(ProductRules::roleViolation($declared))->toBe(3);
});

it('DEC-PRD-50 returns the first offending attribute in declaration order', function () {
    $declared = [
        declaredAttribute(7, AttributeRole::Axis, AttributePresentation::Color),
        declaredAttribute(8, AttributeRole::Order, use: AttributeSpecialUse::Fabric),
    ];

    expect(ProductRules::roleViolation($declared))->toBe(7);
});

it('DEC-PRD-50 accepts fabric as an axis and color as an order attribute', function () {
    $declared = [
        declaredAttribute(1, AttributeRole::Axis, use: AttributeSpecialUse::Fabric),
        declaredAttribute(2, AttributeRole::Axis),
        declaredAttribute(3, AttributeRole::Order, use: AttributeSpecialUse::Size),
        declaredAttribute(4, AttributeRole::Order, AttributePresentation::Color),
    ];

    expect(ProductRules::roleViolation($declared))->toBeNull();
});

it('DEC-PRD-50 leaves every other attribute free to take either role and accepts an empty structure', function () {
    expect(ProductRules::roleViolation([]))->toBeNull()
        ->and(ProductRules::roleViolation([
            declaredAttribute(1, AttributeRole::Axis, use: AttributeSpecialUse::Gender),
            declaredAttribute(2, AttributeRole::Order, use: AttributeSpecialUse::Gender),
        ]))->toBeNull();
});
