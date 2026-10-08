<?php

use App\Actions\Products\CreateCombo;
use App\Enums\AuditAction;
use App\Enums\PermissionName;
use App\Models\AttributeValue;
use App\Models\AuditLog;
use App\Models\CatalogAttribute;
use App\Models\CatalogCode;
use App\Models\Combo;
use App\Models\ComboComponent;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\Role;
use App\Models\User;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Feature tests run against the MySQL `testing` database, refreshed per test, with the
| roles and permission catalog seeded by TestCase (FoundationSeeder). Unit tests stay plain.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| Shared helpers for the foundation tests (design.md, Testing Strategy).
|
*/

/**
 * A user whose only authority is one dedicated role holding exactly the given permissions.
 */
function userWithPermissions(PermissionName|string ...$permissions): User
{
    return User::factory()->withPermissions(...$permissions)->create();
}

/**
 * A user holding the protected Administrador role, which the seeder gives the Administrador
 * grants of the initial matrices of specs 001, 002 and 003 (every permission except `customers.portfolio`).
 */
function administrator(): User
{
    $user = User::factory()->create();
    $user->roles()->attach(Role::query()->where('is_protected', true)->firstOrFail());

    return $user;
}

/**
 * Valid minimum payload for `POST /customers` (spec 002). Phones are fictitious; tests that create
 * several customers pass distinct phones so they never trip the duplicate-phone warning.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function createCustomerPayload(array $overrides = []): array
{
    return [
        'type' => 'natural',
        'name' => 'Cliente de prueba',
        'phone' => '0414-123.45.67',
        ...$overrides,
    ];
}

/**
 * Complete state of a customer as the edit form sends it for `PUT /customers/{customer}`
 * (the contact person and the address are replaced, so the whole state travels).
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function updateCustomerPayload(Customer $customer, array $overrides = []): array
{
    $customer->loadMissing(['contact', 'address']);

    return [
        'type' => $customer->type->value,
        'name' => $customer->name,
        'document_type' => $customer->document_type?->value,
        'document_number' => $customer->document_number,
        'phone' => $customer->phone,
        'email' => $customer->email,
        'birthday_day' => $customer->birthday_day,
        'birthday_month' => $customer->birthday_month,
        'anniversary_day' => $customer->anniversary_day,
        'anniversary_month' => $customer->anniversary_month,
        'notes' => $customer->notes,
        'contact' => $customer->contact?->only(['name', 'position', 'phone', 'email']),
        'address' => $customer->address === null ? null : [
            ...$customer->address->only(['line', 'city', 'reference']),
            'state' => $customer->address->state->value,
        ],
        ...$overrides,
    ];
}

/**
 * Performs a real `POST /login` and returns the decrypted session id from the response cookie,
 * so the database session mechanism is exercised instead of the guard's in-memory user.
 */
function loginWithRealSession(User $user, string $password = 'password'): string
{
    $response = test()->post('/login', ['email' => $user->email, 'password' => $password]);

    $cookie = $response->getCookie(config('session.cookie'));

    expect($cookie)->not->toBeNull('The login did not start a session.');

    return $cookie->getValue();
}

/**
 * Sends a request carrying only the given session id (encrypted like a browser cookie): the guard's cached user and the
 * in-memory session store are forgotten first, so the user is re-resolved from the persisted session.
 *
 * @param  array<string, mixed>  $data
 */
function requestWithSession(string $sessionCookie, string $method, string $uri, array $data = []): TestResponse
{
    // Drop the in-memory session store and guard state kept by the shared test application,
    // so the request is resolved only from the cookie and the persisted `sessions` row.
    app('auth')->forgetGuards();
    app('session')->forgetDrivers();
    app()->forgetInstance('session.store');
    app()->forgetInstance('auth.driver');

    $name = config('session.cookie');

    // `call()` ignores the client's default cookies, so the encrypted cookie is passed explicitly.
    $cookie = encrypt(CookieValuePrefix::create($name, app('encrypter')->getKey()).$sessionCookie, false);

    return test()->call($method, $uri, $data, [$name => $cookie]);
}

function comboCreator(): User
{
    return userWithPermissions(PermissionName::ProductsView, PermissionName::ProductsCreate);
}

function comboEditor(): User
{
    return userWithPermissions(PermissionName::ProductsView, PermissionName::ProductsUpdate);
}

function comboDeactivator(): User
{
    return userWithPermissions(PermissionName::ProductsView, PermissionName::ProductsDeactivate);
}

/**
 * Diaper catalog of the "Kit Oro antiderrame" example (E-21). Value names are unique across the
 * fixture, so tests address them by name. Fictitious data only.
 *
 * - Pañal antiderrame: size as axis (3XG, 4XG, 5XG). 2XG exists in the catalog but is not admitted.
 * - Absorbente: size as order attribute (3XG, 4XG) and its own colors (Blanco, Azul).
 * - Protector de cama: declares no attributes.
 * - Pañal ecológico: fabric as axis (Microfibra offers Blanco, Algodón offers Blanco and Crema); its
 *   color comes from the fabric. Azul exists but no fabric offers it.
 * - Camisa corporativa (uniforms line) and Bordado pequeño (a diaper-line service) for the rejections.
 *
 * @return array{attrs: array<string, CatalogAttribute>, vals: array<string, AttributeValue>, products: array<string, Product>}
 */
function comboCatalog(): array
{
    $attrs = [
        'Talla' => CatalogAttribute::factory()->size()->create(),
        'Color' => CatalogAttribute::factory()->color()->create(),
        'Tela' => CatalogAttribute::factory()->fabric()->create(),
    ];

    $vals = [];

    foreach (['3XG', '4XG', '5XG', '2XG'] as $position => $name) {
        $vals[$name] = AttributeValue::factory()->for($attrs['Talla'], 'catalogAttribute')->create(['name' => $name, 'sort_order' => $position + 1]);
    }

    foreach (['Blanco' => '#FFFFFF', 'Azul' => '#1F3A93', 'Crema' => '#F5F0DC'] as $name => $tone) {
        $vals[$name] = AttributeValue::factory()->for($attrs['Color'], 'catalogAttribute')->withTone($tone)->create(['name' => $name]);
    }

    foreach (['Microfibra', 'Algodón'] as $name) {
        $vals[$name] = AttributeValue::factory()->for($attrs['Tela'], 'catalogAttribute')->create(['name' => $name]);
    }

    $vals['Microfibra']->offeredColors()->attach([$vals['Blanco']->id]);
    $vals['Algodón']->offeredColors()->attach([$vals['Blanco']->id, $vals['Crema']->id]);

    $declare = function (Product $product, array $declared) use ($attrs, $vals): void {
        $position = 0;

        foreach ($declared as $attribute => [$role, $allowed]) {
            $row = ProductAttribute::factory()->create([
                'product_id' => $product->id,
                'catalog_attribute_id' => $attrs[$attribute]->id,
                'role' => $role,
                'sort_order' => ++$position,
            ]);
            $row->allowedValues()->attach(array_map(fn (string $name): int => $vals[$name]->id, $allowed));
        }
    };

    $products = [
        'Pañal antiderrame' => Product::factory()->diapers()->create(['name' => 'Pañal antiderrame']),
        'Absorbente' => Product::factory()->diapers()->create(['name' => 'Absorbente']),
        'Protector de cama' => Product::factory()->diapers()->create(['name' => 'Protector de cama']),
        'Pañal ecológico' => Product::factory()->diapers()->create(['name' => 'Pañal ecológico']),
        'Camisa corporativa' => Product::factory()->create(['name' => 'Camisa corporativa']),
        'Bordado pequeño' => Product::factory()->diapers()->service()->create(['name' => 'Bordado pequeño']),
    ];

    $declare($products['Pañal antiderrame'], ['Talla' => ['axis', ['3XG', '4XG', '5XG']]]);
    $declare($products['Absorbente'], ['Talla' => ['order', ['3XG', '4XG']], 'Color' => ['order', ['Blanco', 'Azul']]]);
    $declare($products['Pañal ecológico'], ['Tela' => ['axis', ['Microfibra', 'Algodón']], 'Color' => ['order', []]]);

    return ['attrs' => $attrs, 'vals' => $vals, 'products' => $products];
}

/**
 * Ids of the named values.
 *
 * @return list<int>
 */
function comboIds(array $catalog, string ...$names): array
{
    return array_map(fn (string $name): int => $catalog['vals'][$name]->id, $names);
}

/**
 * One component of the payload. `$values` maps an attribute name to value names.
 *
 * @param  array<string, list<string>>  $values
 * @return array{product_id: int, quantity: int, values: array<int, list<int>>}
 */
function comboComponent(array $catalog, string $product, int $quantity = 1, array $values = []): array
{
    $ids = [];

    foreach ($values as $attribute => $names) {
        $ids[$catalog['attrs'][$attribute]->id] = comboIds($catalog, ...$names);
    }

    return ['product_id' => $catalog['products'][$product]->id, 'quantity' => $quantity, 'values' => $ids];
}

/**
 * `POST /combos` payload: the E-21 kit unless overridden.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function comboPayload(array $catalog, array $overrides = []): array
{
    return array_merge([
        'name' => 'Kit Oro antiderrame',
        'code' => 'K-ORO',
        'components' => [
            comboComponent($catalog, 'Pañal antiderrame', 2, ['Talla' => ['3XG', '4XG', '5XG']]),
            comboComponent($catalog, 'Absorbente', 3),
            comboComponent($catalog, 'Protector de cama', 1),
        ],
    ], $overrides);
}

function postCombo(mixed $test, User $actor, array $payload): TestResponse
{
    return $test->actingAs($actor)->postJson('/combos', $payload);
}

function putCombo(mixed $test, User $actor, Combo $combo, array $payload): TestResponse
{
    return $test->actingAs($actor)->putJson("/combos/{$combo->id}", $payload);
}

/**
 * Creates the E-21 kit (or a variation) straight through the Action.
 *
 * @param  array<string, mixed>  $overrides
 */
function makeCombo(array $catalog, array $overrides = []): Combo
{
    return app(CreateCombo::class)->handle(comboPayload($catalog, $overrides), comboCreator());
}

/**
 * @return Collection<int, AuditLog>
 */
function comboAudit(AuditAction ...$actions): Collection
{
    return AuditLog::query()
        ->whereIn('action', array_map(fn (AuditAction $action): string => $action->value, $actions))
        ->orderBy('id')
        ->get();
}

/**
 * Nothing was written: no combo, component, value or registry row of a combo, and no audit row.
 */
function expectNoComboWritten(): void
{
    expect(Combo::query()->count())->toBe(0)
        ->and(ComboComponent::query()->count())->toBe(0)
        ->and(DB::table('combo_component_values')->count())->toBe(0)
        ->and(CatalogCode::query()->whereNotNull('combo_id')->count())->toBe(0)
        ->and(comboAudit(AuditAction::ComboCreated))->toHaveCount(0);
}
