<?php

use App\Actions\Users\UpdateUser;
use App\Enums\AuditAction;
use App\Enums\PermissionName;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function updatePayload(User $user, array $overrides = []): array
{
    return [
        'first_name' => $user->first_name,
        'last_name' => $user->last_name,
        'email' => $user->email,
        ...$overrides,
    ];
}

it('FND-010 updates first name, last name and email', function () {
    $actor = userWithPermissions(PermissionName::UsersUpdate);
    $target = User::factory()->create(['first_name' => 'Ana', 'last_name' => 'Pérez', 'email' => 'ana@ecolekua.com']);

    $this->actingAs($actor)
        ->put("/users/{$target->id}", ['first_name' => 'Luisa', 'last_name' => 'Gómez', 'email' => ' LUISA@Ecolekua.com '])
        ->assertRedirect();

    $fresh = $target->fresh();

    expect($fresh->first_name)->toBe('Luisa')
        ->and($fresh->last_name)->toBe('Gómez')
        ->and($fresh->email)->toBe('luisa@ecolekua.com');
});

it('FND-010 ignores fields other than the three identity fields', function () {
    $actor = userWithPermissions(PermissionName::UsersUpdate);
    $target = User::factory()->create();
    $hash = $target->password;

    $this->actingAs($actor)
        ->put("/users/{$target->id}", updatePayload($target, ['password' => 'otra-clave-larga', 'is_active' => false, 'must_change_password' => true]))
        ->assertRedirect();

    $fresh = $target->fresh();

    expect($fresh->password)->toBe($hash)
        ->and($fresh->is_active)->toBeTrue()
        ->and($fresh->must_change_password)->toBeFalse();
});

it('E-12 rejects updating a user to the email of another active user', function () {
    $actor = userWithPermissions(PermissionName::UsersUpdate);
    User::factory()->create(['email' => 'ocupado@ecolekua.com']);
    $target = User::factory()->create(['email' => 'libre@ecolekua.com']);

    $this->actingAs($actor)
        ->put("/users/{$target->id}", updatePayload($target, ['email' => 'Ocupado@Ecolekua.com ']))
        ->assertSessionHasErrors('email');

    expect($target->fresh()->email)->toBe('libre@ecolekua.com')
        ->and(AuditLog::query()->where('action', AuditAction::UserUpdated->value)->count())->toBe(0);
});

it('E-12 rejects updating a user to the email of an inactive user', function () {
    $actor = userWithPermissions(PermissionName::UsersUpdate);
    User::factory()->inactive()->create(['email' => 'inactivo@ecolekua.com']);
    $target = User::factory()->create(['email' => 'libre@ecolekua.com']);

    $this->actingAs($actor)
        ->put("/users/{$target->id}", updatePayload($target, ['email' => 'inactivo@ecolekua.com']))
        ->assertSessionHasErrors('email');

    expect($target->fresh()->email)->toBe('libre@ecolekua.com');
});

it('E-12 lets a user keep their own email when it is unchanged', function () {
    $actor = userWithPermissions(PermissionName::UsersUpdate);
    $target = User::factory()->create(['email' => 'mismo@ecolekua.com']);

    $this->actingAs($actor)
        ->put("/users/{$target->id}", updatePayload($target, ['first_name' => 'Nuevo']))
        ->assertSessionDoesntHaveErrors()
        ->assertRedirect();

    expect($target->fresh()->first_name)->toBe('Nuevo');
});

it('E-27 audits users.updated with only the changed fields as old and new values', function () {
    $actor = userWithPermissions(PermissionName::UsersUpdate);
    $target = User::factory()->create(['first_name' => 'Ana', 'last_name' => 'Pérez', 'email' => 'ana@ecolekua.com']);

    $this->actingAs($actor)
        ->put("/users/{$target->id}", updatePayload($target, ['email' => 'ana.nueva@ecolekua.com']))
        ->assertRedirect();

    $audit = AuditLog::query()->where('action', AuditAction::UserUpdated->value)->sole();

    expect($audit->actor_id)->toBe($actor->id)
        ->and($audit->entity_id)->toBe($target->id)
        ->and($audit->ip_address)->not->toBeNull()
        ->and($audit->old_values)->toEqual(['email' => 'ana@ecolekua.com'])
        ->and($audit->new_values)->toEqual(['email' => 'ana.nueva@ecolekua.com']);
});

it('E-27 records every changed identity field and nothing else', function () {
    $actor = userWithPermissions(PermissionName::UsersUpdate);
    $target = User::factory()->create(['first_name' => 'Ana', 'last_name' => 'Pérez', 'email' => 'ana@ecolekua.com']);

    $this->actingAs($actor)
        ->put("/users/{$target->id}", ['first_name' => 'Luisa', 'last_name' => 'Pérez', 'email' => 'ana@ecolekua.com'])
        ->assertRedirect();

    $audit = AuditLog::query()->where('action', AuditAction::UserUpdated->value)->sole();

    expect($audit->old_values)->toEqual(['first_name' => 'Ana'])
        ->and($audit->new_values)->toEqual(['first_name' => 'Luisa'])
        ->and(json_encode($audit->getAttributes()))->not->toContain($target->password);
});

it('E-27 writes no audit row when nothing changed', function () {
    $actor = userWithPermissions(PermissionName::UsersUpdate);
    $target = User::factory()->create();

    app(UpdateUser::class)->handle($target, updatePayload($target), $actor);

    expect(AuditLog::query()->where('action', AuditAction::UserUpdated->value)->count())->toBe(0);
});

it('FND-010 forbids updating a user without users.update and has no effect', function () {
    $actor = userWithPermissions(PermissionName::UsersView, PermissionName::UsersCreate);
    $target = User::factory()->create(['first_name' => 'Ana']);

    $this->actingAs($actor)
        ->put("/users/{$target->id}", updatePayload($target, ['first_name' => 'Intruso']))
        ->assertForbidden();

    expect($target->fresh()->first_name)->toBe('Ana')
        ->and(AuditLog::query()->where('action', AuditAction::UserUpdated->value)->count())->toBe(0);
});

it('FND-006 applies a permission revoked after the session started on the very next update', function () {
    $actor = userWithPermissions(PermissionName::UsersUpdate);
    $target = User::factory()->create(['first_name' => 'Ana']);

    $this->actingAs($actor)->put("/users/{$target->id}", updatePayload($target, ['first_name' => 'Uno']))->assertRedirect();

    $actor->roles()->detach();

    $this->actingAs($actor)->put("/users/{$target->id}", updatePayload($target, ['first_name' => 'Dos']))->assertForbidden();

    expect($target->fresh()->first_name)->toBe('Uno');
});

it('DEC-020 lets an administrator with users.update edit their own data', function () {
    $actor = userWithPermissions(PermissionName::UsersUpdate);

    $this->actingAs($actor)
        ->put("/users/{$actor->id}", updatePayload($actor, ['first_name' => 'Propio']))
        ->assertRedirect();

    $audit = AuditLog::query()->where('action', AuditAction::UserUpdated->value)->sole();

    expect($actor->fresh()->first_name)->toBe('Propio')
        ->and($audit->actor_id)->toBe($actor->id)
        ->and($audit->entity_id)->toBe($actor->id)
        ->and(Hash::check('password', $actor->fresh()->password))->toBeTrue();
});

it('FND-010 applies the same name and email rules on update as on creation', function () {
    $actor = userWithPermissions(PermissionName::UsersUpdate);
    $target = User::factory()->create();

    $this->actingAs($actor)
        ->put("/users/{$target->id}", ['first_name' => '', 'last_name' => str_repeat('a', 101), 'email' => 'no-es-correo'])
        ->assertSessionHasErrors(['first_name', 'last_name', 'email']);
});
