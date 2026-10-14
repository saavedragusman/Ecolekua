<?php

use App\Actions\Products\CreateCombo;
use App\Enums\AttributeRole;
use App\Enums\AuditAction;
use App\Enums\CatalogStatus;
use App\Enums\PermissionName;
use App\Models\AttributeValue;
use App\Models\AuditLog;
use App\Models\CatalogAttribute;
use App\Models\CatalogCode;
use App\Models\Combination;
use App\Models\Combo;
use App\Models\ComboComponent;
use App\Models\Customer;
use App\Models\DetailLocation;
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

/*
|--------------------------------------------------------------------------
| Selection engine fixtures (PRD-011, PRD-019)
|--------------------------------------------------------------------------
|
| Fictitious catalog shared by the resolution and the options tests: a corporate shirt, a pair of
| trousers, a cap and a polo (`resCatalog()`), and the diaper kit of E-21 (`resKitCatalog()`).
|
*/

/**
 * Declares attributes for a product. `$declared` maps an attribute name to its role and the names of
 * the admitted values (an empty list for the color of a product with fabric, DEC-PRD-35).
 *
 * @param  array{attrs: array<string, CatalogAttribute>, vals: array<string, AttributeValue>}  $catalog
 * @param  array<string, array{0: string, 1: list<string>}>  $declared
 */
function resDeclare(array $catalog, Product $product, array $declared): void
{
    $position = 0;

    foreach ($declared as $attribute => [$role, $allowed]) {
        $row = ProductAttribute::factory()->create([
            'product_id' => $product->id,
            'catalog_attribute_id' => $catalog['attrs'][$attribute]->id,
            'role' => AttributeRole::from($role),
            'sort_order' => ++$position,
        ]);
        $row->allowedValues()->attach(array_map(fn (string $name): int => $catalog['vals'][$name]->id, $allowed));
    }
}

/**
 * An active combination with its code. `$axes` and `$restrictions` map an attribute name to value
 * names; `$included` are service names the price already covers.
 *
 * @param  array<string, mixed>  $catalog
 * @param  array<string, list<string>>  $axes
 * @param  array<string, list<string>>  $restrictions
 * @param  list<string>  $included
 */
function resCombination(array $catalog, Product $product, string $code, array $axes, array $restrictions = [], array $included = [], bool $active = true): Combination
{
    $combination = Combination::factory()->create([
        'product_id' => $product->id,
        'status' => $active ? CatalogStatus::Active : CatalogStatus::Inactive,
    ]);
    CatalogCode::factory()->create(['code' => $code, 'combination_id' => $combination->id]);

    $pivot = [];

    foreach ([$axes, $restrictions] as $map) {
        foreach ($map as $attribute => $names) {
            foreach ($names as $name) {
                $pivot[$catalog['vals'][$name]->id] = ['catalog_attribute_id' => $catalog['attrs'][$attribute]->id];
            }
        }
    }

    $combination->values()->sync($pivot);
    $combination->customizations()->sync(array_map(fn (string $name): int => $catalog['services'][$name]->id, $included));

    return $combination;
}

/**
 * Axes of the shirt combinations by code (the first value of each axis).
 *
 * @return array<string, string>
 */
function resAxesOf(string $code): array
{
    $base = ['Tela' => 'ALG-OXF Pima', 'Modelo' => 'Columbia especial', 'Manga' => 'Manga corta', 'Género' => 'Dama'];

    return match ($code) {
        '110-1' => $base,
        '110' => [...$base, 'Género' => 'Caballero'],
        '110-2' => [...$base, 'Manga' => 'Manga larga'],
        '110-4' => [...$base, 'Tela' => 'Microfibra'],
        '159-1' => ['Tela' => 'Gabardina', 'Modelo' => 'Clásico', 'Manga' => 'Manga larga', 'Género' => 'Caballero'],
        default => [],
    };
}

/**
 * @return array{attrs: array<string, CatalogAttribute>, vals: array<string, AttributeValue>, services: array<string, Product>, locations: array<string, DetailLocation>, camisa: Product, pantalon: Product, gorra: Product, polo: Product, combos: array<string, Combination>}
 */
function resCatalog(): array
{
    $attrs = [
        'Tela' => CatalogAttribute::factory()->fabric()->create(),
        'Modelo' => CatalogAttribute::factory()->create(['name' => 'Modelo']),
        'Manga' => CatalogAttribute::factory()->create(['name' => 'Manga']),
        'Género' => CatalogAttribute::factory()->gender()->create(),
        'Talla' => CatalogAttribute::factory()->size()->create(),
        'Color' => CatalogAttribute::factory()->color()->create(),
    ];

    $names = [
        'Tela' => ['ALG-OXF Pima', 'Drill', 'Gabardina', 'Microfibra'],
        'Modelo' => ['Columbia especial', 'Clásico'],
        'Manga' => ['Manga corta', 'Manga larga'],
        'Género' => ['Dama', 'Caballero'],
        'Talla' => ['S', 'M', 'L', 'XL', '2XL', '28', '38', '44', '46'],
    ];
    $vals = [];

    foreach ($names as $attribute => $valueNames) {
        foreach ($valueNames as $name) {
            $vals[$name] = AttributeValue::factory()->for($attrs[$attribute], 'catalogAttribute')->create(['name' => $name, 'sort_order' => count($vals) + 1]);
        }
    }

    $colors = ['Azul marino' => '#1F2A44', 'Blanco' => '#FFFFFF', 'Verde' => '#2E7D32', 'Rojo' => '#C62828', 'Gris perla' => '#BFC5CC', 'Negro' => '#111111'];

    foreach ($colors as $name => $tone) {
        $vals[$name] = AttributeValue::factory()->for($attrs['Color'], 'catalogAttribute')->withTone($tone)->create(['name' => $name, 'sort_order' => count($vals) + 1]);
    }

    $vals['Fucsia'] = AttributeValue::factory()->for($attrs['Color'], 'catalogAttribute')->withTone('#FF00FF')->inactive()->create(['name' => 'Fucsia', 'sort_order' => count($vals) + 1]);

    // Colors the team offers in each fabric (DEC-PRD-32): ALG-OXF Pima in three, Gabardina in two.
    $offered = ['ALG-OXF Pima' => ['Azul marino', 'Blanco', 'Verde'], 'Drill' => ['Blanco'], 'Gabardina' => ['Negro', 'Blanco'], 'Microfibra' => ['Blanco']];

    foreach ($offered as $fabric => $colorNames) {
        $vals[$fabric]->offeredColors()->attach(array_map(fn (string $name): int => $vals[$name]->id, $colorNames));
    }

    $services = [
        'Bordado pequeño' => Product::factory()->service()->create(['name' => 'Bordado pequeño']),
        'Vinil' => Product::factory()->service()->create(['name' => 'Vinil']),
        'Estampado' => Product::factory()->service()->create(['name' => 'Estampado']),
    ];

    $locations = [
        'Pechera' => DetailLocation::factory()->create(['name' => 'Pechera']),
        'Orilla de mangas' => DetailLocation::factory()->create(['name' => 'Orilla de mangas', 'status' => CatalogStatus::Inactive]),
        'Pie de cuello' => DetailLocation::factory()->create(['name' => 'Pie de cuello']),
    ];

    $catalog = ['attrs' => $attrs, 'vals' => $vals, 'services' => $services, 'locations' => $locations];

    $camisa = Product::factory()->create(['name' => 'Camisa corporativa']);
    resDeclare($catalog, $camisa, [
        'Tela' => ['axis', ['ALG-OXF Pima', 'Drill', 'Gabardina', 'Microfibra']],
        'Modelo' => ['axis', ['Columbia especial', 'Clásico']],
        'Manga' => ['axis', ['Manga corta', 'Manga larga']],
        'Género' => ['axis', ['Dama', 'Caballero']],
        'Talla' => ['order', ['S', 'M', 'L', 'XL', '2XL']],
        'Color' => ['order', []],
    ]);
    $camisa->detailLocations()->attach([$locations['Pechera']->id, $locations['Orilla de mangas']->id]);
    $camisa->customizations()->attach([$services['Bordado pequeño']->id]);

    $pantalon = Product::factory()->create(['name' => 'Pantalón de trabajo']);
    resDeclare($catalog, $pantalon, ['Talla' => ['order', ['28', '38', '44']]]);

    $gorra = Product::factory()->stockWithMinimum()->create(['name' => 'Gorra dryfit']);
    resDeclare($catalog, $gorra, ['Color' => ['order', ['Negro', 'Blanco']]]);

    $polo = Product::factory()->onDemand()->create(['name' => 'Polo sin personalizado', 'allows_custom_color' => false]);
    resDeclare($catalog, $polo, ['Color' => ['order', ['Negro']]]);

    $combos = [];

    foreach (['110-1', '110', '110-4', '159-1'] as $code) {
        $axes = array_map(fn (string $name): array => [$name], resAxesOf($code));
        $combos[$code] = resCombination($catalog, $camisa, $code, $code === '159-1' ? [...$axes, 'Tela' => ['Drill', 'Gabardina']] : $axes);
    }

    // 110-2 restricts the size to S-XL (E-54) and includes vinil, which the shirt does not admit as an extra (E-67).
    $combos['110-2'] = resCombination($catalog, $camisa, '110-2', array_map(fn (string $name): array => [$name], resAxesOf('110-2')), ['Talla' => ['S', 'M', 'L', 'XL']], ['Vinil']);
    $combos['184'] = resCombination($catalog, $pantalon, '184', []);
    $combos['G-01'] = resCombination($catalog, $gorra, 'G-01', []);
    $combos['P-01'] = resCombination($catalog, $polo, 'P-01', []);

    return [...$catalog, 'camisa' => $camisa, 'pantalon' => $pantalon, 'gorra' => $gorra, 'polo' => $polo, 'combos' => $combos];
}

/**
 * A shirt with `$count` active combinations over a fabric, model, sleeve and gender axis (32 at
 * most), every fifth one including a service.
 *
 * @param  array<string, mixed>  $catalog
 */
function resBulkProduct(array $catalog, string $name, int $count): Product
{
    $product = Product::factory()->create(['name' => $name]);
    resDeclare($catalog, $product, [
        'Tela' => ['axis', ['ALG-OXF Pima', 'Drill', 'Gabardina', 'Microfibra']],
        'Modelo' => ['axis', ['Columbia especial', 'Clásico']],
        'Manga' => ['axis', ['Manga corta', 'Manga larga']],
        'Género' => ['axis', ['Dama', 'Caballero']],
        'Talla' => ['order', ['S', 'M', 'L']],
        'Color' => ['order', []],
    ]);

    $index = 0;

    foreach (['ALG-OXF Pima', 'Drill', 'Gabardina', 'Microfibra'] as $fabric) {
        foreach (['Columbia especial', 'Clásico'] as $model) {
            foreach (['Manga corta', 'Manga larga'] as $sleeve) {
                foreach (['Dama', 'Caballero'] as $gender) {
                    if ($index === $count) {
                        return $product;
                    }

                    resCombination($catalog, $product, "$name-".++$index, ['Tela' => [$fabric], 'Modelo' => [$model], 'Manga' => [$sleeve], 'Género' => [$gender]], [], $index % 5 === 0 ? ['Vinil'] : []);
                }
            }
        }
    }

    return $product;
}

/**
 * Number of queries that `$callback` runs.
 */
function resQueryCount(Closure $callback): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $callback();
    DB::disableQueryLog();

    return count(DB::getQueryLog());
}

/**
 * The diaper catalog of the E-21 kit with an active combination per sellable item: the diaper per
 * size (3XG `10`, 4XG `11`, 5XG `12`), the absorbent `20`, the bed protector `30` and the eco diaper
 * per fabric (Microfibra `40`, Algodón `41`). Combos are created on top with `makeCombo()`.
 *
 * @return array<string, mixed>
 */
function resKitCatalog(): array
{
    $catalog = comboCatalog();
    $catalog['services'] = [];

    foreach (['3XG' => '10', '4XG' => '11', '5XG' => '12'] as $size => $code) {
        resCombination($catalog, $catalog['products']['Pañal antiderrame'], $code, ['Talla' => [$size]]);
    }

    resCombination($catalog, $catalog['products']['Absorbente'], '20', []);
    resCombination($catalog, $catalog['products']['Protector de cama'], '30', []);
    resCombination($catalog, $catalog['products']['Pañal ecológico'], '40', ['Tela' => ['Microfibra']]);
    resCombination($catalog, $catalog['products']['Pañal ecológico'], '41', ['Tela' => ['Algodón']]);

    return $catalog;
}

/**
 * Id of the component of `$combo` that holds `$product` (the `$position`-th one when it repeats).
 *
 * @param  array<string, mixed>  $catalog
 */
function resComponentId(array $catalog, Combo $combo, string $product, int $position = 0): int
{
    return $combo->components()->where('product_id', $catalog['products'][$product]->id)->get()->values()[$position]->id;
}
