<?php

use App\Enums\PermissionName;
use App\Models\CombinationPrice;
use App\Models\ComboItemPrice;
use App\Models\CommercialParameters;
use App\Models\ExchangeRate;
use App\Models\Permission;
use App\Models\PriceScheme;
use App\Models\User;
use App\Policies\PricingPolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * One instance (or class) of each model governed by PricingPolicy in this phase.
 *
 * @return array<string, Model|class-string<Model>>
 */
function pricingSubjects(): array
{
    return [
        'scheme' => PriceScheme::factory()->create(),
        'price' => CombinationPrice::factory()->create(),
        'item price' => ComboItemPrice::factory()->create(),
        'parameters' => CommercialParameters::query()->findOrFail(1),
        'scheme class' => PriceScheme::class,
        'price class' => CombinationPrice::class,
        'item price class' => ComboItemPrice::class,
        'parameters class' => CommercialParameters::class,
        'exchange rate' => ExchangeRate::factory()->create(),
        'exchange rate class' => ExchangeRate::class,
    ];
}

/**
 * Every permission except the given ones, so the user holds "everything else".
 *
 * @return list<PermissionName>
 */
function pricingAllExcept(PermissionName ...$excluded): array
{
    return collect(PermissionName::cases())
        ->reject(fn (PermissionName $case): bool => in_array($case, $excluded, true))
        ->values()
        ->all();
}

it('COT-022 registers PricingPolicy for the pricing models created so far', function () {
    foreach ([PriceScheme::class, CombinationPrice::class, ComboItemPrice::class, CommercialParameters::class, ExchangeRate::class] as $model) {
        expect(Gate::getPolicyFor($model))->toBeInstanceOf(PricingPolicy::class);
    }
});

it('COT-022 lets view require pricing.view only, on the class and on each instance', function () {
    $viewer = userWithPermissions(PermissionName::PricingView);
    $outsider = userWithPermissions(...pricingAllExcept(PermissionName::PricingView));

    foreach (pricingSubjects() as $name => $subject) {
        expect(Gate::forUser($viewer)->allows('view', $subject))->toBeTrue("view on {$name}")
            ->and(Gate::forUser($outsider)->denies('view', $subject))->toBeTrue("view denied on {$name}");
    }
});

it('COT-022 lets manage require pricing.manage only, on the class and on each instance', function () {
    $manager = userWithPermissions(PermissionName::PricingManage);
    $outsider = userWithPermissions(...pricingAllExcept(PermissionName::PricingManage));

    foreach (pricingSubjects() as $name => $subject) {
        expect(Gate::forUser($manager)->allows('manage', $subject))->toBeTrue("manage on {$name}")
            ->and(Gate::forUser($outsider)->denies('manage', $subject))->toBeTrue("manage denied on {$name}");
    }
});

it('COT-022 keeps view and manage independent: pricing.view does not manage and pricing.manage does not view', function () {
    $viewer = userWithPermissions(PermissionName::PricingView);
    $manager = userWithPermissions(PermissionName::PricingManage);
    $scheme = PriceScheme::factory()->create();

    expect(Gate::forUser($viewer)->denies('manage', $scheme))->toBeTrue()
        ->and(Gate::forUser($manager)->denies('view', $scheme))->toBeTrue();
});

it('COT-022 denies every pricing ability to a user without roles', function () {
    $user = User::factory()->create();

    foreach (pricingSubjects() as $name => $subject) {
        expect(Gate::forUser($user)->denies('view', $subject))->toBeTrue("view denied on {$name}")
            ->and(Gate::forUser($user)->denies('manage', $subject))->toBeTrue("manage denied on {$name}");
    }
});

it('COT-022 does not grant anything by role name: Administrador without pricing.manage cannot manage', function () {
    $administrator = administrator();
    $scheme = PriceScheme::factory()->create();

    expect(Gate::forUser($administrator)->allows('manage', $scheme))->toBeTrue()
        ->and(Gate::forUser($administrator)->allows('view', $scheme))->toBeTrue();

    $administrator->roles()->first()?->permissions()->detach(
        Permission::query()->where('name', PermissionName::PricingManage->value)->value('id'),
    );

    expect(Gate::forUser($administrator->fresh())->denies('manage', $scheme))->toBeTrue()
        ->and(Gate::forUser($administrator->fresh())->allows('view', $scheme))->toBeTrue();
});

it('COT-022 does not rely on a Gate::before superadmin hook or on role names', function () {
    expect(file_get_contents(app_path('Providers/AppServiceProvider.php')))->not->toContain('Gate::before')
        ->and(file_get_contents(app_path('Policies/PricingPolicy.php')))->not->toContain('hasRole');
});
