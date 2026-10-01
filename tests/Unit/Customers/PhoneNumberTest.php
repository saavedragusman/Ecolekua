<?php

use App\Support\Customers\InvalidPhoneNumber;
use App\Support\Customers\PhoneNumber;

/**
 * The reason code of the exception thrown when parsing the raw phone, or null when it parses.
 */
function phoneFailureReason(string $raw): ?string
{
    try {
        PhoneNumber::parse($raw);
    } catch (InvalidPhoneNumber $exception) {
        return $exception->reason;
    }

    return null;
}

it('DT-01 and E-06 normalize every accepted Venezuelan form to the same E.164 number', function (string $raw) {
    expect(PhoneNumber::parse($raw)->e164())->toBe('+584141234567');
})->with([
    'local with dots and hyphen' => '0414-123.45.67',
    'local spaced' => '0414 123 45 67',
    'local compact' => '04141234567',
    'local with parentheses' => '(0414) 123-4567',
    'plus 58' => '+58 414 123 45 67',
    'plus 58 compact' => '+584141234567',
    '0058 prefix' => '0058 414 1234567',
    '58 without plus' => '584141234567',
    'no trunk' => '4141234567',
]);

it('DT-01 normalizes a landline to E.164', function () {
    expect(PhoneNumber::parse('(0212) 555-1234')->e164())->toBe('+582125551234')
        ->and(PhoneNumber::parse('+58 212 555 1234')->e164())->toBe('+582125551234');
});

it('DEC-CLI-25 rejects numbers from other countries with the foreign reason', function (string $raw) {
    expect(phoneFailureReason($raw))->toBe(InvalidPhoneNumber::FOREIGN);
})->with([
    'plus 1' => '+1 415 555 0123',
    'plus 57' => '+573001234567',
    '0044 prefix' => '0044 20 7946 0958',
]);

it('CLI-004 rejects malformed numbers with the format reason', function (string $raw) {
    expect(phoneFailureReason($raw))->toBe(InvalidPhoneNumber::FORMAT);
})->with([
    'nine digits' => '414123456',
    'twelve digits not starting with 58' => '041412345678',
    'toll free 0800' => '0800-123-4567',
    'text' => 'no es un teléfono',
    'empty' => '',
    'plus 58 too short' => '+58 414 123',
    'plus 58 with a non 2 or 4 prefix' => '+58 500 123 4567',
]);

it('CLI-004 tells mobile from landline by the first digit of the national number', function () {
    $mobile = PhoneNumber::parse('0414-123-4567');
    $landline = PhoneNumber::parse('0212-555-1234');

    expect($mobile->isMobile())->toBeTrue()
        ->and($mobile->isLandline())->toBeFalse()
        ->and($landline->isLandline())->toBeTrue()
        ->and($landline->isMobile())->toBeFalse();
});

it('E-06 displays the number in its readable local form', function () {
    expect(PhoneNumber::parse('+584141234567')->display())->toBe('0414-123-4567')
        ->and(PhoneNumber::parse('02125551234')->display())->toBe('0212-555-1234');
});

it('E-37 reports the original input back through the exception reason only', function () {
    $exception = null;

    try {
        PhoneNumber::parse('+1 415 555 0123');
    } catch (InvalidPhoneNumber $caught) {
        $exception = $caught;
    }

    expect($exception)->toBeInstanceOf(InvalidPhoneNumber::class)
        ->and($exception?->reason)->toBe('foreign')
        ->and($exception?->getMessage())->not->toContain('415');
});

it('DT-01 drops a trunk 0 typed right after the +58 or 0058 country code', function (string $raw, string $e164) {
    expect(PhoneNumber::parse($raw)->e164())->toBe($e164);
})->with([
    'plus 58 mobile' => ['+58 0414-123-4567', '+584141234567'],
    'plus 58 landline' => ['+58 0212 555 1234', '+582125551234'],
    '0058 mobile' => ['0058 0414 1234567', '+584141234567'],
]);

it('DEC-CLI-25 still rejects non-Venezuelan numbers and a second trunk 0 after the country code', function () {
    expect(phoneFailureReason('+1 0415 555 0123'))->toBe('foreign')
        ->and(phoneFailureReason('+58 00414 123 4567'))->toBe('format');
});

it('DEC-CLI-08 ignores a trunk 0 after the country code in the search fragment', function () {
    expect(PhoneNumber::searchFragment('+58 0414 123'))->toBe('414123')
        ->and(PhoneNumber::searchFragment('0058 0414 123'))->toBe('414123');
});

it('DEC-CLI-08 builds a digits-only search fragment from partial phone input', function (string $term, string $fragment) {
    expect(PhoneNumber::searchFragment($term))->toBe($fragment);
})->with([
    'local with space' => ['0414 123', '414123'],
    'without trunk' => ['414-123', '414123'],
    'plus 58' => ['+58 414 123', '414123'],
    '0058 prefix' => ['0058 414 123', '414123'],
    'seven digits' => ['1234567', '1234567'],
    'exactly three digits' => ['123', '123'],
    'landline local' => ['0212 555', '212555'],
]);

it('DEC-CLI-08 gives no search fragment for text or too few digits', function (string $term) {
    expect(PhoneNumber::searchFragment($term))->toBeNull();
})->with([
    'a name' => 'María',
    'a document' => 'J-1234',
    'two digits' => '12',
    'only the trunk and one digit' => '041',
    'only the country code' => '+58',
    'empty' => '',
    'blank' => '   ',
]);
