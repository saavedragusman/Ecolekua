<?php

use App\Enums\AuditAction;
use App\Enums\PermissionName;
use App\Enums\QuotationOrigin;
use App\Enums\QuotationStatus;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Quotation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

// Creating a draft quotation (COT-013, COT-022, DEC-COT-45, design Decision 19): the recipient is an active
// customer or a prospect, the number comes from the sequence, the advisor follows COT-013, and the creation is
// audited. Lines are saved with the draft update (Phase 11b). Fictitious data only.

function draftCreator(PermissionName ...$extra): User
{
    return userWithPermissions(PermissionName::QuotationsView, PermissionName::QuotationsCreate, ...$extra);
}

function draftAdvisor(): User
{
    return userWithPermissions(PermissionName::CustomersPortfolio);
}

/**
 * @param  array<string, mixed>  $payload
 */
function storeQuotation(User $actor, array $payload): TestResponse
{
    return test()->actingAs($actor)->postJson('/quotations', $payload);
}

function createdAudit(): AuditLog
{
    return AuditLog::query()->where('action', AuditAction::QuotationCreated->value)->sole();
}

it('E-32 crea un borrador para un cliente activo con número, origen asesora y la asesora del cliente', function () {
    $advisor = draftAdvisor();
    $customer = Customer::factory()->assignedTo($advisor)->create();
    $actor = draftCreator();

    $response = test()->actingAs($actor)->post('/quotations', ['customer_id' => $customer->id]);

    $quotation = Quotation::query()->sole();
    $response->assertRedirect("/quotations/{$quotation->id}/edit");

    expect($quotation->status)->toBe(QuotationStatus::Draft)
        ->and($quotation->number)->toBe(1)
        ->and($quotation->origin)->toBe(QuotationOrigin::Advisor)
        ->and($quotation->customer_id)->toBe($customer->id)
        ->and($quotation->advisor_id)->toBe($advisor->id)
        ->and($quotation->created_by)->toBe($actor->id)
        ->and($quotation->lines)->toHaveCount(0)
        ->and(DB::table('quotation_sequences')->value('last_number'))->toBe(1);

    $audit = createdAudit();

    expect($audit->actor_id)->toBe($actor->id)
        ->and($audit->entity_id)->toBe($quotation->id)
        ->and($audit->new_values)->toMatchArray([
            'number' => 'COT-000001',
            'customer_id' => $customer->id,
            'advisor_id' => $advisor->id,
        ]);
});

it('DEC-COT-45 los números son consecutivos y un borrador eliminado no devuelve el suyo', function () {
    $actor = draftCreator();
    $customer = Customer::factory()->create();

    storeQuotation($actor, ['customer_id' => $customer->id])->assertRedirect();
    storeQuotation($actor, ['customer_id' => $customer->id])->assertRedirect();
    Quotation::query()->where('number', 2)->delete();
    storeQuotation($actor, ['customer_id' => $customer->id])->assertRedirect();

    expect(Quotation::query()->orderBy('number')->pluck('number')->all())->toBe([1, 3]);
});

it('COT-013 sin asesora en el cliente, la responsable es quien crea si tiene cartera; si no, ninguna', function () {
    $customer = Customer::factory()->create(['advisor_id' => null]);
    $withPortfolio = draftCreator(PermissionName::CustomersPortfolio);
    $withoutPortfolio = draftCreator();

    storeQuotation($withPortfolio, ['customer_id' => $customer->id])->assertRedirect();
    storeQuotation($withoutPortfolio, ['customer_id' => $customer->id])->assertRedirect();

    expect(Quotation::query()->orderBy('number')->pluck('advisor_id')->all())->toBe([$withPortfolio->id, null]);
});

it('E-74 si la asesora del cliente está inactiva, la responsable es quien crea y se avisa', function () {
    $inactive = draftAdvisor();
    $inactive->forceFill(['is_active' => false])->save();
    $customer = Customer::factory()->assignedTo($inactive)->create();
    $actor = draftCreator(PermissionName::CustomersPortfolio);

    test()->actingAs($actor)->post('/quotations', ['customer_id' => $customer->id])
        ->assertRedirect()
        ->assertInertiaFlash('notice', 'asesora del cliente no disponible');

    expect(Quotation::query()->sole()->advisor_id)->toBe($actor->id);
});

it('E-74 si la asesora del cliente perdió la cartera y quien crea no tiene, queda sin responsable y se avisa', function () {
    $customer = Customer::factory()->assignedTo(userWithPermissions(PermissionName::QuotationsView))->create();

    test()->actingAs(draftCreator())->post('/quotations', ['customer_id' => $customer->id])
        ->assertRedirect()
        ->assertInertiaFlash('notice', 'asesora del cliente no disponible');

    expect(Quotation::query()->sole()->advisor_id)->toBeNull();
});

it('DEC-COT-63 con la asesora del cliente disponible no hay aviso', function () {
    $customer = Customer::factory()->assignedTo(draftAdvisor())->create();

    test()->actingAs(draftCreator())->post('/quotations', ['customer_id' => $customer->id])
        ->assertRedirect()
        ->assertInertiaFlashMissing('notice');
});

it('DEC-COT-63 un prospecto queda a cargo de quien crea si tiene cartera, sin aviso', function () {
    $actor = draftCreator(PermissionName::CustomersPortfolio);

    test()->actingAs($actor)->post('/quotations', ['prospect_name' => 'Taller Ejemplo', 'prospect_phone' => '0414-1234567'])
        ->assertRedirect()
        ->assertInertiaFlashMissing('notice');

    expect(Quotation::query()->sole()->advisor_id)->toBe($actor->id);
});

it('COT-013 un cliente que no existe se rechaza en el cliente', function () {
    storeQuotation(draftCreator(), ['customer_id' => 999_999])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['customer_id']);

    expect(Quotation::query()->count())->toBe(0)
        ->and(DB::table('quotation_sequences')->value('last_number'))->toBe(0);
});

it('COT-013 una asesora explícita debe ser una usuaria activa con cartera', function () {
    $customer = Customer::factory()->assignedTo(draftAdvisor())->create();
    $chosen = draftAdvisor();
    $actor = draftCreator();

    storeQuotation($actor, ['customer_id' => $customer->id, 'advisor_id' => $chosen->id])->assertRedirect();

    $noPortfolio = userWithPermissions(PermissionName::QuotationsView);
    $inactive = draftAdvisor();
    $inactive->forceFill(['is_active' => false])->save();

    storeQuotation($actor, ['customer_id' => $customer->id, 'advisor_id' => $noPortfolio->id])->assertStatus(422)->assertJsonValidationErrors(['advisor_id']);
    storeQuotation($actor, ['customer_id' => $customer->id, 'advisor_id' => $inactive->id])->assertStatus(422)->assertJsonValidationErrors(['advisor_id']);

    expect(Quotation::query()->sole()->advisor_id)->toBe($chosen->id);
});

it('E-33 no se crea una cotización para un cliente inactivo', function () {
    $customer = Customer::factory()->inactive()->create();

    storeQuotation(draftCreator(), ['customer_id' => $customer->id])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['customer_id']);

    expect(Quotation::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('action', AuditAction::QuotationCreated->value)->count())->toBe(0)
        ->and(DB::table('quotation_sequences')->value('last_number'))->toBe(0);
});

it('COT-013 incluir al contacto solo vale para una empresa que tiene contacto', function () {
    $actor = draftCreator();
    $company = Customer::factory()->company()->withContact()->create();
    $natural = Customer::factory()->create();

    storeQuotation($actor, ['customer_id' => $company->id, 'include_contact' => true])->assertRedirect();
    storeQuotation($actor, ['customer_id' => $natural->id, 'include_contact' => true])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['include_contact']);

    expect(Quotation::query()->sole()->include_contact)->toBeTrue();
});

it('E-68 crea un borrador para un prospecto con el teléfono normalizado, sin crear ningún cliente', function () {
    $customers = Customer::query()->count();

    storeQuotation(draftCreator(), [
        'prospect_name' => 'Taller Ejemplo',
        'prospect_phone' => '0414-1234567',
        'prospect_email' => 'taller@ejemplo.test',
    ])->assertRedirect();

    $quotation = Quotation::query()->sole();

    expect($quotation->customer_id)->toBeNull()
        ->and($quotation->prospect_name)->toBe('Taller Ejemplo')
        ->and($quotation->prospect_phone)->toBe('+584141234567')
        ->and($quotation->prospect_email)->toBe('taller@ejemplo.test')
        ->and(Customer::query()->count())->toBe($customers)
        ->and(createdAudit()->new_values)->toMatchArray(['number' => 'COT-000001', 'prospect_name' => 'Taller Ejemplo']);
});

it('E-68 rechaza un prospecto con teléfono fijo, de otro país o sin nombre', function (array $payload, string $field) {
    storeQuotation(draftCreator(), $payload)->assertStatus(422)->assertJsonValidationErrors([$field]);

    expect(Quotation::query()->count())->toBe(0);
})->with([
    'fijo' => [['prospect_name' => 'Taller Ejemplo', 'prospect_phone' => '0212-5551234'], 'prospect_phone'],
    'otro país' => [['prospect_name' => 'Taller Ejemplo', 'prospect_phone' => '+1 305 555 1234'], 'prospect_phone'],
    'sin nombre' => [['prospect_phone' => '0414-1234567'], 'prospect_name'],
    'sin teléfono' => [['prospect_name' => 'Taller Ejemplo'], 'prospect_phone'],
]);

it('COT-013 una cotización tiene exactamente un destinatario', function (array $payload) {
    storeQuotation(draftCreator(), $payload)->assertStatus(422)->assertJsonValidationErrors(['customer_id']);

    expect(Quotation::query()->count())->toBe(0);
})->with([
    'ninguno' => [[]],
    'los dos' => [fn () => ['customer_id' => Customer::factory()->create()->id, 'prospect_name' => 'Taller Ejemplo', 'prospect_phone' => '0414-1234567']],
]);

it('E-34 sin quotations.create responde 403, no crea nada y la denegación queda auditada', function () {
    $actor = userWithPermissions(PermissionName::QuotationsView);

    storeQuotation($actor, ['customer_id' => Customer::factory()->create()->id])->assertForbidden();

    expect(Quotation::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('action', AuditAction::AuthorizationDenied->value)->sole()->actor_id)->toBe($actor->id);
});
