<?php

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use App\Support\Audit\AuditOrigin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

function makeActor(string $email = 'ana@ecolekua.com'): User
{
    return User::factory()->create(['email' => $email]);
}

it('FND-022 inserts one row with actor, entity, action and values', function () {
    $actor = makeActor();
    $role = Role::query()->create(['name' => 'Rol de prueba']);

    $log = app(RecordAuditEvent::class)->handle(
        AuditAction::RoleCreated,
        $actor,
        $role,
        oldValues: [],
        newValues: ['name' => 'Rol de prueba'],
    );

    expect(AuditLog::count())->toBe(1)
        ->and($log)->toBeInstanceOf(AuditLog::class)
        ->and($log->action)->toBe('roles.created')
        ->and($log->actor_id)->toBe($actor->id)
        ->and($log->actor_email)->toBe('ana@ecolekua.com')
        ->and($log->entity_type)->toBe(Role::class)
        ->and($log->entity_id)->toBe($role->id)
        ->and($log->new_values)->toBe(['name' => 'Rol de prueba']);
});

it('FND-023 stores the moment of the event in UTC with microsecond precision', function () {
    $this->travelTo(now()->utc()->setMicroseconds(123456));

    $log = app(RecordAuditEvent::class)->handle(AuditAction::LoginFailed, null, actorEmail: 'x@ecolekua.com');

    $raw = DB::table('audit_logs')->where('id', $log->id)->value('created_at');

    expect($raw)->toEndWith('.123456');
});

it('FND-012 redacts password-like keys from old_values, new_values and context', function () {
    $log = app(RecordAuditEvent::class)->handle(
        AuditAction::UserUpdated,
        makeActor(),
        oldValues: ['first_name' => 'Ana', 'password' => 'old-secret'],
        newValues: ['first_name' => 'Anita', 'nested' => ['current_password' => 'x', 'ok' => 1]],
        context: ['remember_token' => 'abc', 'reason' => 'test'],
    );

    $stored = DB::table('audit_logs')->where('id', $log->id)->first();
    $json = json_encode($stored);

    expect(json_decode($stored->old_values, true))->toBe(['first_name' => 'Ana'])
        ->and(json_decode($stored->new_values, true))->toEqual(['first_name' => 'Anita', 'nested' => ['ok' => 1]])
        ->and(json_decode($stored->context, true))->toBe(['reason' => 'test'])
        ->and($json)->not->toContain('secret')->not->toContain('remember_token');
});

it('FND-022 uses the attempted email and no actor for a failed login', function () {
    $log = app(RecordAuditEvent::class)->handle(
        AuditAction::LoginFailed,
        null,
        actorEmail: 'ana@ecolekua.com',
    );

    expect($log->actor_id)->toBeNull()
        ->and($log->actor_email)->toBe('ana@ecolekua.com')
        ->and($log->entity_type)->toBeNull()
        ->and($log->entity_id)->toBeNull();
});

it('FND-022 defaults the origin to the current request IP with no extra context', function () {
    $this->app->instance('request', Request::create('/x', 'GET', server: ['REMOTE_ADDR' => '203.0.113.9']));

    $log = app(RecordAuditEvent::class)->handle(AuditAction::Logout, makeActor(), context: ['a' => 1]);

    expect($log->ip_address)->toBe('203.0.113.9')
        ->and($log->context)->toBe(['a' => 1]);
});

it('DEC-021 a console origin stores no IP, no actor and merges its context', function () {
    $log = app(RecordAuditEvent::class)->handle(
        AuditAction::UserCreated,
        null,
        newValues: ['email' => 'root@ecolekua.com'],
        context: ['extra' => 'kept'],
        origin: AuditOrigin::console('users:create-administrator'),
    );

    expect($log->ip_address)->toBeNull()
        ->and($log->actor_id)->toBeNull()
        ->and($log->actor_email)->toBeNull()
        ->and($log->context)->toMatchArray([
            'extra' => 'kept',
            'source' => 'console',
            'command' => 'users:create-administrator',
        ])
        ->and($log->context)->toHaveKeys(['os_user', 'host']);
});

it('FND-022 is written inside the caller transaction and rolls back with it', function () {
    try {
        DB::transaction(function () {
            app(RecordAuditEvent::class)->handle(AuditAction::Logout, makeActor('rollback@ecolekua.com'));

            throw new RuntimeException('force rollback');
        });
    } catch (RuntimeException) {
    }

    expect(AuditLog::count())->toBe(0);
});
