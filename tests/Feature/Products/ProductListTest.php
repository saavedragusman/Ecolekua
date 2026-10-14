<?php

use App\Enums\PermissionName;
use App\Models\CatalogCode;
use App\Models\Combination;
use App\Models\Combo;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\StockMinimumOverride;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->viewer = userWithPermissions(PermissionName::ProductsView);
});

/**
 * @return list<string>
 */
function listedProductNames(Assert $page): array
{
    return collect($page->toArray()['props']['products']['data'])->pluck('name')->all();
}

/**
 * A product with one combination registered under the given code.
 *
 * @param  array<string, mixed>  $attributes
 */
function productWithCode(string $code, array $attributes = []): Product
{
    $product = Product::factory()->create($attributes);
    $combination = Combination::factory()->create(['product_id' => $product->id]);
    CatalogCode::factory()->create(['code' => $code, 'combination_id' => $combination->id]);

    return $product;
}

// --- Authorization (E-04) ---------------------------------------------------------------------

it('E-04 forbids the product list to a user without products.view', function () {
    $user = userWithPermissions(PermissionName::ProductsCreate, PermissionName::ProductsUpdate);

    $this->actingAs($user)->get('/products')->assertForbidden();
});

it('E-04 forbids a product page to a user without products.view', function () {
    $product = Product::factory()->create();
    $user = userWithPermissions(PermissionName::ProductsCreate, PermissionName::ProductsUpdate);

    $this->actingAs($user)->get("/products/{$product->id}")->assertForbidden();
});

it('E-04 redirects a guest to the login', function () {
    $product = Product::factory()->create();

    $this->get('/products')->assertRedirect('/login');
    $this->get("/products/{$product->id}")->assertRedirect('/login');
});

// --- Search (E-30) ----------------------------------------------------------------------------

it('E-30 lists the product that contains the searched combination code', function () {
    $target = productWithCode('147-12', ['name' => 'Chemise de sublimación']);
    productWithCode('147-3', ['name' => 'Gorra dryfit']);
    productWithCode('200', ['name' => 'Pantalón industrial']);

    $this->actingAs($this->viewer)->get('/products?q=147-12')->assertInertia(function (Assert $page) use ($target) {
        $page->component('products/Index')->where('q', '147-12');

        expect(listedProductNames($page))->toBe([$target->name]);
    });
});

it('E-30 matches a code regardless of case and by fragment', function () {
    productWithCode('CAM-ROJA', ['name' => 'Camisa roja']);
    productWithCode('GOR-1', ['name' => 'Gorra']);

    $this->actingAs($this->viewer)->get('/products?q=cam-r')->assertInertia(function (Assert $page) {
        expect(listedProductNames($page))->toBe(['Camisa roja']);
    });
});

it('E-30 matches a product name regardless of accents and case', function () {
    Product::factory()->create(['name' => 'Pañal ecológico']);
    Product::factory()->create(['name' => 'Camisa corporativa']);

    $this->actingAs($this->viewer)->get('/products?q=ECOLOGICO')->assertInertia(function (Assert $page) {
        expect(listedProductNames($page))->toBe(['Pañal ecológico']);
    });
});

it('E-30 lists a product once when several of its combinations match the code', function () {
    $product = productWithCode('147-1', ['name' => 'Chemise']);
    $second = Combination::factory()->create(['product_id' => $product->id]);
    CatalogCode::factory()->create(['code' => '147-2', 'combination_id' => $second->id]);

    $this->actingAs($this->viewer)->get('/products?q=147')->assertInertia(function (Assert $page) {
        expect(listedProductNames($page))->toBe(['Chemise']);
    });
});

it('E-30 does not search the codes of combos', function () {
    Product::factory()->create(['name' => 'Pañal']);
    $combo = Combo::factory()->create();
    CatalogCode::factory()->forCombo($combo)->create(['code' => 'KIT-ORO']);

    $this->actingAs($this->viewer)->get('/products?q=KIT-ORO')->assertInertia(function (Assert $page) {
        expect(listedProductNames($page))->toBe([]);
    });
});

it('trims the search term and cuts it to 100 characters', function () {
    Product::factory()->create(['name' => 'Camisa']);

    $this->actingAs($this->viewer)->get('/products?q='.urlencode('  Camisa  '))->assertInertia(function (Assert $page) {
        $page->where('q', 'Camisa');

        expect(listedProductNames($page))->toBe(['Camisa']);
    });

    $this->actingAs($this->viewer)->get('/products?q='.str_repeat('a', 150))->assertInertia(
        fn (Assert $page) => $page->where('q', str_repeat('a', 100)),
    );
});

it('treats a non-string search term as empty', function () {
    Product::factory()->create(['name' => 'Camisa']);

    $this->actingAs($this->viewer)->get('/products?q[]=Camisa')->assertInertia(function (Assert $page) {
        $page->where('q', '');

        expect(listedProductNames($page))->toBe(['Camisa']);
    });
});

it('matches % and _ literally in names and in codes', function () {
    Product::factory()->create(['name' => '100% algodón']);
    Product::factory()->create(['name' => 'tela_azul']);
    Product::factory()->create(['name' => '1000 algodón']);
    Product::factory()->create(['name' => 'telaXazul']);
    productWithCode('A_1', ['name' => 'Con guion bajo']);
    productWithCode('AX1', ['name' => 'Sin guion bajo']);

    $names = fn (string $term): array => collect(
        $this->actingAs($this->viewer)->get('/products?q='.urlencode($term))->viewData('page')['props']['products']['data'],
    )->pluck('name')->all();

    expect($names('100%'))->toBe(['100% algodón'])
        ->and($names('tela_a'))->toBe(['tela_azul'])
        ->and($names('A_1'))->toBe(['Con guion bajo']);
});

// --- Views and counts -------------------------------------------------------------------------

it('defaults to the active view and falls back on an unknown status', function () {
    Product::factory()->create(['name' => 'Activo']);
    Product::factory()->inactive()->create(['name' => 'Inactivo']);

    foreach (['/products', '/products?status=archived', '/products?status[]=all'] as $url) {
        $this->actingAs($this->viewer)->get($url)->assertInertia(function (Assert $page) {
            $page->where('status', 'active');

            expect(listedProductNames($page))->toBe(['Activo']);
        });
    }
});

it('shows the inactive and all views', function () {
    Product::factory()->create(['name' => 'Activo']);
    Product::factory()->inactive()->create(['name' => 'Inactivo']);

    $this->actingAs($this->viewer)->get('/products?status=inactive')->assertInertia(function (Assert $page) {
        $page->where('status', 'inactive');

        expect(listedProductNames($page))->toBe(['Inactivo']);
    });

    $this->actingAs($this->viewer)->get('/products?status=all')->assertInertia(function (Assert $page) {
        $page->where('status', 'all');

        expect(listedProductNames($page))->toBe(['Activo', 'Inactivo']);
    });
});

it('counts every view in the backend with the same filters, whatever the selected view', function () {
    $category = ProductCategory::factory()->create();
    Product::factory()->create(['name' => 'Camisa azul', 'product_category_id' => $category->id]);
    Product::factory()->create(['name' => 'Camisa verde', 'product_category_id' => $category->id]);
    Product::factory()->inactive()->create(['name' => 'Camisa vieja', 'product_category_id' => $category->id]);
    Product::factory()->create(['name' => 'Gorra']);
    Product::factory()->inactive()->create(['name' => 'Pantalón']);

    $this->actingAs($this->viewer)->get('/products?status=inactive')->assertInertia(
        fn (Assert $page) => $page->where('counts', ['active' => 3, 'inactive' => 2, 'all' => 5]),
    );

    $this->actingAs($this->viewer)->get('/products?q=camisa')->assertInertia(
        fn (Assert $page) => $page->where('counts', ['active' => 2, 'inactive' => 1, 'all' => 3]),
    );

    $this->actingAs($this->viewer)->get("/products?category={$category->id}&status=all")->assertInertia(
        fn (Assert $page) => $page->where('counts', ['active' => 2, 'inactive' => 1, 'all' => 3]),
    );
});

// --- Filters ----------------------------------------------------------------------------------

it('filters by category, line and supply mode, and counts with those filters', function () {
    $category = ProductCategory::factory()->create();
    Product::factory()->diapers()->stockWithMinimum()->create(['name' => 'Pañal stock', 'product_category_id' => $category->id]);
    Product::factory()->diapers()->create(['name' => 'Pañal pedido', 'product_category_id' => $category->id]);
    Product::factory()->stockWithMinimum()->create(['name' => 'Gorra stock']);
    Product::factory()->diapers()->stockWithMinimum()->inactive()->create(['name' => 'Pañal viejo', 'product_category_id' => $category->id]);

    $this->actingAs($this->viewer)->get("/products?category={$category->id}")->assertInertia(function (Assert $page) use ($category) {
        $page->where('category', $category->id);

        expect(listedProductNames($page))->toBe(['Pañal pedido', 'Pañal stock']);
    });

    $this->actingAs($this->viewer)->get('/products?line=uniforms')->assertInertia(function (Assert $page) {
        $page->where('line', 'uniforms');

        expect(listedProductNames($page))->toBe(['Gorra stock']);
    });

    $this->actingAs($this->viewer)->get('/products?mode=stock_with_minimum&line=diapers')->assertInertia(function (Assert $page) {
        $page->where('mode', 'stock_with_minimum')
            ->where('counts', ['active' => 1, 'inactive' => 1, 'all' => 2]);

        expect(listedProductNames($page))->toBe(['Pañal stock']);
    });
});

it('ignores a filter value that does not exist', function () {
    Product::factory()->create(['name' => 'Camisa']);

    $this->actingAs($this->viewer)->get('/products?category=99999&line=other&mode=magic')->assertInertia(function (Assert $page) {
        $page->where('category', null)->where('line', null)->where('mode', null);

        expect(listedProductNames($page))->toBe(['Camisa']);
    });

    $this->actingAs($this->viewer)->get('/products?category[]=1&line[]=uniforms&mode[]=service')->assertInertia(
        fn (Assert $page) => $page->where('category', null)->where('line', null)->where('mode', null),
    );
});

it('offers the labelled options of every filter', function () {
    ProductCategory::factory()->create(['name' => 'Camisas', 'sort_order' => 1]);
    ProductCategory::factory()->inactive()->create(['name' => 'Gorras', 'sort_order' => 2]);

    $this->actingAs($this->viewer)->get('/products')->assertInertia(function (Assert $page) {
        $options = $page->toArray()['props']['filters'];

        expect(collect($options['categories'])->pluck('label')->all())->toBe(['Camisas', 'Gorras'])
            ->and($options['lines'])->toBe([['value' => 'uniforms', 'label' => 'Uniformes'], ['value' => 'diapers', 'label' => 'Pañales']])
            ->and(collect($options['modes'])->pluck('value')->all())->toBe(['on_demand', 'stock_with_minimum', 'stock_depletable', 'service']);
    });
});

// --- Rows and pagination ----------------------------------------------------------------------

it('lists a row with category, line, mode, status and combination count', function () {
    $category = ProductCategory::factory()->create(['name' => 'Camisas']);
    $product = productWithCode('110-1', ['name' => 'Camisa corporativa', 'product_category_id' => $category->id]);

    $this->actingAs($this->viewer)->get('/products')->assertInertia(function (Assert $page) use ($product) {
        $row = $page->toArray()['props']['products']['data'][0];

        expect($row)->toBe([
            'id' => $product->id,
            'name' => 'Camisa corporativa',
            'category' => 'Camisas',
            'business_line_label' => 'Uniformes',
            'supply_mode_label' => 'Bajo pedido',
            'status' => 'active',
            'status_label' => 'Activo',
            'combinations_count' => 1,
        ]);
    });
});

it('paginates by 15 and keeps the filters in the page links', function () {
    foreach (range(1, 16) as $number) {
        Product::factory()->create(['name' => sprintf('Camisa %02d', $number)]);
    }

    Product::factory()->create(['name' => 'Gorra']);

    $this->actingAs($this->viewer)->get('/products?q=Camisa&line=uniforms')->assertInertia(function (Assert $page) {
        $products = $page->toArray()['props']['products'];

        expect($products['data'])->toHaveCount(15)
            ->and($products['last_page'])->toBe(2)
            ->and($products['total'])->toBe(16)
            ->and($products['next_page_url'])->toContain('q=Camisa')->toContain('line=uniforms');
    });

    $this->actingAs($this->viewer)->get('/products?q=Camisa&line=uniforms&page=2')->assertInertia(function (Assert $page) {
        expect(listedProductNames($page))->toBe(['Camisa 16']);
    });
});

// --- Detail page ------------------------------------------------------------------------------

it('exposes the general data of the product page', function () {
    $catalog = resCatalog();
    $camisa = $catalog['camisa'];
    $camisa->update(['description' => 'Camisa de trabajo']);

    $this->actingAs($this->viewer)->get("/products/{$camisa->id}")->assertInertia(function (Assert $page) use ($camisa) {
        $page->component('products/Show')
            ->where('product.id', $camisa->id)
            ->where('product.name', 'Camisa corporativa')
            ->where('product.description', 'Camisa de trabajo')
            ->where('product.category', $camisa->category->name)
            ->where('product.business_line_label', 'Uniformes')
            ->where('product.supply_mode', 'on_demand')
            ->where('product.supply_mode_label', 'Bajo pedido')
            ->where('product.status', 'active')
            ->where('product.status_label', 'Activo')
            ->where('product.portal_visible', true)
            ->where('product.admits_custom_color', true);
    });
});

it('does not announce custom color when the product cannot offer it', function () {
    $catalog = resCatalog();

    $this->actingAs($this->viewer)->get("/products/{$catalog['polo']->id}")->assertInertia(
        fn (Assert $page) => $page->where('product.admits_custom_color', false),
    );

    $this->actingAs($this->viewer)->get("/products/{$catalog['gorra']->id}")->assertInertia(
        fn (Assert $page) => $page->where('product.admits_custom_color', false),
    );
});

it('exposes the structure with roles, allowed values and order', function () {
    $catalog = resCatalog();

    $this->actingAs($this->viewer)->get("/products/{$catalog['camisa']->id}")->assertInertia(function (Assert $page) {
        $attributes = $page->toArray()['props']['product']['attributes'];

        expect(collect($attributes)->pluck('name')->all())->toBe(['Tela', 'Modelo', 'Manga', 'Género', 'Talla', 'Color'])
            ->and(collect($attributes)->pluck('role')->all())->toBe(['axis', 'axis', 'axis', 'axis', 'order', 'order'])
            ->and(collect($attributes)->pluck('role_label')->all())->toBe(['Eje', 'Eje', 'Eje', 'Eje', 'De pedido', 'De pedido'])
            ->and(collect($attributes[0]['values'])->pluck('name')->all())->toBe(['ALG-OXF Pima', 'Drill', 'Gabardina', 'Microfibra'])
            ->and(collect($attributes[4]['values'])->pluck('name')->all())->toBe(['S', 'M', 'L', 'XL', '2XL'])
            ->and($attributes[5]['values'])->toBe([])
            ->and($attributes[5]['follows_fabric'])->toBeTrue()
            ->and($attributes[0]['follows_fabric'])->toBeFalse();
    });
});

it('exposes the combinations with code, descriptive name, restrictions, included services and status', function () {
    $catalog = resCatalog();
    resCombination($catalog, $catalog['camisa'], '110-9', resAxesOfNames(), active: false);

    $this->actingAs($this->viewer)->get("/products/{$catalog['camisa']->id}")->assertInertia(function (Assert $page) {
        $rows = collect($page->toArray()['props']['product']['combinations']);

        expect($rows->pluck('code')->all())->toBe(['110', '110-1', '110-2', '110-4', '110-9', '159-1']);

        $first = $rows->firstWhere('code', '110-1');
        $restricted = $rows->firstWhere('code', '110-2');
        $inactive = $rows->firstWhere('code', '110-9');
        $alternative = $rows->firstWhere('code', '159-1');

        expect($first['name'])->toBe('Camisa corporativa · ALG-OXF Pima · Columbia especial · Manga corta · Dama')
            ->and($first['status'])->toBe('active')
            ->and($first['status_label'])->toBe('Activo')
            ->and($first['restrictions'])->toBe([])
            ->and($first['included_customizations'])->toBe([])
            ->and($alternative['name'])->toBe('Camisa corporativa · Drill o Gabardina · Clásico · Manga larga · Caballero')
            ->and($restricted['restrictions'])->toBe([['attribute' => 'Talla', 'values' => ['S', 'M', 'L', 'XL']]])
            ->and($restricted['included_customizations'])->toBe(['Vinil'])
            ->and($inactive['status'])->toBe('inactive');
    });
});

/**
 * @return array<string, list<string>>
 */
function resAxesOfNames(): array
{
    return array_map(fn (string $name): array => [$name], resAxesOf('110-1'));
}

it('exposes the details, customizations and images of the product', function () {
    $catalog = resCatalog();

    $this->actingAs($this->viewer)->get("/products/{$catalog['camisa']->id}")->assertInertia(function (Assert $page) {
        $product = $page->toArray()['props']['product'];

        expect(collect($product['detail_locations'])->pluck('name')->all())->toBe(['Orilla de mangas', 'Pechera'])
            ->and(collect($product['detail_locations'])->pluck('status')->all())->toBe(['inactive', 'active'])
            ->and(collect($product['customizations'])->pluck('name')->all())->toBe(['Bordado pequeño'])
            ->and($product['images'])->toBe(['has_main' => false, 'templates' => []]);
    });
});

it('exposes the default stock minimum and the overrides of the product', function () {
    $catalog = resCatalog();
    $gorra = $catalog['gorra'];
    StockMinimumOverride::query()->create([
        'combination_id' => $catalog['combos']['G-01']->id,
        'size_value_id' => null,
        'minimum' => 2,
    ]);

    $this->actingAs($this->viewer)->get("/products/{$gorra->id}")->assertInertia(function (Assert $page) {
        $page->where('product.supply_mode', 'stock_with_minimum')
            ->where('product.stock.default', 6)
            ->where('product.stock.overrides', [['code' => 'G-01', 'size' => null, 'minimum' => 2]]);
    });

    $this->actingAs($this->viewer)->get("/products/{$catalog['camisa']->id}")->assertInertia(
        fn (Assert $page) => $page->where('product.stock.default', null)->where('product.stock.overrides', []),
    );
});

it('shares the can flags of the user on the product page', function () {
    $product = Product::factory()->create();

    $this->actingAs($this->viewer)->get("/products/{$product->id}")->assertInertia(
        fn (Assert $page) => $page->where('can', ['update' => false, 'deactivate' => false, 'delete' => false, 'createCombination' => false]),
    );

    $manager = userWithPermissions(
        PermissionName::ProductsView,
        PermissionName::ProductsCreate,
        PermissionName::ProductsUpdate,
        PermissionName::ProductsDeactivate,
        PermissionName::ProductsDelete,
    );

    $this->actingAs($manager)->get("/products/{$product->id}")->assertInertia(
        fn (Assert $page) => $page->where('can', ['update' => true, 'deactivate' => true, 'delete' => true, 'createCombination' => true]),
    );

    $reader = userWithPermissions(PermissionName::ProductsView, PermissionName::ProductsDeactivate);

    $this->actingAs($reader)->get("/products/{$product->id}")->assertInertia(
        fn (Assert $page) => $page->where('can', ['update' => false, 'deactivate' => true, 'delete' => false, 'createCombination' => false]),
    );
});

it('shows an inactive product page and answers 404 for an unknown product', function () {
    $product = Product::factory()->inactive()->create();

    $this->actingAs($this->viewer)->get("/products/{$product->id}")->assertInertia(
        fn (Assert $page) => $page->where('product.status', 'inactive')->where('product.status_label', 'Inactivo'),
    );

    $this->actingAs($this->viewer)->get('/products/999999')->assertNotFound();
});

it('PRD-003 exposes can.create on the list only to a user with products.create', function () {
    $creator = userWithPermissions(PermissionName::ProductsView, PermissionName::ProductsCreate);

    $this->actingAs($creator)->get('/products')->assertInertia(fn (Assert $page) => $page->where('can.create', true));
    $this->actingAs($this->viewer)->get('/products')->assertInertia(fn (Assert $page) => $page->where('can.create', false));
});
