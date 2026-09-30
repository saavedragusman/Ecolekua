<?php

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

const DEC021_TEMPORARY_PASSWORD = 'Tmp-DEC021-abcdefgh';

beforeEach(function () {
    Str::createRandomStringsUsing(fn () => DEC021_TEMPORARY_PASSWORD);
});

afterEach(function () {
    Str::createRandomStringsNormally();
});

/**
 * Runs the command answering the three prompts in order (first name, last name, email).
 */
function runCreateAdministrator(string $firstName = 'Ana', string $lastName = 'Pérez', string $email = 'ana@ecolekua.com')
{
    return test()
        ->artisan('users:create-administrator')
        ->expectsQuestion('Nombre', $firstName)
        ->expectsQuestion('Apellido', $lastName)
        ->expectsQuestion('Correo electrónico', $email);
}

it('DEC-021 creates an active administrator with a temporary password that must be changed', function () {
    runCreateAdministrator()
        ->expectsOutputToContain(DEC021_TEMPORARY_PASSWORD)
        ->assertExitCode(0);

    $user = User::query()->where('email', 'ana@ecolekua.com')->sole();

    expect($user->first_name)->toBe('Ana')
        ->and($user->last_name)->toBe('Pérez')
        ->and($user->is_active)->toBeTrue()
        ->and($user->must_change_password)->toBeTrue()
        ->and(Hash::check(DEC021_TEMPORARY_PASSWORD, $user->password))->toBeTrue()
        ->and($user->roles)->toHaveCount(1)
        ->and($user->roles->first()->is_protected)->toBeTrue();
});

it('DEC-021 audits a console event without credentials (FND-022)', function () {
    runCreateAdministrator()->assertExitCode(0);

    $rows = AuditLog::query()->where('action', AuditAction::UserCreated->value)->get();
    expect($rows)->toHaveCount(1);

    $row = $rows->first();
    $user = User::query()->where('email', 'ana@ecolekua.com')->sole();

    expect($row->actor_id)->toBeNull()
        ->and($row->actor_email)->toBeNull()
        ->and($row->ip_address)->toBeNull()
        ->and($row->context['source'])->toBe('console')
        ->and($row->context['command'])->toBe('users:create-administrator')
        ->and($row->entity_id)->toBe($user->id);

    $serialized = json_encode($row->getAttributes());
    expect($serialized)->not->toContain(DEC021_TEMPORARY_PASSWORD)
        ->and($serialized)->not->toContain($user->password);
});

it('DEC-021 never logs the temporary password', function () {
    Log::spy();

    runCreateAdministrator()->assertExitCode(0);

    Log::shouldNotHaveReceived('log', fn ($level, $message) => str_contains((string) $message, DEC021_TEMPORARY_PASSWORD));
    Log::shouldNotHaveReceived('info', fn ($message) => str_contains((string) $message, DEC021_TEMPORARY_PASSWORD));
    Log::shouldNotHaveReceived('warning', fn ($message) => str_contains((string) $message, DEC021_TEMPORARY_PASSWORD));
    Log::shouldNotHaveReceived('error', fn ($message) => str_contains((string) $message, DEC021_TEMPORARY_PASSWORD));
});

it('DEC-021 refuses when an active user already holds the protected role (FND-020)', function () {
    administrator();
    $users = User::query()->count();
    $audits = AuditLog::query()->count();

    runCreateAdministrator()
        ->expectsOutputToContain('Ya existe un administrador activo')
        ->assertExitCode(1);

    expect(User::query()->count())->toBe($users)
        ->and(AuditLog::query()->count())->toBe($audits);
});

it('DEC-021 runs when the only protected-role holders are inactive', function () {
    $inactive = administrator();
    $inactive->forceFill(['is_active' => false])->save();

    runCreateAdministrator()->assertExitCode(0);

    expect(User::query()->where('email', 'ana@ecolekua.com')->where('is_active', true)->exists())->toBeTrue();
});

it('DEC-021 rejects an invalid email and writes nothing', function () {
    runCreateAdministrator(email: 'no-es-un-correo')->assertExitCode(1);

    expect(User::query()->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe(0);
});

it('DEC-021 rejects a duplicate email after normalization and writes nothing', function () {
    User::factory()->create(['email' => 'ana@ecolekua.com']);
    $users = User::query()->count();

    runCreateAdministrator(email: '  ANA@Ecolekua.com ')->assertExitCode(1);

    expect(User::query()->count())->toBe($users)
        ->and(AuditLog::query()->count())->toBe(0);
});

it('DEC-021 refuses non-interactive runs and writes nothing', function () {
    $this->artisan('users:create-administrator', ['--no-interaction' => true])
        ->expectsOutputToContain('interactivo')
        ->assertExitCode(1);

    expect(User::query()->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe(0);
});

it('DEC-021 fails with a clear message when the protected role does not exist', function () {
    Role::query()->where('is_protected', true)->delete();

    runCreateAdministrator()
        ->expectsOutputToContain('FoundationSeeder')
        ->assertExitCode(1);

    expect(User::query()->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe(0);
});

it('DEC-021 sends the first login with the temporary password to the password change (FND-014)', function () {
    runCreateAdministrator()->assertExitCode(0);

    $this->post('/login', ['email' => 'ana@ecolekua.com', 'password' => DEC021_TEMPORARY_PASSWORD])
        ->assertRedirect(route('password.edit'));
});
