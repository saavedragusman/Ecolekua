<?php

use App\Enums\AuditAction;
use App\Enums\PermissionName;
use App\Models\AuditLog;
use App\Models\CommercialParameters;
use App\Models\User;
use Illuminate\Testing\TestResponse;

// COT-006 (E-11, design Decision 13): the advance percentage and the validity of quotations live in one
// row created by the migration with 50 % and 7 days. The settings page ships later, so the write answers
// a redirect back (003 precedent).

/**
 * @param  array<string, mixed>  $payload
 */
function saveCommercialParameters(array $payload, ?User $actor = null): TestResponse
{
    $actor ??= userWithPermissions(PermissionName::PricingView, PermissionName::PricingManage);

    return test()->actingAs($actor)->putJson('/pricing/settings/parameters', $payload);
}

function parametersAuditRows(): int
{
    return AuditLog::query()->where('action', AuditAction::PricingParametersUpdated->value)->count();
}

it('COT-006 starts with an advance of 50 % and a validity of 7 days', function () {
    $parameters = CommercialParameters::current();

    expect($parameters->advance_percentage->toScale(2)->toString())->toBe('50.00')
        ->and($parameters->validity_days)->toBe(7);
});

it('E-11 changes the advance to 40 % and the validity to 10 days and audits both changes', function () {
    $actor = userWithPermissions(PermissionName::PricingView, PermissionName::PricingManage);

    saveCommercialParameters(['advance_percentage' => '40', 'validity_days' => 10], $actor)->assertRedirect();

    $parameters = CommercialParameters::current();
    $audit = AuditLog::query()->where('action', AuditAction::PricingParametersUpdated->value)->sole();

    expect($parameters->advance_percentage->toScale(2)->toString())->toBe('40.00')
        ->and($parameters->validity_days)->toBe(10)
        ->and($parameters->updated_by)->toBe($actor->id)
        ->and(CommercialParameters::query()->count())->toBe(1)
        ->and($audit->actor_id)->toBe($actor->id)
        ->and($audit->entity_type)->toBe($parameters->getMorphClass())
        ->and($audit->entity_id)->toBe(1)
        ->and($audit->old_values)->toEqual(['advance_percentage' => '50.00', 'validity_days' => 7])
        ->and($audit->new_values)->toEqual(['advance_percentage' => '40.00', 'validity_days' => 10])
        ->and($audit->new_values['advance_percentage'])->toBeMoneyString();
});

it('E-11 audits only the field that changed', function () {
    saveCommercialParameters(['advance_percentage' => '50,00', 'validity_days' => 10])->assertRedirect();

    $audit = AuditLog::query()->where('action', AuditAction::PricingParametersUpdated->value)->sole();

    expect($audit->old_values)->toBe(['validity_days' => 7])
        ->and($audit->new_values)->toBe(['validity_days' => 10]);
});

it('E-11 accepts a decimal percentage and the bounds 0, 100, 1 and 365', function (string $percentage, int $days, string $stored) {
    saveCommercialParameters(['advance_percentage' => $percentage, 'validity_days' => $days])->assertRedirect();

    $parameters = CommercialParameters::current();

    expect($parameters->advance_percentage->toScale(2)->toString())->toBe($stored)
        ->and($parameters->validity_days)->toBe($days);
})->with([
    'decimal with comma' => ['37,5', 10, '37.50'],
    'zero and one day' => ['0', 1, '0.00'],
    'one hundred and a year' => ['100.00', 365, '100.00'],
]);

it('E-11 writes nothing and audits nothing when the values do not change', function () {
    $stamp = CommercialParameters::current()->updated_at;

    $this->travel(5)->minutes();

    saveCommercialParameters(['advance_percentage' => '50', 'validity_days' => 7])->assertRedirect();

    expect(parametersAuditRows())->toBe(0)
        ->and(CommercialParameters::current()->updated_at->equalTo($stamp))->toBeTrue();
});

it('E-11 rejects an advance outside 0-100 or with more than two decimals, on its field', function (string $percentage) {
    saveCommercialParameters(['advance_percentage' => $percentage, 'validity_days' => 10])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['advance_percentage'])
        ->assertJsonMissingValidationErrors(['validity_days']);

    expect(CommercialParameters::current()->validity_days)->toBe(7)
        ->and(parametersAuditRows())->toBe(0);
})->with([
    'one hundred twenty' => ['120'],
    'just above one hundred' => ['100.01'],
    'negative' => ['-1'],
    'three decimals' => ['40.005'],
    'text' => ['cuarenta'],
    'empty' => [''],
]);

it('E-11 rejects a validity of 0 days, above 365 or not an integer, on its field', function (mixed $days) {
    saveCommercialParameters(['advance_percentage' => '40', 'validity_days' => $days])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['validity_days']);

    expect(CommercialParameters::current()->advance_percentage->toScale(2)->toString())->toBe('50.00')
        ->and(parametersAuditRows())->toBe(0);
})->with([
    'zero' => [0],
    'negative' => [-3],
    'above a year' => [366],
    'decimal' => [7.5],
    'text' => ['siete'],
]);

it('E-11 reports both fields together and saves neither', function () {
    saveCommercialParameters(['advance_percentage' => '120', 'validity_days' => 0])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['advance_percentage', 'validity_days']);

    expect(parametersAuditRows())->toBe(0);
});

it('E-11 requires both fields and the percentage as a string, so it never travels as a float', function (array $payload, string $field) {
    saveCommercialParameters($payload)->assertUnprocessable()->assertJsonValidationErrors([$field]);
})->with([
    'no percentage' => [['validity_days' => 10], 'advance_percentage'],
    'no validity' => [['advance_percentage' => '40'], 'validity_days'],
    'percentage as number' => [['advance_percentage' => 40, 'validity_days' => 10], 'advance_percentage'],
]);

it('E-11 answers 403, audited, to a user with pricing.view but without pricing.manage and changes nothing', function () {
    $actor = userWithPermissions(PermissionName::PricingView);

    saveCommercialParameters(['advance_percentage' => '40', 'validity_days' => 10], $actor)->assertForbidden();

    expect(CommercialParameters::current()->advance_percentage->toScale(2)->toString())->toBe('50.00')
        ->and(parametersAuditRows())->toBe(0)
        ->and(AuditLog::query()->where('action', AuditAction::AuthorizationDenied->value)->sole()->actor_id)->toBe($actor->id);
});

it('E-11 answers 403 to a user holding other permissions only', function () {
    $actor = userWithPermissions(PermissionName::ProductsView, PermissionName::ProductsUpdate);

    saveCommercialParameters(['advance_percentage' => '40', 'validity_days' => 10], $actor)->assertForbidden();

    expect(CommercialParameters::current()->validity_days)->toBe(7);
});

it('COT-006 declares the parameters route', function () {
    $route = app('router')->getRoutes()->getByName('pricing.settings.parameters.update');

    expect($route->uri())->toBe('pricing/settings/parameters')
        ->and($route->methods())->toContain('PUT');
});
