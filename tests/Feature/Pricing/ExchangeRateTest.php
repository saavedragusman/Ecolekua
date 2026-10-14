<?php

use App\Actions\Pricing\CurrentExchangeRate;
use App\Enums\AuditAction;
use App\Enums\ExchangeRateOrigin;
use App\Enums\PermissionName;
use App\Events\Pricing\ExchangeRateFetchFailed;
use App\Models\AuditLog;
use App\Models\ExchangeRate;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Illuminate\Testing\TestResponse;

// COT-030 (E-63, E-64, design Decision 11): the official rate of the day (Bs per USD): the manual entry,
// the API fetch and its schedule, the rate in force and the history. Dates are local to the operating
// timezone (America/Caracas, UTC-4): 2026-10-12 02:00 UTC is still 2026-10-11 in Caracas. The real API
// is never called: every request is faked and stray requests fail the suite (TestCase).

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-12 14:00:00', 'UTC'));
});

/**
 * A raw row, as the API command or an earlier manual entry would have left it.
 *
 * @param  array<string, mixed>  $attributes
 */
function rateRow(string $date, string $value, ExchangeRateOrigin $origin = ExchangeRateOrigin::Api, array $attributes = []): ExchangeRate
{
    return ExchangeRate::query()->create([
        'rate_date' => $date,
        'value' => $value,
        'origin' => $origin,
        ...$attributes,
    ]);
}

function recordRate(mixed $value, ?User $actor = null): TestResponse
{
    $actor ??= userWithPermissions(PermissionName::PricingView, PermissionName::PricingManage);

    return test()->actingAs($actor)->postJson('/pricing/exchange-rates', ['value' => $value]);
}

function rateAuditRows(): int
{
    return AuditLog::query()->where('action', AuditAction::ExchangeRateRecorded->value)->count();
}

// --- Schema (append-only, one effective rate per day) ---------------------------------------------

it('COT-030 keeps one effective rate per day: a second effective row for the same date is rejected', function () {
    rateRow('2026-10-12', '875.6505');

    expect(fn () => DB::table('exchange_rates')->insert(['rate_date' => '2026-10-12', 'value' => '880.0000', 'origin' => 'manual', 'created_at' => now()]))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('COT-030 lets superseded rows of a day coexist with its effective one', function () {
    rateRow('2026-10-12', '870.0000', attributes: ['superseded_at' => now()]);
    rateRow('2026-10-12', '871.0000', attributes: ['superseded_at' => now()]);
    rateRow('2026-10-12', '875.6505');

    expect(ExchangeRate::query()->count())->toBe(3)
        ->and(DB::table('exchange_rates')->whereNotNull('effective_date')->count())->toBe(1);
});

it('COT-030 rejects a rate of zero or less in the database', function (string $value) {
    expect(fn () => DB::table('exchange_rates')->insert(['rate_date' => '2026-10-12', 'value' => $value, 'origin' => 'api', 'created_at' => now()]))
        ->toThrow(QueryException::class);
})->with(['zero' => ['0'], 'negative' => ['-1']]);

it('COT-030 keeps the value with four exact decimals', function () {
    $rate = rateRow('2026-10-12', '875.6505')->fresh();

    expect($rate->value->toScale(4)->toString())->toBe('875.6505')
        ->and($rate->origin)->toBe(ExchangeRateOrigin::Api)
        ->and($rate->rate_date->toDateString())->toBe('2026-10-12');
});

// --- Manual entry (E-64) --------------------------------------------------------------------------

it('E-64 records the manual rate of today with its author and audit, as the rate in force', function () {
    $actor = userWithPermissions(PermissionName::PricingView, PermissionName::PricingManage);
    rateRow('2026-10-11', '870.1000');

    recordRate('875,6505', $actor)->assertRedirect();

    $rate = ExchangeRate::query()->latest('id')->firstOrFail();
    $audit = AuditLog::query()->where('action', AuditAction::ExchangeRateRecorded->value)->sole();

    expect($rate->rate_date->toDateString())->toBe('2026-10-12')
        ->and($rate->value->toScale(4)->toString())->toBe('875.6505')
        ->and($rate->origin)->toBe(ExchangeRateOrigin::Manual)
        ->and($rate->recorded_by)->toBe($actor->id)
        ->and($rate->superseded_at)->toBeNull()
        ->and($audit->actor_id)->toBe($actor->id)
        ->and($audit->entity_type)->toBe($rate->getMorphClass())
        ->and($audit->entity_id)->toBe($rate->id)
        ->and($audit->old_values)->toBe([])
        ->and($audit->new_values)->toEqual(['date' => '2026-10-12', 'value' => '875.6505', 'origin' => 'manual']);

    $current = app(CurrentExchangeRate::class)->get();

    expect($current->value->toScale(4)->toString())->toBe('875.6505')
        ->and($current->date->toDateString())->toBe('2026-10-12')
        ->and($current->origin)->toBe(ExchangeRateOrigin::Manual);
});

it('E-64 takes today in Caracas, not in UTC', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-12 02:00:00', 'UTC'));

    recordRate('875.6505')->assertRedirect();

    expect(ExchangeRate::query()->sole()->rate_date->toDateString())->toBe('2026-10-11');
});

it('E-64 supersedes the API rate of today: the old row stays, marked, and points to the manual one', function () {
    $api = rateRow('2026-10-12', '875.6505', attributes: ['source_updated_at' => '2026-10-09 04:00:00']);

    recordRate('880.25')->assertRedirect();

    $api->refresh();
    $manual = ExchangeRate::query()->where('origin', ExchangeRateOrigin::Manual)->sole();
    $audit = AuditLog::query()->where('action', AuditAction::ExchangeRateRecorded->value)->sole();

    expect($api->superseded_at)->not->toBeNull()
        ->and($api->superseded_by_id)->toBe($manual->id)
        ->and($api->value->toScale(4)->toString())->toBe('875.6505')
        ->and($manual->superseded_at)->toBeNull()
        ->and(DB::table('exchange_rates')->where('effective_date', '2026-10-12')->value('id'))->toBe($manual->id)
        ->and($audit->old_values)->toEqual(['date' => '2026-10-12', 'value' => '875.6505', 'origin' => 'api'])
        ->and($audit->new_values)->toEqual(['date' => '2026-10-12', 'value' => '880.2500', 'origin' => 'manual'])
        ->and(app(CurrentExchangeRate::class)->get()->value->toScale(4)->toString())->toBe('880.2500');
});

it('E-64 lets a second manual entry of the same day supersede the first one', function () {
    recordRate('875.00')->assertRedirect();
    recordRate('876.00')->assertRedirect();

    $rows = ExchangeRate::query()->orderBy('id')->get();

    expect($rows)->toHaveCount(2)
        ->and($rows[0]->superseded_by_id)->toBe($rows[1]->id)
        ->and($rows[1]->superseded_at)->toBeNull()
        ->and(rateAuditRows())->toBe(2);
});

it('E-64 reruns once when the API rate of today appears while the manual one is being written, and the manual one wins', function () {
    $attempts = 0;

    // The API command inserts today's row between the read and the insert of the manual entry.
    ExchangeRate::creating(function (ExchangeRate $rate) use (&$attempts): void {
        if ($rate->origin === ExchangeRateOrigin::Manual && ++$attempts === 1) {
            DB::table('exchange_rates')->insert(['rate_date' => '2026-10-12', 'value' => '875.6505', 'origin' => 'api', 'created_at' => now()]);
        }
    });

    recordRate('880.00')->assertRedirect();

    expect($attempts)->toBe(2)
        ->and(DB::table('exchange_rates')->whereNotNull('effective_date')->sole()->origin)->toBe('manual')
        ->and(rateAuditRows())->toBe(1);
});

it('E-64 rejects a value of zero, negative, with more than four decimals or not a number, on its field', function (mixed $value) {
    recordRate($value)->assertUnprocessable()->assertJsonValidationErrors(['value']);

    expect(ExchangeRate::query()->count())->toBe(0)
        ->and(rateAuditRows())->toBe(0);
})->with([
    'zero' => ['0'],
    'zero with decimals' => ['0,0000'],
    'negative' => ['-875'],
    'five decimals' => ['875.65049'],
    'text' => ['ochocientos'],
    'empty' => [''],
    'number instead of string' => [875.65],
]);

it('E-64 answers 403, audited, to a user with pricing.view but without pricing.manage and records nothing', function () {
    $actor = userWithPermissions(PermissionName::PricingView);

    recordRate('875.6505', $actor)->assertForbidden();

    expect(ExchangeRate::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('action', AuditAction::AuthorizationDenied->value)->sole()->actor_id)->toBe($actor->id);
});

it('E-64 answers 403 to a user holding other permissions only', function () {
    recordRate('875.6505', userWithPermissions(PermissionName::ProductsView, PermissionName::QuotationsView))->assertForbidden();

    expect(ExchangeRate::query()->count())->toBe(0);
});

it('COT-030 declares the manual rate route', function () {
    $route = app('router')->getRoutes()->getByName('pricing.exchange-rates.store');

    expect($route->uri())->toBe('pricing/exchange-rates')
        ->and($route->methods())->toContain('POST');
});

// --- API fetch (E-63, E-64) -----------------------------------------------------------------------

const RATE_URL = 'https://ve.dolarapi.com/v1/dolares/oficial';

const RATE_BODY = '{"fuente":"oficial","nombre":"Oficial","compra":null,"venta":null,"promedio": 875.6505, "fechaActualizacion": "2026-10-09T00:00:00-04:00"}';

function fakeRateApi(mixed $response): void
{
    config(['services.exchange_rate.url' => RATE_URL]);
    Sleep::fake();
    Event::fake([ExchangeRateFetchFailed::class]);
    Http::fake([RATE_URL => $response]);
}

it('E-63 saves the promedio as the rate of the Caracas day of the run, with origin api and the source date apart', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-12 11:00:00', 'UTC')); // 07:00 in Caracas
    fakeRateApi(Http::response(RATE_BODY));

    $this->artisan('exchange-rate:fetch')->assertSuccessful();

    $rate = ExchangeRate::query()->sole();

    expect($rate->rate_date->toDateString())->toBe('2026-10-12')
        ->and($rate->value->toScale(4)->toString())->toBe('875.6505')
        ->and($rate->origin)->toBe(ExchangeRateOrigin::Api)
        ->and($rate->source_updated_at->utc()->format('Y-m-d H:i:s'))->toBe('2026-10-09 04:00:00')
        ->and($rate->recorded_by)->toBeNull()
        ->and(rateAuditRows())->toBe(0);

    Http::assertSentCount(1);
    Http::assertSent(fn ($request): bool => $request->url() === RATE_URL && $request->hasHeader('Accept', 'application/json'));
    Event::assertNotDispatched(ExchangeRateFetchFailed::class);
});

it('E-63 takes the date of the run in Caracas even when UTC is already the next day', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-13 02:00:00', 'UTC')); // 22:00 of the 12th in Caracas
    fakeRateApi(Http::response(RATE_BODY));

    $this->artisan('exchange-rate:fetch')->assertSuccessful();

    expect(ExchangeRate::query()->sole()->rate_date->toDateString())->toBe('2026-10-12');
});

it('E-63 does not call the API again nor create another rate when the task runs twice the same day', function () {
    fakeRateApi(Http::response(RATE_BODY));

    $this->artisan('exchange-rate:fetch')->assertSuccessful();
    $this->artisan('exchange-rate:fetch')->assertSuccessful();

    expect(ExchangeRate::query()->count())->toBe(1);
    Http::assertSentCount(1);
});

it('E-63 does not call the API when today already has a rate of any origin, and keeps the manual one', function () {
    fakeRateApi(Http::response(RATE_BODY));
    recordRate('880.00')->assertRedirect();

    $this->artisan('exchange-rate:fetch')->assertSuccessful();

    expect(ExchangeRate::query()->sole()->origin)->toBe(ExchangeRateOrigin::Manual);
    Http::assertNothingSent();
});

it('E-63 keeps a rate whose source date is missing or unreadable, without the source date', function (string $body) {
    fakeRateApi(Http::response($body));

    $this->artisan('exchange-rate:fetch')->assertSuccessful();

    expect(ExchangeRate::query()->sole()->source_updated_at)->toBeNull();
})->with([
    'missing' => ['{"promedio": 875.6505}'],
    'not a date' => ['{"promedio": 875.6505, "fechaActualizacion": "ayer"}'],
]);

it('E-64 saves nothing, logs the failure and announces it when the API fails', function (Closure $response, string $reason) {
    Log::spy();
    fakeRateApi($response);
    rateRow('2026-10-11', '870.1000');

    $this->artisan('exchange-rate:fetch')->assertSuccessful();

    expect(ExchangeRate::query()->count())->toBe(1);

    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context): bool => $message === 'exchange_rate.fetch_failed' && $context['reason'] === $reason);
    Event::assertDispatched(ExchangeRateFetchFailed::class, fn (ExchangeRateFetchFailed $event): bool => $event->reason === $reason);

    // The calculation keeps using the rate of the day before, with its date.
    expect(app(CurrentExchangeRate::class)->get()->date->toDateString())->toBe('2026-10-11');
})->with([
    'connection error' => [fn () => Http::failedConnection('timeout'), 'connection'],
    'server error' => [fn () => Http::response('upstream', 500), 'status'],
    'invalid json' => [fn () => Http::response('<html>502</html>', 200), 'invalid_rate'],
    'missing promedio' => [fn () => Http::response('{"compra": 870.1}', 200), 'invalid_rate'],
    'zero promedio' => [fn () => Http::response('{"promedio": 0}', 200), 'invalid_rate'],
    'negative promedio' => [fn () => Http::response('{"promedio": -1}', 200), 'invalid_rate'],
]);

it('E-64 tries the API three times in a run, waiting 2 s and 10 s, before giving up', function () {
    fakeRateApi(Http::sequence()->push('upstream', 500)->push('upstream', 503)->push(RATE_BODY));

    $this->artisan('exchange-rate:fetch')->assertSuccessful();

    expect(ExchangeRate::query()->sole()->value->toScale(4)->toString())->toBe('875.6505');
    Http::assertSentCount(3);
    Sleep::assertSlept(fn ($duration): bool => (int) $duration->totalMilliseconds === 2000, 1);
    Sleep::assertSlept(fn ($duration): bool => (int) $duration->totalMilliseconds === 10000, 1);
});

it('COT-030 lets a manual rate written while the API answers win: the API row is dropped silently', function () {
    fakeRateApi(Http::response(RATE_BODY));

    // A manual entry commits today's rate between the check and the insert of the API row.
    ExchangeRate::creating(function (ExchangeRate $rate): void {
        if ($rate->origin === ExchangeRateOrigin::Api) {
            DB::table('exchange_rates')->insert(['rate_date' => '2026-10-12', 'value' => '880.0000', 'origin' => 'manual', 'created_at' => now()]);
        }
    });

    $this->artisan('exchange-rate:fetch')->assertSuccessful();

    expect(DB::table('exchange_rates')->sole()->origin)->toBe('manual');
    Event::assertNotDispatched(ExchangeRateFetchFailed::class);
});

it('E-63 schedules the fetch at 07:00 in America/Caracas, with later runs at 07:30, 08:00 and 09:00, never overlapping', function () {
    $events = collect(app(Schedule::class)->events())->filter(fn ($event): bool => str_contains((string) $event->command, 'exchange-rate:fetch'));

    expect($events->map(fn ($event): string => $event->expression)->values()->all())->toBe(['0 7 * * *', '30 7 * * *', '0 8 * * *', '0 9 * * *'])
        ->and($events->every(fn ($event): bool => $event->timezone === 'America/Caracas'))->toBeTrue()
        ->and($events->every(fn ($event): bool => $event->withoutOverlapping))->toBeTrue();
});

it('COT-030 reads the API address from the services configuration', function () {
    expect(config('services.exchange_rate.url'))->toBe(RATE_URL);
});

// --- Rate in force and history --------------------------------------------------------------------

it('COT-030 uses the most recent effective rate when today has none, with its own date', function () {
    rateRow('2026-10-09', '860.0000');
    rateRow('2026-10-11', '870.0000', attributes: ['superseded_at' => now()]);
    rateRow('2026-10-10', '865.5000', ExchangeRateOrigin::Manual);

    $current = app(CurrentExchangeRate::class)->get();

    expect($current->value->toScale(4)->toString())->toBe('865.5000')
        ->and($current->date->toDateString())->toBe('2026-10-10')
        ->and($current->origin)->toBe(ExchangeRateOrigin::Manual);
});

it('COT-030 prefers the effective rate of today over older ones', function () {
    rateRow('2026-10-11', '870.0000');
    rateRow('2026-10-12', '875.6505');

    expect(app(CurrentExchangeRate::class)->get()->date->toDateString())->toBe('2026-10-12');
});

it('COT-030 has no rate in force when none is stored', function () {
    expect(app(CurrentExchangeRate::class)->get())->toBeNull();
});

it('COT-030 lists the history newest first, superseded rows included, with their author', function () {
    $author = userWithPermissions(PermissionName::PricingView, PermissionName::PricingManage);
    $old = rateRow('2026-10-10', '865.0000');
    $api = rateRow('2026-10-12', '875.6505', attributes: ['superseded_at' => now()]);
    $manual = rateRow('2026-10-12', '880.0000', ExchangeRateOrigin::Manual, ['recorded_by' => $author->id]);
    $api->forceFill(['superseded_by_id' => $manual->id])->save();

    $history = ExchangeRate::query()->history()->get();

    expect($history->pluck('id')->all())->toBe([$manual->id, $api->id, $old->id])
        ->and($history->first()->relationLoaded('recordedBy'))->toBeTrue()
        ->and($history->first()->recordedBy->fullName())->toBe($author->fullName())
        ->and($history->first()->isEffective())->toBeTrue()
        ->and($history[1]->isEffective())->toBeFalse();
});
