<?php

use App\Enums\DocumentType;
use App\Enums\VenezuelanState;
use App\Models\Customer;
use App\Rules\DocumentNumberFormat;
use App\Rules\VenezuelanPhone;
use App\Support\Customers\CustomerRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Validator as ValidatorInstance;
use Tests\TestCase;

// The rules translate their messages and the unique check queries customers, so these unit tests boot the application.
uses(TestCase::class, RefreshDatabase::class);

/**
 * Validates data with the shared customer rules and their after hooks, as the FormRequests and the import do.
 *
 * @param  array<string, mixed>  $data
 */
function customerValidator(array $data, ?Customer $ignoring = null): ValidatorInstance
{
    $validator = Validator::make($data, CustomerRules::customer($ignoring));
    $validator->after(CustomerRules::after());

    return $validator;
}

/**
 * Minimum valid customer input plus overrides.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function minimalCustomerData(array $overrides = []): array
{
    return array_merge(['type' => 'natural', 'name' => 'Cliente de prueba', 'phone' => '0414-123.45.67'], $overrides);
}

it('CLI-001 accepts the minimum data and the full company data', function () {
    $company = [
        'type' => 'company',
        'name' => 'Empresa de prueba C.A.',
        'document_type' => 'rif_j',
        'document_number' => 'J-12345678-4',
        'phone' => '0414-123-4567',
        'email' => 'contacto@example.test',
        'birthday_day' => 29,
        'birthday_month' => 2,
        'anniversary_day' => 15,
        'anniversary_month' => 7,
        'notes' => 'Observaciones.',
        'contact' => ['name' => 'Persona de contacto', 'position' => 'Compras', 'phone' => '0212-555-1234', 'email' => 'c@example.test'],
        'address' => ['line' => 'Calle 1', 'city' => 'Ciudad de prueba', 'state' => 'zulia', 'reference' => 'Frente a la plaza'],
        'confirm_duplicate_phone' => true,
    ];

    expect(customerValidator(minimalCustomerData())->passes())->toBeTrue()
        ->and(customerValidator($company)->passes())->toBeTrue();
});

it('CLI-002 requires type, name and phone and rejects an unknown type', function () {
    $validator = customerValidator(['type' => 'robot']);

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->keys())->toEqualCanonicalizing(['type', 'name', 'phone']);
});

it('E-37 rejects a landline for the customer phone with the mobile-only message', function () {
    $validator = customerValidator(minimalCustomerData(['phone' => '0212-555-1234']));

    expect($validator->errors()->get('phone'))->toBe(['El teléfono del cliente debe ser un celular (04xx).']);
});

it('DEC-CLI-25 rejects a foreign customer phone with the Venezuela-only message', function () {
    $validator = customerValidator(minimalCustomerData(['phone' => '+1 415 555 0123']));

    expect($validator->errors()->get('phone'))->toBe(['Solo se admiten números de Venezuela (+58).']);
});

it('CLI-004 rejects a malformed phone with the format message', function () {
    $validator = customerValidator(minimalCustomerData(['phone' => '12345']));

    expect($validator->errors()->get('phone'))->toBe(['El teléfono no es un número venezolano válido.']);
});

it('DEC-CLI-26 VenezuelanPhone(true) accepts a landline and still rejects foreign numbers', function () {
    $landline = Validator::make(['phone' => '0212-555-1234'], ['phone' => [new VenezuelanPhone(true)]]);
    $foreign = Validator::make(['phone' => '+44 20 7946 0958'], ['phone' => [new VenezuelanPhone(true)]]);
    $mobileOnly = Validator::make(['phone' => '0212-555-1234'], ['phone' => [new VenezuelanPhone(false)]]);

    expect($landline->passes())->toBeTrue()
        ->and($foreign->errors()->get('phone'))->toBe(['Solo se admiten números de Venezuela (+58).'])
        ->and($mobileOnly->errors()->get('phone'))->toBe(['El teléfono del cliente debe ser un celular (04xx).']);
});

it('E-38 validates the contact person phone as Venezuelan, landline allowed', function () {
    $company = minimalCustomerData(['type' => 'company']);

    $landline = customerValidator($company + ['contact' => ['name' => 'Contacto', 'phone' => '0212-555-1234']]);
    $foreign = customerValidator($company + ['contact' => ['name' => 'Contacto', 'phone' => '+1 415 555 0123']]);

    expect($landline->passes())->toBeTrue()
        ->and($foreign->errors()->keys())->toBe(['contact.phone']);
});

it('CLI-005 requires name and phone when a contact person is sent', function () {
    $validator = customerValidator(minimalCustomerData(['type' => 'company', 'contact' => ['position' => 'Compras']]));

    expect($validator->errors()->keys())->toEqualCanonicalizing(['contact.name', 'contact.phone']);
});

it('CLI-005 and CLI-006 reject an empty contact or address array instead of letting it through', function (string $type, string $field) {
    $validator = customerValidator(minimalCustomerData(['type' => $type, $field => []]));

    expect($validator->passes())->toBeFalse()
        ->and($validator->errors()->has($field))->toBeTrue();
})->with([
    'company with empty contact' => ['company', 'contact'],
    'company with empty address' => ['company', 'address'],
    'natural with empty address' => ['natural', 'address'],
    'natural with empty contact' => ['natural', 'contact'],
]);

it('CLI-005 and CLI-006 still accept an absent or null contact and address', function () {
    expect(customerValidator(minimalCustomerData(['type' => 'company', 'contact' => null, 'address' => null]))->passes())->toBeTrue()
        ->and(customerValidator(minimalCustomerData(['type' => 'company']))->passes())->toBeTrue();
});

it('E-16 prohibits the contact person unless the customer is a company', function () {
    $contact = ['name' => 'Contacto', 'phone' => '0414-123-4567'];

    expect(customerValidator(minimalCustomerData(['type' => 'natural', 'contact' => $contact]))->errors()->has('contact'))->toBeTrue()
        ->and(customerValidator(minimalCustomerData(['type' => 'company', 'contact' => $contact]))->passes())->toBeTrue();
});

it('DEC-CLI-30 DocumentNumberFormat reads document_type to validate the number', function () {
    $rules = ['document_number' => [new DocumentNumberFormat]];

    $valid = Validator::make(['document_type' => 'rif_j', 'document_number' => 'J-12345678-4'], $rules);
    $wrongDigit = Validator::make(['document_type' => 'rif_j', 'document_number' => 'J-12345678-5'], $rules);
    $sameNumberOtherType = Validator::make(['document_type' => 'cedula_v', 'document_number' => 'J-12345678-4'], $rules);

    expect($valid->passes())->toBeTrue()
        ->and($wrongDigit->errors()->get('document_number'))->toBe(['El número de documento no es válido para el tipo de documento elegido.'])
        ->and($sameNumberOtherType->errors()->has('document_number'))->toBeTrue();
});

it('E-34 DocumentNumberFormat leaves a missing or unknown type to the other rules', function () {
    $rules = ['document_number' => [new DocumentNumberFormat]];

    expect(Validator::make(['document_number' => 'V12345678'], $rules)->passes())->toBeTrue()
        ->and(Validator::make(['document_type' => 'driver_license', 'document_number' => 'V12345678'], $rules)->passes())->toBeTrue();
});

it('E-35 and E-24 report a document type that does not fit the customer type on document_type', function (string $type, string $documentType, string $number) {
    $validator = customerValidator(minimalCustomerData(['type' => $type, 'document_type' => $documentType, 'document_number' => $number]));

    expect($validator->errors()->keys())->toBe(['document_type']);
})->with([
    'natural with RIF J' => ['natural', 'rif_j', 'J-12345678-4'],
    'company with cédula' => ['company', 'cedula_v', 'V-12345678'],
    'company with passport' => ['company', 'passport', 'AB123456'],
]);

it('CLI-003 accepts the document types that fit the customer type', function (string $type, string $documentType, string $number) {
    expect(customerValidator(minimalCustomerData(['type' => $type, 'document_type' => $documentType, 'document_number' => $number]))->passes())->toBeTrue();
})->with([
    'natural cédula V' => ['natural', 'cedula_v', 'V-12.345.678'],
    'natural cédula E' => ['natural', 'cedula_e', '12345678'],
    'natural passport' => ['natural', 'passport', 'ab-123456'],
    'company RIF G' => ['company', 'rif_g', 'G-20000001-5'],
    'company RIF P' => ['company', 'rif_p', 'P304050603'],
]);

it('DEC-CLI-02 requires document type and number together', function () {
    $onlyNumber = customerValidator(minimalCustomerData(['document_number' => 'V12345678']));
    $onlyType = customerValidator(minimalCustomerData(['document_type' => 'cedula_v']));

    expect($onlyNumber->errors()->keys())->toBe(['document_type'])
        ->and($onlyType->errors()->keys())->toBe(['document_number']);
});

it('E-34 rejects a document number with a wrong length or check digit', function () {
    $shortCedula = customerValidator(minimalCustomerData(['document_type' => 'cedula_v', 'document_number' => '12345']));
    $badRif = customerValidator(minimalCustomerData(['type' => 'company', 'document_type' => 'rif_j', 'document_number' => 'J-12345678-9']));

    expect($shortCedula->errors()->keys())->toBe(['document_number'])
        ->and($badRif->errors()->keys())->toBe(['document_number']);
});

it('E-36 and E-13 reject a document already registered, compared after normalization', function () {
    Customer::factory()->create(['document_type' => DocumentType::RifJ, 'document_number' => 'J123456784']);
    $data = minimalCustomerData(['type' => 'company', 'document_type' => 'rif_j', 'document_number' => 'j.12345678 4']);

    expect(customerValidator($data)->errors()->get('document_number'))->toBe(['Ya existe un cliente con ese documento.']);
});

it('E-13 lets a customer keep its own document and ignores inactive state when comparing', function () {
    $existing = Customer::factory()->inactive()->create(['document_type' => DocumentType::CedulaV, 'document_number' => 'V12345678']);
    $data = minimalCustomerData(['document_type' => 'cedula_v', 'document_number' => '12.345.678']);

    expect(customerValidator($data)->errors()->keys())->toBe(['document_number'])
        ->and(customerValidator($data, $existing)->passes())->toBeTrue()
        ->and(customerValidator(minimalCustomerData(['document_type' => 'cedula_e', 'document_number' => '12345678']))->passes())->toBeTrue();
});

it('DEC-CLI-31 accepts 29 February and rejects impossible commemorative dates', function (string $field, int $day, int $month, bool $valid) {
    $prefix = $field === 'birthday' ? 'birthday' : 'anniversary';
    $data = minimalCustomerData(['type' => 'company', "{$prefix}_day" => $day, "{$prefix}_month" => $month]);

    $validator = customerValidator($data);

    expect($validator->passes())->toBe($valid);

    if (! $valid) {
        expect($validator->errors()->keys())->toBe(["{$prefix}_day"]);
    }
})->with([
    'birthday 29 February' => ['birthday', 29, 2, true],
    'birthday 31 April' => ['birthday', 31, 4, false],
    'birthday 30 February' => ['birthday', 30, 2, false],
    'birthday 31 December' => ['birthday', 31, 12, true],
    'anniversary 29 February' => ['anniversary', 29, 2, true],
    'anniversary 31 April' => ['anniversary', 31, 4, false],
    'anniversary 30 February' => ['anniversary', 30, 2, false],
]);

it('CLI-017 rejects half a date and out-of-range parts', function () {
    $dayOnly = customerValidator(minimalCustomerData(['birthday_day' => 10]));
    $monthOnly = customerValidator(minimalCustomerData(['birthday_month' => 5]));
    $outOfRange = customerValidator(minimalCustomerData(['birthday_day' => 32, 'birthday_month' => 13]));

    expect($dayOnly->errors()->keys())->toBe(['birthday_month'])
        ->and($monthOnly->errors()->keys())->toBe(['birthday_day'])
        ->and($outOfRange->errors()->keys())->toEqualCanonicalizing(['birthday_day', 'birthday_month']);
});

it('E-22 prohibits the anniversary unless the customer is a company', function () {
    $dates = ['anniversary_day' => 15, 'anniversary_month' => 7];

    expect(customerValidator(minimalCustomerData(['type' => 'natural'] + $dates))->errors()->has('anniversary_day'))->toBeTrue()
        ->and(customerValidator(minimalCustomerData(['type' => 'company'] + $dates))->passes())->toBeTrue()
        ->and(customerValidator(minimalCustomerData(['type' => 'natural', 'birthday_day' => 15, 'birthday_month' => 7]))->passes())->toBeTrue();
});

it('DEC-CLI-33 accepts every venezuelan state value for the address', function () {
    foreach (VenezuelanState::cases() as $state) {
        $validator = customerValidator(minimalCustomerData(['address' => ['line' => 'Calle 1', 'city' => 'Ciudad', 'state' => $state->value]]));

        expect($validator->passes())->toBeTrue("state {$state->value} should be accepted");
    }
});

it('DEC-CLI-33 rejects any other state text, including a label instead of the value', function (string $state) {
    $validator = customerValidator(minimalCustomerData(['address' => ['line' => 'Calle 1', 'city' => 'Ciudad', 'state' => $state]]));

    expect($validator->errors()->keys())->toBe(['address.state']);
})->with([
    'unknown' => 'atlantis',
    'label instead of value' => 'Distrito Capital',
    'blank' => '',
]);

it('CLI-006 requires line, city and state when an address is sent', function () {
    $validator = customerValidator(minimalCustomerData(['address' => ['reference' => 'Cerca del parque']]));

    expect($validator->errors()->keys())->toEqualCanonicalizing(['address.line', 'address.city', 'address.state']);
});

it('CLI-007 limits the notes to 5000 characters', function () {
    expect(customerValidator(minimalCustomerData(['notes' => str_repeat('a', 5000)]))->passes())->toBeTrue()
        ->and(customerValidator(minimalCustomerData(['notes' => str_repeat('a', 5001)]))->errors()->keys())->toBe(['notes']);
});
