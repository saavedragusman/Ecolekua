<?php

use App\Actions\Users\CreateUser;
use App\Enums\AuditAction;
use App\Enums\PermissionName;
use App\Exceptions\BusinessRuleViolation;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function newUserPayload(array $overrides = []): array
{
    return [
        'first_name' => 'Ana',
        'last_name' => 'Pérez',
        'email' => 'ana@ecolekua.com',
        'password' => 'clave-temporal-1',
        'roles' => [Role::factory()->create()->id],
        ...$overrides,
    ];
}

it('E-11 creates an active user with a pending forced password change, roles and audit', function () {
    $actor = userWithPermissions(PermissionName::UsersCreate);
    $role = Role::factory()->create(['name' => 'Ventas de prueba']);

    $this->actingAs($actor)
        ->post('/users', newUserPayload(['roles' => [$role->id]]))
        ->assertRedirect();

    $created = User::query()->where('email', 'ana@ecolekua.com')->firstOrFail();

    expect($created->is_active)->toBeTrue()
        ->and($created->must_change_password)->toBeTrue()
        ->and($created->roles->pluck('id')->all())->toBe([$role->id])
        ->and(Hash::check('clave-temporal-1', $created->password))->toBeTrue();

    $audit = AuditLog::query()->where('action', AuditAction::UserCreated->value)->sole();

    expect($audit->actor_id)->toBe($actor->id)
        ->and($audit->entity_id)->toBe($created->id)
        ->and($audit->new_values)->toEqual([
            'first_name' => 'Ana',
            'last_name' => 'Pérez',
            'email' => 'ana@ecolekua.com',
            'is_active' => true,
            'roles' => [['id' => $role->id, 'name' => 'Ventas de prueba']],
        ])
        ->and(json_encode($audit->getAttributes()))->not->toContain('clave-temporal-1')
        ->and(json_encode($audit->getAttributes()))->not->toContain($created->password);
});

it('E-11 accepts several roles', function () {
    $actor = userWithPermissions(PermissionName::UsersCreate);
    $roles = Role::factory()->count(2)->create();

    $this->actingAs($actor)
        ->post('/users', newUserPayload(['roles' => $roles->pluck('id')->all()]))
        ->assertRedirect();

    expect(User::query()->where('email', 'ana@ecolekua.com')->firstOrFail()->roles)->toHaveCount(2);
});

it('FND-007 persists identifier, names, email, hashed password, status, forced-change flag and dates', function () {
    $actor = userWithPermissions(PermissionName::UsersCreate);

    $this->actingAs($actor)->post('/users', newUserPayload())->assertRedirect();

    $created = User::query()->where('email', 'ana@ecolekua.com')->firstOrFail();

    expect($created->id)->toBeInt()
        ->and($created->first_name)->toBe('Ana')
        ->and($created->last_name)->toBe('Pérez')
        ->and($created->email)->toBe('ana@ecolekua.com')
        ->and($created->password)->not->toBe('clave-temporal-1')
        ->and(Hash::isHashed($created->password))->toBeTrue()
        ->and($created->is_active)->toBeTrue()
        ->and($created->must_change_password)->toBeTrue()
        ->and($created->created_at)->not->toBeNull()
        ->and($created->updated_at)->not->toBeNull();
});

it('E-12 rejects creating a user whose email belongs to an active user, normalizing before comparing', function () {
    $actor = userWithPermissions(PermissionName::UsersCreate);
    User::factory()->create(['email' => 'ana@ecolekua.com']);
    $before = User::query()->count();

    $this->actingAs($actor)
        ->post('/users', newUserPayload(['email' => '  ANA@Ecolekua.com ']))
        ->assertSessionHasErrors('email');

    expect(User::query()->count())->toBe($before)
        ->and(AuditLog::query()->where('action', AuditAction::UserCreated->value)->count())->toBe(0);
});

it('E-12 rejects creating a user whose email belongs to an inactive user', function () {
    $actor = userWithPermissions(PermissionName::UsersCreate);
    User::factory()->inactive()->create(['email' => 'ana@ecolekua.com']);
    $before = User::query()->count();

    $this->actingAs($actor)
        ->post('/users', newUserPayload())
        ->assertSessionHasErrors('email');

    expect(User::query()->count())->toBe($before);
});

it('E-13 rejects creating a user with no roles', function (mixed $roles) {
    $actor = userWithPermissions(PermissionName::UsersCreate);
    $before = User::query()->count();

    $payload = newUserPayload();
    $roles === 'omit' ? $payload = collect($payload)->except('roles')->all() : $payload['roles'] = $roles;

    $this->actingAs($actor)->post('/users', $payload)->assertSessionHasErrors('roles');

    expect(User::query()->count())->toBe($before);
})->with([
    'roles omitted' => ['omit'],
    'empty list' => [[]],
]);

it('E-13 makes the CreateUser action itself refuse an empty role list and write nothing', function () {
    $actor = userWithPermissions(PermissionName::UsersCreate);
    $before = User::query()->count();

    expect(fn () => app(CreateUser::class)->handle(newUserPayload(['roles' => []]), $actor))
        ->toThrow(BusinessRuleViolation::class);

    expect(User::query()->count())->toBe($before)
        ->and(AuditLog::query()->where('action', AuditAction::UserCreated->value)->count())->toBe(0);
});

it('E-16 rejects a temporary password shorter than 10 characters when creating a user', function () {
    $actor = userWithPermissions(PermissionName::UsersCreate);
    $before = User::query()->count();

    $this->actingAs($actor)
        ->post('/users', newUserPayload(['password' => 'corta1234']))
        ->assertSessionHasErrors('password');

    expect(User::query()->count())->toBe($before);
});

it('FND-010 rejects missing names and an invalid email when creating a user', function () {
    $actor = userWithPermissions(PermissionName::UsersCreate);

    $this->actingAs($actor)
        ->post('/users', newUserPayload(['first_name' => '', 'last_name' => '', 'email' => 'no-es-correo']))
        ->assertSessionHasErrors(['first_name', 'last_name', 'email']);
});

it('FND-010 forbids creating a user without users.create and has no effect', function () {
    $actor = userWithPermissions(PermissionName::UsersView, PermissionName::UsersUpdate);
    $before = User::query()->count();

    $this->actingAs($actor)->post('/users', newUserPayload())->assertForbidden();

    expect(User::query()->count())->toBe($before);
});

it('FND-010 checks the permission, not the role name, and any role holding it is enough', function () {
    $actor = userWithPermissions(PermissionName::UsersCreate);
    $actor->roles()->first()->update(['name' => 'Administrador de creación']);

    $this->actingAs($actor)->post('/users', newUserPayload())->assertRedirect();

    $noPermission = User::factory()->create();
    $noPermission->roles()->attach(Role::factory()->create(['name' => 'Administrador de prueba']));

    $this->actingAs($noPermission)->post('/users', newUserPayload(['email' => 'otra@ecolekua.com']))->assertForbidden();
});

it('FND-010 shows the user list, detail, create and edit routes only with the matching permission', function () {
    $viewer = userWithPermissions(PermissionName::UsersView);
    $target = User::factory()->create();

    $this->actingAs($viewer)->get('/users')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('users/Index')->has('users.data', 2));
    $this->actingAs($viewer)->get("/users/{$target->id}")->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('users/Show')->where('user.id', $target->id));
    $this->actingAs($viewer)->get('/users/create')->assertForbidden();
    $this->actingAs($viewer)->get("/users/{$target->id}/edit")->assertForbidden();

    $creator = userWithPermissions(PermissionName::UsersCreate, PermissionName::UsersUpdate);
    Role::factory()->create();

    $this->actingAs($creator)->get('/users/create')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('users/Create')->has('roles'));
    $this->actingAs($creator)->get("/users/{$target->id}/edit")->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('users/Edit')->where('user.id', $target->id));
    $this->actingAs($creator)->get('/users')->assertForbidden();
    $this->actingAs($creator)->get("/users/{$target->id}")->assertForbidden();
});

it('FND-010 exposes the role options on the detail page only to users who can assign roles', function () {
    $target = User::factory()->create();
    Role::factory()->create(['name' => 'Rol de ejemplo']);

    $viewer = userWithPermissions(PermissionName::UsersView);
    $this->actingAs($viewer)->get("/users/{$target->id}")->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('users/Show')->has('roles', 0));

    $assigner = userWithPermissions(PermissionName::UsersView, PermissionName::UsersAssignRoles);
    $this->actingAs($assigner)->get("/users/{$target->id}")->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('users/Show')->where('roles', fn ($roles) => collect($roles)->pluck('name')->contains('Rol de ejemplo')));
});
