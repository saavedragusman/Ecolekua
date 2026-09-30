<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

it('renders the Home page for an authenticated user', function () {
    $this->actingAs(User::factory()->create())
        ->get('/')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Home'));
});

it('redirects a guest from Home to login', function () {
    $this->get('/')->assertRedirect(route('login'));
});
