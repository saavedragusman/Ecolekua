<?php

use App\Enums\AttributePresentation;
use App\Enums\AttributeRole;
use App\Enums\AttributeSpecialUse;
use App\Enums\BusinessLine;
use App\Enums\CatalogStatus;
use App\Enums\SupplyMode;

it('PRD-013 defines the shared catalog status with Spanish labels', function () {
    expect(CatalogStatus::Active->value)->toBe('active')
        ->and(CatalogStatus::Inactive->value)->toBe('inactive')
        ->and(CatalogStatus::Active->label())->toBe('Activo')
        ->and(CatalogStatus::Inactive->label())->toBe('Inactivo')
        ->and(CatalogStatus::cases())->toHaveCount(2);
});

it('DEC-PRD-26 defines the two business lines', function () {
    expect(array_map(fn (BusinessLine $case) => $case->value, BusinessLine::cases()))->toBe(['uniforms', 'diapers'])
        ->and(BusinessLine::Uniforms->label())->toBe('Uniformes')
        ->and(BusinessLine::Diapers->label())->toBe('Pañales');
});

it('DEC-PRD-09 defines the four supply modes with Spanish labels', function () {
    expect(array_map(fn (SupplyMode $case) => $case->value, SupplyMode::cases()))
        ->toBe(['on_demand', 'stock_with_minimum', 'stock_depletable', 'service'])
        ->and(SupplyMode::OnDemand->label())->toBe('Bajo pedido')
        ->and(SupplyMode::StockWithMinimum->label())->toBe('Stock con mínimo')
        ->and(SupplyMode::StockDepletable->label())->toBe('Stock agotable')
        ->and(SupplyMode::Service->label())->toBe('Servicio');
});

it('DEC-PRD-30 defines the three attribute presentations', function () {
    expect(array_map(fn (AttributePresentation $case) => $case->value, AttributePresentation::cases()))
        ->toBe(['text', 'image', 'color'])
        ->and(AttributePresentation::Text->label())->toBe('Texto')
        ->and(AttributePresentation::Image->label())->toBe('Imagen')
        ->and(AttributePresentation::Color->label())->toBe('Color');
});

it('DEC-PRD-03 defines the two product attribute roles', function () {
    expect(array_map(fn (AttributeRole $case) => $case->value, AttributeRole::cases()))->toBe(['axis', 'order'])
        ->and(AttributeRole::Axis->label())->toBe('Eje')
        ->and(AttributeRole::Order->label())->toBe('De pedido');
});

it('DEC-PRD-49 defines the three attribute special uses', function () {
    expect(array_map(fn (AttributeSpecialUse $case) => $case->value, AttributeSpecialUse::cases()))
        ->toBe(['fabric', 'size', 'gender'])
        ->and(AttributeSpecialUse::Fabric->label())->toBe('Tela')
        ->and(AttributeSpecialUse::Size->label())->toBe('Talla')
        ->and(AttributeSpecialUse::Gender->label())->toBe('Género');
});
