<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->actor = administrator();
    $this->active = User::factory()->count(2)->create(['is_active' => true]);
    $this->inactive = User::factory()->count(3)->inactive()->create();
});

/**
 * @return list<int>
 */
function listedUserIds(Assert $page): array
{
    return collect($page->toArray()['props']['users']['data'])->pluck('id')->all();
}

it('DEC-023 lists only active users by default', function () {
    $this->actingAs($this->actor)->get('/users')->assertInertia(function (Assert $page) {
        $page->component('users/Index')->where('status', 'active');

        expect(listedUserIds($page))->toEqualCanonicalizing([$this->actor->id, ...$this->active->pluck('id')->all()]);
    });
});

it('DEC-023 lists only inactive users with status=inactive', function () {
    $this->actingAs($this->actor)->get('/users?status=inactive')->assertInertia(function (Assert $page) {
        $page->where('status', 'inactive');

        expect(listedUserIds($page))->toEqualCanonicalizing($this->inactive->pluck('id')->all());
    });
});

it('DEC-023 lists active and inactive users with status=all', function () {
    $this->actingAs($this->actor)->get('/users?status=all')->assertInertia(function (Assert $page) {
        $page->where('status', 'all');

        expect(listedUserIds($page))->toEqualCanonicalizing([
            $this->actor->id,
            ...$this->active->pluck('id')->all(),
            ...$this->inactive->pluck('id')->all(),
        ]);
    });
});

it('DEC-023 treats an unknown status value as active', function () {
    $this->actingAs($this->actor)->get('/users?status=borrados')->assertInertia(function (Assert $page) {
        $page->where('status', 'active');

        expect(listedUserIds($page))->toEqualCanonicalizing([$this->actor->id, ...$this->active->pluck('id')->all()]);
    });
});

it('DEC-023 treats a non-string status value as active', function () {
    $this->actingAs($this->actor)->get('/users?status[]=inactive')->assertInertia(function (Assert $page) {
        $page->where('status', 'active');

        expect(listedUserIds($page))->toEqualCanonicalizing([$this->actor->id, ...$this->active->pluck('id')->all()]);
    });
});

it('DEC-023 sends the count of every view resolved by the backend', function () {
    $this->actingAs($this->actor)->get('/users')->assertInertia(fn (Assert $page) => $page
        ->where('counts.active', 3)
        ->where('counts.inactive', 3)
        ->where('counts.all', 6));
});

it('DEC-023 keeps the counts independent of the selected view', function () {
    $this->actingAs($this->actor)->get('/users?status=inactive')->assertInertia(fn (Assert $page) => $page
        ->where('counts.active', 3)
        ->where('counts.inactive', 3)
        ->where('counts.all', 6));
});

it('DEC-023 keeps the status in the pagination links', function () {
    User::factory()->count(16)->inactive()->create();

    $this->actingAs($this->actor)->get('/users?status=inactive')->assertInertia(function (Assert $page) {
        $links = $page->toArray()['props']['users'];

        expect($links['next_page_url'])->toContain('status=inactive')
            ->and($links['next_page_url'])->toContain('page=2');
    });
});

it('DEC-023 keeps requiring users.view to list users', function () {
    $this->actingAs(User::factory()->create())->get('/users?status=all')->assertForbidden();
});
