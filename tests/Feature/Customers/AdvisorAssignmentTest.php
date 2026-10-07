<?php

use App\Enums\AuditAction;
use App\Enums\CustomerStatus;
use App\Enums\PermissionName;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Inertia\Testing\AssertableInertia as Assert;

function advisorAuditRows(): Collection
{
    return AuditLog::query()->where('action', AuditAction::CustomerAdvisorAssigned->value)->get();
}

function advisorNamed(string $first, string $last): User
{
    $advisor = userWithPermissions(PermissionName::CustomersPortfolio);
    $advisor->update(['first_name' => $first, 'last_name' => $last]);

    return $advisor;
}

it('E-26 reassigns the customer to another eligible advisor and audits previous and new advisor', function () {
    $actor = userWithPermissions(PermissionName::CustomersAssign);
    $previous = advisorNamed('Ana', 'Gómez');
    $next = advisorNamed('Luisa', 'Pérez');
    $customer = Customer::factory()->assignedTo($previous)->create();

    $this->actingAs($actor)
        ->put("/customers/{$customer->id}/advisor", ['advisor_id' => $next->id])
        ->assertRedirect("/customers/{$customer->id}")
        ->assertInertiaFlash('message', 'La asesora del cliente fue actualizada.');

    expect($customer->fresh()->advisor_id)->toBe($next->id);

    $audit = AuditLog::query()->where('action', AuditAction::CustomerAdvisorAssigned->value)->sole();

    expect($audit->actor_id)->toBe($actor->id)
        ->and($audit->entity_type)->toBe($customer->getMorphClass())
        ->and($audit->entity_id)->toBe($customer->id)
        ->and($audit->old_values)->toEqual(['advisor_id' => $previous->id, 'advisor_name' => 'Ana Gómez'])
        ->and($audit->new_values)->toEqual(['advisor_id' => $next->id, 'advisor_name' => 'Luisa Pérez']);
});

it('E-26 assigns an advisor to a customer that had none and audits a null previous advisor', function () {
    $actor = userWithPermissions(PermissionName::CustomersAssign);
    $next = advisorNamed('Luisa', 'Pérez');
    $customer = Customer::factory()->create();

    $this->actingAs($actor)
        ->put("/customers/{$customer->id}/advisor", ['advisor_id' => $next->id])
        ->assertRedirect("/customers/{$customer->id}")
        ->assertInertiaFlash('message', 'La asesora del cliente fue actualizada.');

    $audit = AuditLog::query()->where('action', AuditAction::CustomerAdvisorAssigned->value)->sole();

    expect($customer->fresh()->advisor_id)->toBe($next->id)
        ->and($audit->old_values)->toEqual(['advisor_id' => null, 'advisor_name' => null])
        ->and($audit->new_values)->toEqual(['advisor_id' => $next->id, 'advisor_name' => 'Luisa Pérez']);
});

it('CLI-014 unassigns the customer with a null advisor_id and audits the previous advisor', function () {
    $actor = userWithPermissions(PermissionName::CustomersAssign);
    $previous = advisorNamed('Ana', 'Gómez');
    $customer = Customer::factory()->assignedTo($previous)->create();

    $this->actingAs($actor)
        ->putJson("/customers/{$customer->id}/advisor", ['advisor_id' => null])
        ->assertRedirect("/customers/{$customer->id}");

    $audit = AuditLog::query()->where('action', AuditAction::CustomerAdvisorAssigned->value)->sole();

    expect($customer->fresh()->advisor_id)->toBeNull()
        ->and($audit->old_values)->toEqual(['advisor_id' => $previous->id, 'advisor_name' => 'Ana Gómez'])
        ->and($audit->new_values)->toEqual(['advisor_id' => null, 'advisor_name' => null]);
});

it('CLI-014 unassigning a customer without advisor is a no-op without audit', function () {
    $customer = Customer::factory()->create();

    $this->actingAs(userWithPermissions(PermissionName::CustomersAssign))
        ->putJson("/customers/{$customer->id}/advisor", ['advisor_id' => null])
        ->assertRedirect("/customers/{$customer->id}");

    expect($customer->fresh()->advisor_id)->toBeNull()
        ->and(advisorAuditRows())->toHaveCount(0);
});

it('CLI-014 assigning the same advisor again is a no-op without audit', function () {
    $advisor = advisorNamed('Ana', 'Gómez');
    $customer = Customer::factory()->assignedTo($advisor)->create();

    $this->actingAs(userWithPermissions(PermissionName::CustomersAssign))
        ->putJson("/customers/{$customer->id}/advisor", ['advisor_id' => $advisor->id])
        ->assertRedirect("/customers/{$customer->id}");

    expect($customer->fresh()->advisor_id)->toBe($advisor->id)
        ->and(advisorAuditRows())->toHaveCount(0);
});

it('E-33 submitting the current but unavailable advisor again is a no-op without error or audit', function () {
    $advisor = advisorNamed('Ana', 'Gómez');
    $customer = Customer::factory()->assignedTo($advisor)->create();
    $advisor->update(['is_active' => false]);

    $this->actingAs(userWithPermissions(PermissionName::CustomersAssign))
        ->putJson("/customers/{$customer->id}/advisor", ['advisor_id' => $advisor->id])
        ->assertRedirect("/customers/{$customer->id}");

    expect($customer->fresh()->advisor_id)->toBe($advisor->id)
        ->and(advisorAuditRows())->toHaveCount(0);
});

it('E-27 forbids assigning with customers.update but without customers.assign', function () {
    $previous = advisorNamed('Ana', 'Gómez');
    $next = advisorNamed('Luisa', 'Pérez');
    $customer = Customer::factory()->assignedTo($previous)->create();
    $actor = userWithPermissions(PermissionName::CustomersView, PermissionName::CustomersUpdate);

    $this->actingAs($actor)
        ->putJson("/customers/{$customer->id}/advisor", ['advisor_id' => $next->id])
        ->assertForbidden();

    expect($customer->fresh()->advisor_id)->toBe($previous->id)
        ->and(advisorAuditRows())->toHaveCount(0);
});

it('E-27 redirects a guest to the login page on advisor assignment', function () {
    $previous = advisorNamed('Ana', 'Gómez');
    $customer = Customer::factory()->assignedTo($previous)->create();

    $this->put("/customers/{$customer->id}/advisor", ['advisor_id' => null])->assertRedirect('/login');

    expect($customer->fresh()->advisor_id)->toBe($previous->id)
        ->and(advisorAuditRows())->toHaveCount(0);
});

it('E-28 rejects an inactive user as advisor', function () {
    $previous = advisorNamed('Ana', 'Gómez');
    $inactive = userWithPermissions(PermissionName::CustomersPortfolio);
    $inactive->update(['is_active' => false]);
    $customer = Customer::factory()->assignedTo($previous)->create();

    $this->actingAs(userWithPermissions(PermissionName::CustomersAssign))
        ->putJson("/customers/{$customer->id}/advisor", ['advisor_id' => $inactive->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['advisor_id' => 'La asesora debe ser un usuario activo con permiso para tener cartera.']);

    expect($customer->fresh()->advisor_id)->toBe($previous->id)
        ->and(advisorAuditRows())->toHaveCount(0);
});

it('E-28 rejects a user without customers.portfolio as advisor', function () {
    $previous = advisorNamed('Ana', 'Gómez');
    $withoutPortfolio = userWithPermissions(PermissionName::CustomersView);
    $customer = Customer::factory()->assignedTo($previous)->create();

    $this->actingAs(userWithPermissions(PermissionName::CustomersAssign))
        ->putJson("/customers/{$customer->id}/advisor", ['advisor_id' => $withoutPortfolio->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['advisor_id' => 'La asesora debe ser un usuario activo con permiso para tener cartera.']);

    expect($customer->fresh()->advisor_id)->toBe($previous->id)
        ->and(advisorAuditRows())->toHaveCount(0);
});

it('E-28 surfaces the rejection as an error on advisor_id for a browser submission', function () {
    $withoutPortfolio = userWithPermissions(PermissionName::CustomersView);
    $customer = Customer::factory()->create();

    $this->actingAs(userWithPermissions(PermissionName::CustomersAssign))
        ->from("/customers/{$customer->id}")
        ->put("/customers/{$customer->id}/advisor", ['advisor_id' => $withoutPortfolio->id])
        ->assertRedirect("/customers/{$customer->id}")
        ->assertSessionHasErrors(['advisor_id' => 'La asesora debe ser un usuario activo con permiso para tener cartera.']);

    expect($customer->fresh()->advisor_id)->toBeNull();
});

it('CLI-014 rejects a missing or unknown advisor_id', function (array $payload) {
    $previous = advisorNamed('Ana', 'Gómez');
    $customer = Customer::factory()->assignedTo($previous)->create();

    $this->actingAs(userWithPermissions(PermissionName::CustomersAssign))
        ->putJson("/customers/{$customer->id}/advisor", $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('advisor_id');

    expect($customer->fresh()->advisor_id)->toBe($previous->id)
        ->and(advisorAuditRows())->toHaveCount(0);
})->with([
    'missing key' => [[]],
    'unknown user' => [['advisor_id' => 999999]],
    'not an integer' => [['advisor_id' => 'abc']],
]);

it('DEC-CLI-28 reassigns an inactive customer', function () {
    $next = advisorNamed('Luisa', 'Pérez');
    $customer = Customer::factory()->inactive()->create();

    $this->actingAs(userWithPermissions(PermissionName::CustomersAssign))
        ->putJson("/customers/{$customer->id}/advisor", ['advisor_id' => $next->id])
        ->assertRedirect("/customers/{$customer->id}");

    expect($customer->fresh()->advisor_id)->toBe($next->id)
        ->and($customer->fresh()->status)->toBe(CustomerStatus::Inactive)
        ->and(advisorAuditRows())->toHaveCount(1);
});

it('E-33 (after deactivation) keeps the assignment, flags the advisor unavailable and excludes it from the options', function () {
    $advisor = advisorNamed('Ana', 'Gómez');
    $other = advisorNamed('Luisa', 'Pérez');
    $customer = Customer::factory()->assignedTo($advisor)->create();
    $viewer = userWithPermissions(PermissionName::CustomersView, PermissionName::CustomersAssign);

    $advisor->update(['is_active' => false]);

    $this->actingAs($viewer)->get("/customers/{$customer->id}")->assertInertia(function (Assert $page) use ($advisor, $other) {
        $page->where('customer.advisor.id', $advisor->id)
            ->where('customer.advisor.available', false)
            ->has('advisorOptions', 1)
            ->where('advisorOptions.0', ['id' => $other->id, 'name' => 'Luisa Pérez']);
    });

    expect($customer->fresh()->advisor_id)->toBe($advisor->id)
        ->and(advisorAuditRows())->toHaveCount(0);
});

it('CLI-014 advisorOptions lists the eligible advisors by full name for a user with customers.assign', function () {
    $second = advisorNamed('Luisa', 'Pérez');
    $first = advisorNamed('Ana', 'Gómez');
    $notAdvisor = userWithPermissions(PermissionName::CustomersView);
    $customer = Customer::factory()->create();
    $viewer = userWithPermissions(PermissionName::CustomersView, PermissionName::CustomersAssign);

    $this->actingAs($viewer)->get("/customers/{$customer->id}")->assertInertia(function (Assert $page) use ($first, $second) {
        $page->has('advisorOptions', 2)
            ->where('advisorOptions.0', ['id' => $first->id, 'name' => 'Ana Gómez'])
            ->where('advisorOptions.1', ['id' => $second->id, 'name' => 'Luisa Pérez']);
    });

    expect($notAdvisor->isEligibleAdvisor())->toBeFalse();
});

it('E-27 advisorOptions is not sent to a user without customers.assign', function () {
    advisorNamed('Ana', 'Gómez');
    $customer = Customer::factory()->create();
    $viewer = userWithPermissions(PermissionName::CustomersView, PermissionName::CustomersUpdate);

    $this->actingAs($viewer)->get("/customers/{$customer->id}")->assertInertia(function (Assert $page) {
        $page->missing('advisorOptions');
    });
});
