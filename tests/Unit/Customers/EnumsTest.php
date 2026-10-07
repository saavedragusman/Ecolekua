<?php

use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Enums\DocumentType;
use App\Enums\VenezuelanState;

it('DEC-CLI-01 labels the customer types in Spanish', function () {
    expect(CustomerType::Natural->value)->toBe('natural')
        ->and(CustomerType::Natural->label())->toBe('Persona natural')
        ->and(CustomerType::Company->value)->toBe('company')
        ->and(CustomerType::Company->label())->toBe('Empresa')
        ->and(CustomerType::cases())->toHaveCount(2);
});

it('CLI-009 labels the customer statuses in Spanish', function () {
    expect(CustomerStatus::Active->value)->toBe('active')
        ->and(CustomerStatus::Active->label())->toBe('Activo')
        ->and(CustomerStatus::Inactive->value)->toBe('inactive')
        ->and(CustomerStatus::Inactive->label())->toBe('Inactivo')
        ->and(CustomerStatus::cases())->toHaveCount(2);
});

it('DEC-CLI-02 labels the eight document types in Spanish', function () {
    $labels = collect(DocumentType::cases())->mapWithKeys(fn (DocumentType $type) => [$type->value => $type->label()])->all();

    expect($labels)->toBe([
        'cedula_v' => 'Cédula V',
        'cedula_e' => 'Cédula E',
        'passport' => 'Pasaporte',
        'rif_j' => 'RIF J',
        'rif_g' => 'RIF G',
        'rif_v' => 'RIF V',
        'rif_e' => 'RIF E',
        'rif_p' => 'RIF P',
    ]);
});

it('CLI-003 allows cédula and passport for natural persons and the five RIF types for companies', function () {
    expect(DocumentType::allowedFor(CustomerType::Natural))
        ->toBe([DocumentType::CedulaV, DocumentType::CedulaE, DocumentType::Passport])
        ->and(DocumentType::allowedFor(CustomerType::Company))
        ->toBe([DocumentType::RifJ, DocumentType::RifG, DocumentType::RifV, DocumentType::RifE, DocumentType::RifP]);
});

it('CLI-003 exposes the prefix letter of each document type, null for passports', function () {
    expect(DocumentType::CedulaV->letter())->toBe('V')
        ->and(DocumentType::CedulaE->letter())->toBe('E')
        ->and(DocumentType::RifJ->letter())->toBe('J')
        ->and(DocumentType::RifG->letter())->toBe('G')
        ->and(DocumentType::RifV->letter())->toBe('V')
        ->and(DocumentType::RifE->letter())->toBe('E')
        ->and(DocumentType::RifP->letter())->toBe('P')
        ->and(DocumentType::Passport->letter())->toBeNull();
});

it('CLI-003 tells RIF types from the others', function () {
    expect(DocumentType::RifJ->isRif())->toBeTrue()
        ->and(DocumentType::RifP->isRif())->toBeTrue()
        ->and(DocumentType::CedulaV->isRif())->toBeFalse()
        ->and(DocumentType::Passport->isRif())->toBeFalse();
});

it('DEC-CLI-33 defines exactly the 24 federal entities with unique values and labels', function () {
    $cases = VenezuelanState::cases();

    expect($cases)->toHaveCount(24)
        ->and(array_unique(array_map(fn (VenezuelanState $s) => $s->value, $cases)))->toHaveCount(24)
        ->and(array_unique(array_map(fn (VenezuelanState $s) => $s->label(), $cases)))->toHaveCount(24);

    foreach ($cases as $case) {
        expect($case->value)->toMatch('/^[a-z_]+$/')
            ->and($case->label())->toBeString()->not->toBe('');
    }

    expect(VenezuelanState::DistritoCapital->label())->toBe('Distrito Capital')
        ->and(VenezuelanState::Tachira->label())->toBe('Táchira')
        ->and(VenezuelanState::Anzoategui->label())->toBe('Anzoátegui');
});

it('DEC-CLI-33 maps a label back to its state ignoring case, accents and surrounding spaces', function () {
    expect(VenezuelanState::fromLabel('Táchira'))->toBe(VenezuelanState::Tachira)
        ->and(VenezuelanState::fromLabel('tachira'))->toBe(VenezuelanState::Tachira)
        ->and(VenezuelanState::fromLabel('ANZOATEGUI'))->toBe(VenezuelanState::Anzoategui)
        ->and(VenezuelanState::fromLabel('  distrito   capital '))->toBe(VenezuelanState::DistritoCapital)
        ->and(VenezuelanState::fromLabel('Nueva Esparta'))->toBe(VenezuelanState::NuevaEsparta);
});

it('DEC-CLI-33 round-trips every label and rejects unknown text', function () {
    foreach (VenezuelanState::cases() as $case) {
        expect(VenezuelanState::fromLabel($case->label()))->toBe($case);
    }

    expect(VenezuelanState::fromLabel('Atlantis'))->toBeNull()
        ->and(VenezuelanState::fromLabel(''))->toBeNull();
});
