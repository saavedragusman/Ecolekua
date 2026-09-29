<?php

use App\Exceptions\AuditLogIsImmutable;
use App\Models\AuditLog;
use App\Policies\AuditLogPolicy;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

function insertAuditRow(string $action = 'auth.login', string $email = 'ana@ecolekua.com'): int
{
    return DB::table('audit_logs')->insertGetId([
        'created_at' => now()->utc()->format('Y-m-d H:i:s.u'),
        'actor_email' => $email,
        'action' => $action,
        'ip_address' => '127.0.0.1',
        'context' => json_encode(['source' => 'test']),
    ]);
}

it('E-29 (a) no registered route other than GET audit.index targets audit records', function () {
    $auditRoutes = collect(Route::getRoutes()->getRoutes())->filter(function ($route): bool {
        $action = $route->getActionName();

        return str_contains($route->uri(), 'audit')
            || str_contains($action, 'Audit')
            || str_starts_with((string) $route->getName(), 'audit');
    });

    expect($auditRoutes)->toHaveCount(1);

    $route = $auditRoutes->first();

    expect($route->getName())->toBe('audit.index')
        ->and($route->methods())->toBe(['GET', 'HEAD'])
        ->and($route->uri())->toBe('audit');
});

it('E-29 (a) audit records expose no write ability in the policy', function () {
    $abilities = collect(get_class_methods(AuditLogPolicy::class))
        ->reject(fn (string $method): bool => str_starts_with($method, '__'))
        ->values()
        ->all();

    expect($abilities)->toBe(['viewAny']);
});

it('E-29 (b) the model refuses to update an audit record', function () {
    $id = insertAuditRow();

    $log = AuditLog::findOrFail($id);
    $log->action = 'tampered';

    expect(fn () => $log->save())->toThrow(AuditLogIsImmutable::class);
    expect(fn () => AuditLog::findOrFail($id)->update(['action' => 'tampered']))
        ->toThrow(AuditLogIsImmutable::class);
});

it('E-29 (b) the model refuses to delete an audit record', function () {
    $id = insertAuditRow();

    expect(fn () => AuditLog::findOrFail($id)->delete())->toThrow(AuditLogIsImmutable::class);
});

it('E-29 (c) the database rejects UPDATE on audit_logs with SQLSTATE 45000', function () {
    $id = insertAuditRow();

    try {
        DB::table('audit_logs')->where('id', $id)->update(['action' => 'tampered']);
        $this->fail('UPDATE on audit_logs was not rejected.');
    } catch (QueryException $e) {
        expect($e->getCode())->toBe('45000')
            ->and($e->getMessage())->toContain('audit_logs is append-only');
    }
});

it('E-29 (c) the database rejects DELETE on audit_logs with SQLSTATE 45000', function () {
    $id = insertAuditRow();

    try {
        DB::table('audit_logs')->where('id', $id)->delete();
        $this->fail('DELETE on audit_logs was not rejected.');
    } catch (QueryException $e) {
        expect($e->getCode())->toBe('45000')
            ->and($e->getMessage())->toContain('audit_logs is append-only');
    }
});

it('E-29 (d) the row is unchanged after every rejected attempt', function () {
    $id = insertAuditRow('auth.login', 'ana@ecolekua.com');
    $before = (array) DB::table('audit_logs')->where('id', $id)->first();

    try {
        AuditLog::findOrFail($id)->update(['action' => 'tampered']);
    } catch (AuditLogIsImmutable) {
    }
    try {
        AuditLog::findOrFail($id)->delete();
    } catch (AuditLogIsImmutable) {
    }
    try {
        DB::table('audit_logs')->where('id', $id)->update(['action' => 'tampered']);
    } catch (QueryException) {
    }
    try {
        DB::table('audit_logs')->where('id', $id)->delete();
    } catch (QueryException) {
    }

    $after = (array) DB::table('audit_logs')->where('id', $id)->first();

    expect($after)->toBe($before)
        ->and($after['action'])->toBe('auth.login')
        ->and(DB::table('audit_logs')->count())->toBe(1);
});
