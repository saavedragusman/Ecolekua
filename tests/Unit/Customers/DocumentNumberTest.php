<?php

use App\Enums\DocumentType;
use App\Support\Customers\DocumentNumber;

it('CLI-003 and E-36 normalize by removing separators, uppercasing and adding the type letter', function (DocumentType $type, string $raw, string $expected) {
    expect(DocumentNumber::normalize($type, $raw))->toBe($expected);
})->with([
    'rif with lowercase letter and separators' => [DocumentType::RifJ, 'j-12.345.678 4', 'J123456784'],
    'rif with its letter' => [DocumentType::RifJ, 'J-12345678-4', 'J123456784'],
    'rif without the letter' => [DocumentType::RifG, '20000001-5', 'G200000015'],
    'cédula without the letter' => [DocumentType::CedulaV, '12.345.678', 'V12345678'],
    'cédula lowercase letter' => [DocumentType::CedulaE, 'e 12 345 678', 'E12345678'],
    'passport uppercased and without separators' => [DocumentType::Passport, 'ab-123 456.c', 'AB123456C'],
    'blank stays empty' => [DocumentType::CedulaV, ' - . ', ''],
]);

it('CLI-003 keeps a wrong letter so the format check can reject it', function () {
    $normalized = DocumentNumber::normalize(DocumentType::RifJ, 'V-12345678-4');

    expect($normalized)->toBe('V123456784')
        ->and(DocumentNumber::isValid(DocumentType::RifJ, $normalized))->toBeFalse()
        ->and(DocumentNumber::isValid(DocumentType::RifV, $normalized))->toBeFalse();
});

it('E-34 accepts cédulas of 6 to 9 digits and rejects 5 and 10', function (string $normalized, bool $valid) {
    expect(DocumentNumber::isValid(DocumentType::CedulaV, $normalized))->toBe($valid);
})->with([
    'five digits' => ['V12345', false],
    'six digits' => ['V123456', true],
    'eight digits' => ['V12345678', true],
    'nine digits' => ['V123456789', true],
    'ten digits' => ['V1234567890', false],
    'letters inside' => ['V12A456', false],
    'no digits' => ['V', false],
    'empty' => ['', false],
]);

it('CLI-003 requires the cédula letter to match the type', function () {
    expect(DocumentNumber::isValid(DocumentType::CedulaE, 'E12345678'))->toBeTrue()
        ->and(DocumentNumber::isValid(DocumentType::CedulaE, 'V12345678'))->toBeFalse()
        ->and(DocumentNumber::isValid(DocumentType::CedulaV, 'E12345678'))->toBeFalse();
});

it('E-34 accepts passports of 5 to 20 alphanumeric characters and rejects 4 and 21', function (string $normalized, bool $valid) {
    expect(DocumentNumber::isValid(DocumentType::Passport, $normalized))->toBe($valid);
})->with([
    'four characters' => ['AB12', false],
    'five characters' => ['AB123', true],
    'twenty characters' => [str_repeat('A', 20), true],
    'twenty one characters' => [str_repeat('A', 21), false],
    'with a symbol' => ['AB12_5', false],
    'lowercase is not canonical' => ['ab123456', false],
]);

it('DEC-CLI-30 computes the SENIAT check digit', function (string $letter, string $digits, int $expected) {
    expect(DocumentNumber::rifCheckDigit($letter, $digits))->toBe($expected);
})->with([
    'worked example J' => ['J', '12345678', 4],
    'G' => ['G', '20000001', 5],
    'V' => ['V', '98765432', 1],
    'E' => ['E', '11111111', 4],
    'P' => ['P', '30405060', 3],
    'raw result 10 maps to zero' => ['G', '10000000', 0],
    'raw result 11 maps to zero' => ['J', '10000009', 0],
]);

it('E-34 accepts a RIF only with the right check digit, for each letter', function (DocumentType $type, string $valid) {
    $letter = $valid[0];
    $body = substr($valid, 0, 9);
    $check = (int) substr($valid, 9);
    $wrong = $body.(($check + 1) % 10);

    expect(DocumentNumber::isValid($type, $valid))->toBeTrue()
        ->and(DocumentNumber::isValid($type, $wrong))->toBeFalse()
        ->and($letter)->toBe($type->letter());
})->with([
    'J' => [DocumentType::RifJ, 'J123456784'],
    'G' => [DocumentType::RifG, 'G200000015'],
    'V' => [DocumentType::RifV, 'V987654321'],
    'E' => [DocumentType::RifE, 'E111111114'],
    'P' => [DocumentType::RifP, 'P304050603'],
    'check digit zero from 10' => [DocumentType::RifG, 'G100000000'],
    'check digit zero from 11' => [DocumentType::RifJ, 'J100000090'],
]);

it('E-34 rejects RIFs with the wrong length or letter', function (DocumentType $type, string $normalized) {
    expect(DocumentNumber::isValid($type, $normalized))->toBeFalse();
})->with([
    'eight digits only' => [DocumentType::RifJ, 'J12345678'],
    'ten digits' => [DocumentType::RifJ, 'J1234567840'],
    'letter of another type' => [DocumentType::RifJ, 'G123456784'],
    'no letter' => [DocumentType::RifJ, '123456784'],
]);

it('CLI-003 displays each document in its readable form', function () {
    expect(DocumentNumber::display(DocumentType::CedulaV, 'V12345678'))->toBe('V-12345678')
        ->and(DocumentNumber::display(DocumentType::CedulaE, 'E123456'))->toBe('E-123456')
        ->and(DocumentNumber::display(DocumentType::RifJ, 'J123456784'))->toBe('J-12345678-4')
        ->and(DocumentNumber::display(DocumentType::RifP, 'P304050603'))->toBe('P-30405060-3')
        ->and(DocumentNumber::display(DocumentType::Passport, 'AB123456'))->toBe('AB123456');
});
