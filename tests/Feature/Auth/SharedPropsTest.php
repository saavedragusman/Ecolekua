<?php

use App\Enums\PermissionName;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

it('shares only the explicit user DTO and the permission names', function () {
    $user = User::factory()->withPermissions(PermissionName::UsersView, PermissionName::AuditView)->create([
        'first_name' => 'Ana',
        'last_name' => 'Pérez',
        'email' => 'ana@ecolekua.com',
    ]);

    $this->actingAs($user)->get('/')->assertInertia(fn (Assert $page) => $page
        ->where('auth.user', [
            'id' => $user->id,
            'first_name' => 'Ana',
            'last_name' => 'Pérez',
            'email' => 'ana@ecolekua.com',
            'must_change_password' => false,
        ])
        ->where('auth.permissions', ['audit.view', 'users.view'])
    );
});

it('shares no user and no permissions to a guest on the login page', function () {
    $this->get('/login')->assertInertia(fn (Assert $page) => $page
        ->component('auth/Login')
        ->where('auth.user', null)
        ->where('auth.permissions', [])
    );
});
