<?php

use App\Enums\PermissionName;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Eligibility is read from roles and permissions in the database.
uses(TestCase::class, RefreshDatabase::class);

it('CLI-014 treats an active user with customers.portfolio as an eligible advisor', function () {
    $advisor = userWithPermissions(PermissionName::CustomersPortfolio);

    expect($advisor->isEligibleAdvisor())->toBeTrue();
});

it('CLI-014 does not treat an inactive user with customers.portfolio as eligible', function () {
    $inactive = User::factory()->inactive()->withPermissions(PermissionName::CustomersPortfolio)->create();

    expect($inactive->isEligibleAdvisor())->toBeFalse();
});

it('CLI-014 does not treat a user without customers.portfolio as eligible', function () {
    $withoutPermission = userWithPermissions(PermissionName::CustomersView, PermissionName::CustomersAssign);
    $withoutRole = User::factory()->create();

    expect($withoutPermission->isEligibleAdvisor())->toBeFalse()
        ->and($withoutRole->isEligibleAdvisor())->toBeFalse();
});

it('DEC-CLI-18 eligibleAdvisors returns the same set the instance check accepts', function () {
    $eligible = userWithPermissions(PermissionName::CustomersPortfolio);
    $alsoEligible = userWithPermissions(PermissionName::CustomersPortfolio, PermissionName::CustomersView);
    $inactive = User::factory()->inactive()->withPermissions(PermissionName::CustomersPortfolio)->create();
    $noPermission = userWithPermissions(PermissionName::CustomersView);
    $noRole = User::factory()->create();

    $ids = User::eligibleAdvisors()->pluck('id')->all();
    $byInstanceCheck = User::query()->get()->filter(fn (User $user) => $user->isEligibleAdvisor())->pluck('id')->all();

    expect($ids)->toEqualCanonicalizing([$eligible->id, $alsoEligible->id])
        ->and($ids)->toEqualCanonicalizing($byInstanceCheck)
        ->and($ids)->not->toContain($inactive->id, $noPermission->id, $noRole->id);
});

it('DEC-CLI-18 lists a user once even when several roles grant customers.portfolio', function () {
    $advisor = userWithPermissions(PermissionName::CustomersPortfolio);
    $advisor->roles()->attach(userWithPermissions(PermissionName::CustomersPortfolio)->roles()->firstOrFail());

    expect($advisor->roles()->count())->toBe(2)
        ->and(User::eligibleAdvisors()->where('users.id', $advisor->id)->count())->toBe(1);
});

it('DEC-CLI-29 revoking customers.portfolio from the role makes the user ineligible on the next check', function () {
    $advisor = userWithPermissions(PermissionName::CustomersPortfolio, PermissionName::CustomersView);
    $other = userWithPermissions(PermissionName::CustomersPortfolio);

    expect($advisor->isEligibleAdvisor())->toBeTrue()
        ->and(User::eligibleAdvisors()->whereKey($advisor->id)->exists())->toBeTrue();

    $permission = Permission::query()->where('name', PermissionName::CustomersPortfolio->value)->firstOrFail();
    $advisor->roles()->firstOrFail()->permissions()->detach($permission->id);

    expect($advisor->refresh()->isEligibleAdvisor())->toBeFalse()
        ->and(User::eligibleAdvisors()->whereKey($advisor->id)->exists())->toBeFalse()
        ->and(User::eligibleAdvisors()->pluck('id')->all())->toBe([$other->id]);
});

it('DEC-CLI-29 eligibleAdvisors follows revocations and deactivation on the next query', function () {
    $advisor = userWithPermissions(PermissionName::CustomersPortfolio);

    expect(User::eligibleAdvisors()->whereKey($advisor->id)->exists())->toBeTrue();

    $advisor->forceFill(['is_active' => false])->save();

    expect(User::eligibleAdvisors()->whereKey($advisor->id)->exists())->toBeFalse()
        ->and($advisor->refresh()->isEligibleAdvisor())->toBeFalse();
});
