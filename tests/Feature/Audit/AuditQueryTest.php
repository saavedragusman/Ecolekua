<?php

use App\Enums\AuditAction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->viewer = userWithPermissions('audit.view');
});

/**
 * Inserts an audit row with an explicit UTC instant (`created_at` is timestamp(6) UTC).
 *
 * @param  array<string, mixed>  $attributes
 */
function auditRowAt(string $utc, array $attributes = []): int
{
    return DB::table('audit_logs')->insertGetId(array_merge([
        'created_at' => $utc,
        'actor_id' => null,
        'actor_email' => 'ana@ecolekua.com',
        'action' => AuditAction::LoginSucceeded->value,
        'ip_address' => '127.0.0.1',
    ], $attributes));
}

/**
 * @return list<int>
 */
function auditListedIds(Assert $page): array
{
    return collect($page->toArray()['props']['logs']['data'])->pluck('id')->all();
}

it('FND-025 requires the audit.view permission', function () {
    $this->actingAs(userWithPermissions('users.view'))->get('/audit')->assertForbidden();
    $this->actingAs($this->viewer)->get('/audit')->assertOk();
});

it('FND-025 renders the audit page component for a user with audit.view', function () {
    $this->actingAs($this->viewer)->get('/audit')->assertInertia(
        fn (Assert $page) => $page->component('audit/Index')
    );
});

it('FND-025 filters by user', function () {
    $ana = User::factory()->create();
    $luis = User::factory()->create();
    $anaRow = auditRowAt('2026-03-10 10:00:00', ['actor_id' => $ana->id]);
    auditRowAt('2026-03-10 11:00:00', ['actor_id' => $luis->id]);

    $this->actingAs($this->viewer)->get('/audit?user_id='.$ana->id)
        ->assertInertia(fn (Assert $page) => expect(auditListedIds($page))->toBe([$anaRow]));
});

it('FND-025 filters by action', function () {
    $login = auditRowAt('2026-03-10 10:00:00', ['action' => AuditAction::LoginSucceeded->value]);
    auditRowAt('2026-03-10 11:00:00', ['action' => AuditAction::UserCreated->value]);

    $this->actingAs($this->viewer)->get('/audit?action='.AuditAction::LoginSucceeded->value)
        ->assertInertia(fn (Assert $page) => expect(auditListedIds($page))->toBe([$login]));
});

it('FND-025 filters by date range', function () {
    $before = auditRowAt('2026-03-08 15:00:00');
    $inside = auditRowAt('2026-03-10 15:00:00');
    $after = auditRowAt('2026-03-13 15:00:00');

    $this->actingAs($this->viewer)->get('/audit?from=2026-03-09&to=2026-03-11')
        ->assertInertia(fn (Assert $page) => expect(auditListedIds($page))->toBe([$inside]));

    $this->actingAs($this->viewer)->get('/audit?from=2026-03-10')
        ->assertInertia(fn (Assert $page) => expect(auditListedIds($page))->toBe([$after, $inside]));

    $this->actingAs($this->viewer)->get('/audit?to=2026-03-10')
        ->assertInertia(fn (Assert $page) => expect(auditListedIds($page))->toBe([$inside, $before]));
});

it('FND-025 combines the filters', function () {
    $ana = User::factory()->create();
    $match = auditRowAt('2026-03-10 15:00:00', ['actor_id' => $ana->id, 'action' => AuditAction::Logout->value]);
    auditRowAt('2026-03-10 15:00:00', ['actor_id' => $ana->id, 'action' => AuditAction::LoginSucceeded->value]);
    auditRowAt('2026-03-10 15:00:00', ['actor_email' => 'otro@ecolekua.com', 'action' => AuditAction::Logout->value]);

    $this->actingAs($this->viewer)
        ->get('/audit?user_id='.$ana->id.'&action='.AuditAction::Logout->value.'&from=2026-03-10&to=2026-03-10')
        ->assertInertia(fn (Assert $page) => expect(auditListedIds($page))->toBe([$match]));
});

it('FND-025 rejects invalid filters', function () {
    $this->actingAs($this->viewer)->from('/audit')->get('/audit?from=10/03/2026')->assertSessionHasErrors('from');
    $this->actingAs($this->viewer)->from('/audit')->get('/audit?from=2026-03-10&to=2026-03-09')->assertSessionHasErrors('to');
    $this->actingAs($this->viewer)->from('/audit')->get('/audit?action=no.existe')->assertSessionHasErrors('action');
    $this->actingAs($this->viewer)->from('/audit')->get('/audit?user_id=abc')->assertSessionHasErrors('user_id');
});

it('FND-025 lists the newest first, paginates and keeps the filters in the page links', function () {
    foreach (range(1, 30) as $i) {
        auditRowAt('2026-03-10 10:00:00', ['action' => AuditAction::Logout->value, 'actor_email' => "u{$i}@ecolekua.com"]);
    }

    $this->actingAs($this->viewer)->get('/audit?action='.AuditAction::Logout->value)
        ->assertInertia(function (Assert $page) {
            $logs = $page->toArray()['props']['logs'];

            expect($logs['data'])->toHaveCount(25)
                ->and($logs['last_page'])->toBe(2)
                ->and($logs['next_page_url'])->toContain('action=auth.logout');
        });
});

it('FND-025 sends the filters, the user options and the action options', function () {
    $ana = User::factory()->create(['first_name' => 'Ana', 'last_name' => 'Pérez']);

    $this->actingAs($this->viewer)->get('/audit?user_id='.$ana->id.'&from=2026-03-09')
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.user_id', (string) $ana->id)
            ->where('filters.from', '2026-03-09')
            ->where('filters.to', null)
            ->where('filters.action', null)
            ->where('actions.0.value', AuditAction::cases()[0]->value)
            ->where('actions.0.label', AuditAction::cases()[0]->label())
            ->has('actions', count(AuditAction::cases()))
            ->where('users', fn ($users) => collect($users)->contains(fn ($u) => $u['id'] === $ana->id && $u['name'] === 'Ana Pérez'))
        );
});

it('FND-023 exposes no password or hash even if one were present in a stored payload', function () {
    auditRowAt('2026-03-10 10:00:00', [
        'old_values' => json_encode(['first_name' => 'Ana']),
        'new_values' => json_encode(['first_name' => 'Anita', 'password' => '$2y$12$hashhashhash']),
        'context' => json_encode(['note' => ['current_password' => 'secret-value']]),
    ]);

    $this->actingAs($this->viewer)->get('/audit')->assertInertia(function (Assert $page) {
        $row = $page->toArray()['props']['logs']['data'][0];
        $json = json_encode($row);

        expect($json)->not->toContain('hashhashhash')
            ->and($json)->not->toContain('secret-value')
            ->and($row['old_values'])->toBe(['first_name' => 'Ana'])
            ->and($row['new_values'])->toBe(['first_name' => 'Anita']);
    });
});

it('FND-025 displays the actor name, the entity and the action label', function () {
    $ana = User::factory()->create(['first_name' => 'Ana', 'last_name' => 'Pérez']);
    auditRowAt('2026-03-10 10:00:00', [
        'actor_id' => $ana->id,
        'action' => AuditAction::UserCreated->value,
        'entity_type' => User::class,
        'entity_id' => 7,
    ]);

    $this->actingAs($this->viewer)->get('/audit')->assertInertia(fn (Assert $page) => $page
        ->where('logs.data.0.actor', 'Ana Pérez')
        ->where('logs.data.0.action', AuditAction::UserCreated->value)
        ->where('logs.data.0.action_label', AuditAction::UserCreated->label())
        ->where('logs.data.0.entity', 'User #7')
        ->where('logs.data.0.ip_address', '127.0.0.1')
    );
});

it('DEC-019 filters by day in America/Caracas using half-open UTC ranges', function () {
    // 2026-03-10 03:30:00 UTC = 09/03/2026 23:30:00 Caracas; 04:00:00 UTC = 10/03/2026 00:00:00.
    $lastMinutes = auditRowAt('2026-03-10 03:30:00');
    $firstInstant = auditRowAt('2026-03-10 04:00:00');

    $this->actingAs($this->viewer)->get('/audit?from=2026-03-09&to=2026-03-09')
        ->assertInertia(fn (Assert $page) => expect(auditListedIds($page))->toBe([$lastMinutes]));

    $this->actingAs($this->viewer)->get('/audit?from=2026-03-10&to=2026-03-10')
        ->assertInertia(fn (Assert $page) => expect(auditListedIds($page))->toBe([$firstInstant]));
});

it('DEC-019 keeps events in the last microsecond of the day inside that day', function () {
    $lastMoment = auditRowAt('2026-03-10 03:59:59.999999');

    $this->actingAs($this->viewer)->get('/audit?from=2026-03-09&to=2026-03-09')
        ->assertInertia(fn (Assert $page) => expect(auditListedIds($page))->toBe([$lastMoment]));
});

it('DEC-019 prints occurred_at in the operating timezone and leaves storage in UTC', function () {
    auditRowAt('2026-03-10 03:30:00');

    $this->actingAs($this->viewer)->get('/audit')
        ->assertInertia(fn (Assert $page) => $page->where('logs.data.0.occurred_at', '09/03/2026 23:30:00'));

    expect(config('app.timezone'))->toBe('UTC')
        ->and(config('app.operating_timezone'))->toBe('America/Caracas')
        ->and(DB::table('audit_logs')->value('created_at'))->toStartWith('2026-03-10 03:30:00');
});
