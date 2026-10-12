<?php

use App\Actions\Audit\RecordAuditEvent;
use App\Actions\Products\CreateCombination;
use App\Actions\Products\CreateProduct;
use App\Enums\AttributePresentation;
use App\Enums\AttributeRole;
use App\Enums\AttributeSpecialUse;
use App\Enums\AuditAction;
use App\Models\AttributeValue;
use App\Models\AuditLog;
use App\Models\CatalogAttribute;
use App\Models\CatalogCode;
use App\Models\Combination;
use App\Models\Combo;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use App\Support\Audit\AuditOrigin;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/*
 * `catalog:import`, colors and combinations (PRD-018, E-32, design Decision 19). Every CSV file is
 * fictitious and generated at runtime in a temporary directory that is removed after each test: no
 * file is ever committed. Row numbers are physical lines (header = 1, first data row = 2).
 */

beforeEach(function () {
    $this->catalogImportDirectory = sys_get_temp_dir().'/catalog-import-'.bin2hex(random_bytes(6));
    mkdir($this->catalogImportDirectory);
});

afterEach(function () {
    foreach (glob($this->catalogImportDirectory.'/*') ?: [] as $file) {
        unlink($file);
    }

    rmdir($this->catalogImportDirectory);
});

const CIMP_COMBINATION_COLUMNS = ['categoria', 'producto', 'linea', 'modo', 'stock_minimo', 'codigo', 'ejes', 'pedido', 'descripcion'];

const CIMP_COMBO_COLUMNS = ['codigo', 'nombre', 'producto', 'cantidad', 'valores'];

const CIMP_COLOR_COLUMNS = ['color', 'tono', 'telas'];

/**
 * Writes a file with the given content and returns its path.
 */
function cimpFile(string $content, string $name): string
{
    $path = test()->catalogImportDirectory.'/'.$name;
    file_put_contents($path, $content);

    return $path;
}

/**
 * CSV text from rows keyed by column name, in the order of `$columns`.
 *
 * @param  list<string>  $columns
 * @param  list<array<string, string>>  $rows
 */
function cimpCsv(array $columns, array $rows, string $delimiter = ','): string
{
    $stream = fopen('php://memory', 'w+');
    fputcsv($stream, $columns, $delimiter, '"', '');

    foreach ($rows as $row) {
        fputcsv($stream, array_map(fn (string $column): string => $row[$column] ?? '', $columns), $delimiter, '"', '');
    }

    rewind($stream);

    return (string) stream_get_contents($stream);
}

/**
 * A combination row with fictitious values; the overrides replace any column.
 *
 * @param  array<string, string>  $overrides
 * @return array<string, string>
 */
function cimpRow(array $overrides = []): array
{
    return $overrides + [
        'categoria' => 'Camisas',
        'producto' => 'Camisa corporativa',
        'linea' => 'uniformes',
        'modo' => 'bajo_pedido',
        'stock_minimo' => '',
        'codigo' => '110-1',
        'ejes' => 'Tela=ALG-OXF Pima|Modelo=Columbia|Género=Dama',
        'pedido' => 'Talla=XS,S,M,L,XL|Color=',
        'descripcion' => 'Camisa ficticia',
    ];
}

/**
 * Four valid rows: a shirt with fabric (two restricted rows and one unrestricted) and a cap with
 * its own colors.
 *
 * @return list<array<string, string>>
 */
function cimpRows(): array
{
    return [
        cimpRow(['pedido' => 'Talla=XS,S,M,L|Color=']),
        cimpRow(['codigo' => '110-2', 'ejes' => 'Tela=ALG-OXF Pima|Modelo=Columbia|Género=Caballero', 'pedido' => 'Talla=S,M,L,XL|Color=']),
        cimpRow(['codigo' => '110-3', 'ejes' => 'Tela=Dril Fino|Modelo=Columbia|Género=Dama']),
        cimpRow([
            'categoria' => 'Gorras', 'producto' => 'Gorra', 'modo' => 'stock_con_minimo', 'stock_minimo' => '6',
            'codigo' => '210-1', 'ejes' => '', 'pedido' => 'Color=Rojo,Azul', 'descripcion' => 'Gorra ficticia',
        ]),
    ];
}

/**
 * The palette: two fabrics offer colors, Verde is palette only.
 *
 * @return list<array<string, string>>
 */
function cimpColors(): array
{
    return [
        ['color' => 'Rojo', 'tono' => '#ff0000', 'telas' => 'ALG-OXF Pima|Dril Fino'],
        ['color' => 'Azul', 'tono' => '#0000FF', 'telas' => 'ALG-OXF Pima'],
        ['color' => 'Verde', 'tono' => '#00FF00', 'telas' => ''],
    ];
}

function cimpAuthor(): User
{
    return User::factory()->create(['email' => 'autor@ecolekua.test']);
}

/**
 * Builds the three files (valid ones unless given) and runs the command; an option set to `null`
 * is not passed. Returns the exit code and the whole console output.
 *
 * @param  list<array<string, string>>|null  $combinations
 * @param  list<array<string, string>>|null  $colors
 * @param  array<string, string|null>  $options
 * @return array{0: int, 1: string}
 */
function cimpRun(?array $combinations = null, ?array $colors = null, array $options = [], string $combosContent = ''): array
{
    $arguments = [
        '--combinations' => cimpFile(cimpCsv(CIMP_COMBINATION_COLUMNS, $combinations ?? cimpRows()), 'combinaciones.csv'),
        '--combos' => cimpFile($combosContent !== '' ? $combosContent : cimpCsv(CIMP_COMBO_COLUMNS, []), 'combos.csv'),
        '--colors' => cimpFile(cimpCsv(CIMP_COLOR_COLUMNS, $colors ?? cimpColors()), 'colores.csv'),
        '--author' => 'autor@ecolekua.test',
    ];

    foreach ($options as $name => $value) {
        if ($value === null) {
            unset($arguments["--{$name}"]);
        } else {
            $arguments["--{$name}"] = $value;
        }
    }

    $exit = Artisan::call('catalog:import', $arguments);

    return [$exit, Artisan::output()];
}

/**
 * The rows of the error table printed by the command: `[file, row, column, reason]`.
 *
 * @return list<array{0: string, 1: string, 2: string, 3: string}>
 */
function cimpReport(string $output): array
{
    $rows = [];

    foreach (explode("\n", $output) as $line) {
        if (! str_starts_with($line, '|')) {
            continue;
        }

        $cells = array_map(trim(...), explode('|', trim($line, "| \t\r")));

        if (count($cells) === 4 && $cells[0] !== 'Archivo') {
            $rows[] = [$cells[0], $cells[1], $cells[2], $cells[3]];
        }
    }

    return $rows;
}

/**
 * The reason reported for a file, row and column, or null when nothing was reported there.
 *
 * @param  list<array{0: string, 1: string, 2: string, 3: string}>  $report
 */
function cimpReason(array $report, string $file, int $row, string $column): ?string
{
    foreach ($report as $entry) {
        if ($entry[0] === $file && $entry[1] === (string) $row && $entry[2] === $column) {
            return $entry[3];
        }
    }

    return null;
}

/**
 * Row counts of every table the import writes.
 *
 * @return array<string, int>
 */
function cimpCatalogCounts(): array
{
    return [
        'categories' => ProductCategory::query()->count(),
        'attributes' => CatalogAttribute::query()->count(),
        'values' => AttributeValue::query()->count(),
        'products' => Product::query()->count(),
        'product_attributes' => DB::table('product_attributes')->count(),
        'combinations' => Combination::query()->count(),
        'codes' => CatalogCode::query()->count(),
        'combos' => Combo::query()->count(),
        'offered_colors' => DB::table('fabric_offered_colors')->count(),
    ];
}

/**
 * Asserts the failed run left the catalog and the audit trail untouched.
 */
function cimpExpectUntouched(int $auditBefore): void
{
    expect(array_sum(cimpCatalogCounts()))->toBe(0)
        ->and(AuditLog::query()->count())->toBe($auditBefore);
}

// ---------------------------------------------------------------------------------------------
// Phase 0: author, options, empty catalog, files and headers
// ---------------------------------------------------------------------------------------------

it('PRD-018 requires an active internal author and imports nothing without one', function (?string $email) {
    $inactive = User::factory()->inactive()->create(['email' => 'inactivo@ecolekua.test']);
    cimpAuthor();
    $auditBefore = AuditLog::query()->count();

    [$exit, $output] = cimpRun(options: ['author' => $email === 'inactive' ? $inactive->email : $email]);

    expect($exit)->toBe(1)
        ->and($output)->toContain($email === null ? 'Debe indicar el autor' : 'El autor indicado no existe o está inactivo');
    cimpExpectUntouched($auditBefore);
})->with([
    'missing' => [null],
    'unknown' => ['nadie@ecolekua.test'],
    'inactive' => ['inactive'],
]);

it('PRD-018 requires the three file options', function (string $missing, string $label) {
    cimpAuthor();
    $auditBefore = AuditLog::query()->count();

    [$exit, $output] = cimpRun(options: [$missing => null]);

    expect($exit)->toBe(1)
        ->and($output)->toContain("Debe indicar el archivo {$label}")
        ->and($output)->toContain("--{$missing}");
    cimpExpectUntouched($auditBefore);
})->with([
    'combinations' => ['combinations', 'de combinaciones'],
    'combos' => ['combos', 'de combos'],
    'colors' => ['colors', 'de colores'],
]);

it('PRD-018 imports only into an empty catalog', function (Closure $seed) {
    cimpAuthor();
    $seed();
    $before = cimpCatalogCounts();
    $auditBefore = AuditLog::query()->count();

    [$exit, $output] = cimpRun();

    expect($exit)->toBe(1)
        ->and($output)->toContain('El catálogo no está vacío')
        ->and(cimpCatalogCounts())->toBe($before)
        ->and(AuditLog::query()->count())->toBe($auditBefore);
})->with([
    'a category' => [fn () => ProductCategory::factory()->create()],
    'an attribute' => [fn () => CatalogAttribute::factory()->create()],
    'a product' => [fn () => Product::factory()->create()],
    'a combo' => [fn () => Combo::factory()->create()],
]);

it('PRD-018 rejects a file whose header is not the exact one and names the file', function (string $file, string $content, string $reason) {
    cimpAuthor();
    $auditBefore = AuditLog::query()->count();
    $wrong = cimpFile($content, 'mal.csv');

    [$exit, $output] = cimpRun(options: [$file => $wrong]);

    expect($exit)->toBe(1)
        ->and($output)->toContain($reason);
    cimpExpectUntouched($auditBefore);
})->with([
    'combinations missing column' => ['combinations', "categoria,producto\nA,B\n", 'Faltan las columnas: linea, modo'],
    'colors unknown column' => ['colors', "color,tono,telas,extra\nA,#000000,,x\n", 'Columnas no reconocidas: extra'],
    'combos missing column' => ['combos', "codigo,nombre\n", 'Faltan las columnas: producto, cantidad, valores'],
]);

it('PRD-018 names the file in the header problem', function () {
    cimpAuthor();

    [, $output] = cimpRun(options: ['colors' => cimpFile("color\n", 'mal.csv')]);

    expect($output)->toContain('colores')
        ->and($output)->toContain('Faltan las columnas: tono, telas');
});

it('PRD-018 accepts the combos and colors files with only a header', function () {
    cimpAuthor();

    [$exit, $output] = cimpRun([cimpRow()], colors: []);

    expect($exit)->toBe(0)
        ->and($output)->toContain('Se importó')
        ->and(Combination::query()->count())->toBe(1)
        ->and(AttributeValue::query()->whereHas('catalogAttribute', fn ($query) => $query->where('presentation', AttributePresentation::Color->value))->count())->toBe(0);
});

it('PRD-018 warns that combo rows are not imported yet and still imports the rest', function () {
    cimpAuthor();
    $combos = cimpCsv(CIMP_COMBO_COLUMNS, [['codigo' => 'K-1', 'nombre' => 'Kit', 'producto' => 'Camisa corporativa', 'cantidad' => '1', 'valores' => '']]);

    [$exit, $output] = cimpRun(combosContent: $combos);

    expect($exit)->toBe(0)
        ->and($output)->toContain('1 fila(s) de combos')
        ->and(Combo::query()->count())->toBe(0)
        ->and(Combination::query()->count())->toBe(4);
});

// ---------------------------------------------------------------------------------------------
// Phase 1: E-32 (partial), one file per failure class
// ---------------------------------------------------------------------------------------------

it('E-32 reports an invalid row by file, row and column and imports nothing', function () {
    cimpAuthor();
    $auditBefore = AuditLog::query()->count();
    $rows = cimpRows();
    $rows[1]['linea'] = 'ropa';
    $rows[2]['modo'] = 'raro';

    [$exit, $output] = cimpRun($rows);
    $report = cimpReport($output);

    expect($exit)->toBe(1)
        ->and($output)->toContain('Archivo')
        ->and($output)->toContain('Motivo')
        ->and(cimpReason($report, 'combinaciones', 3, 'linea'))->not->toBeNull()
        ->and(cimpReason($report, 'combinaciones', 4, 'modo'))->not->toBeNull()
        ->and(cimpReason($report, 'combinaciones', 2, 'linea'))->toBeNull();
    cimpExpectUntouched($auditBefore);
});

it('E-32 reports a repeated code in the file ignoring letter case', function () {
    cimpAuthor();
    $auditBefore = AuditLog::query()->count();
    $rows = cimpRows();
    $rows[0]['codigo'] = 'abc-1';
    $rows[1]['codigo'] = 'ABC-1';

    [$exit, $output] = cimpRun($rows);

    expect($exit)->toBe(1)
        ->and(cimpReason(cimpReport($output), 'combinaciones', 3, 'codigo'))->toBe('Código repetido en la fila 2.')
        ->and($output)->not->toContain('ABC-1');
    cimpExpectUntouched($auditBefore);
});

it('E-32 reports a color without tone and a malformed tone', function () {
    cimpAuthor();
    $auditBefore = AuditLog::query()->count();
    $colors = cimpColors();
    $colors[0]['tono'] = '';
    $colors[1]['tono'] = 'azul';

    [$exit, $output] = cimpRun(colors: $colors);
    $report = cimpReport($output);

    expect($exit)->toBe(1)
        ->and(cimpReason($report, 'colores', 2, 'tono'))->not->toBeNull()
        ->and(cimpReason($report, 'colores', 3, 'tono'))->not->toBeNull()
        ->and(cimpReason($report, 'colores', 4, 'tono'))->toBeNull();
    cimpExpectUntouched($auditBefore);
});

it('E-32 reports a repeated color name in the colors file', function () {
    cimpAuthor();
    $auditBefore = AuditLog::query()->count();
    $colors = cimpColors();
    $colors[2]['color'] = 'rojo';

    [$exit, $output] = cimpRun(colors: $colors);

    expect($exit)->toBe(1)
        ->and(cimpReason(cimpReport($output), 'colores', 4, 'color'))->toBe('Color repetido en la fila 2.');
    cimpExpectUntouched($auditBefore);
});

it('E-32 reports a fabric of the colors file that is not a Tela value of the combinations file', function () {
    cimpAuthor();
    $auditBefore = AuditLog::query()->count();
    $colors = cimpColors();
    $colors[1]['telas'] = 'ALG-OXF Pima|Seda Imaginaria';

    [$exit, $output] = cimpRun(colors: $colors);

    expect($exit)->toBe(1)
        ->and(cimpReason(cimpReport($output), 'colores', 3, 'telas'))->not->toBeNull()
        ->and($output)->not->toContain('Seda Imaginaria');
    cimpExpectUntouched($auditBefore);
});

it('E-32 reports a color of a product without fabric that is not in the palette', function () {
    cimpAuthor();
    $auditBefore = AuditLog::query()->count();
    $rows = cimpRows();
    $rows[3]['pedido'] = 'Color=Rojo,Rosa';

    [$exit, $output] = cimpRun($rows);

    expect($exit)->toBe(1)
        ->and(cimpReason(cimpReport($output), 'combinaciones', 5, 'pedido'))->not->toBeNull();
    cimpExpectUntouched($auditBefore);
});

it('DEC-PRD-48 reports a stock_minimo that disagrees with the first row of the product', function () {
    cimpAuthor();
    $auditBefore = AuditLog::query()->count();
    $cap = ['categoria' => 'Gorras', 'producto' => 'Gorra', 'modo' => 'stock_con_minimo', 'pedido' => 'Color=Rojo'];
    $rows = [
        cimpRow($cap + ['stock_minimo' => '6', 'codigo' => '210-1', 'ejes' => 'Modelo=Plana']),
        cimpRow($cap + ['stock_minimo' => '8', 'codigo' => '210-2', 'ejes' => 'Modelo=Curva']),
    ];

    [$exit, $output] = cimpRun($rows);

    expect($exit)->toBe(1)
        ->and(cimpReason(cimpReport($output), 'combinaciones', 3, 'stock_minimo'))->toBe('Difiere del stock mínimo de la fila 2.');
    cimpExpectUntouched($auditBefore);
});

it('DEC-PRD-48 accepts equal minimums on every row and stores the product default', function () {
    cimpAuthor();
    $cap = ['categoria' => 'Gorras', 'producto' => 'Gorra', 'modo' => 'stock_con_minimo', 'stock_minimo' => '6', 'pedido' => 'Color=Rojo'];

    [$exit] = cimpRun([
        cimpRow($cap + ['codigo' => '210-1', 'ejes' => 'Modelo=Plana']),
        cimpRow($cap + ['codigo' => '210-2', 'ejes' => 'Modelo=Curva']),
    ], colors: [['color' => 'Rojo', 'tono' => '#FF0000', 'telas' => '']]);

    expect($exit)->toBe(0)
        ->and(Product::query()->where('name', 'Gorra')->value('min_stock_default'))->toBe(6)
        ->and(DB::table('stock_minimum_overrides')->count())->toBe(0);
});

it('DEC-PRD-50 rejects Tela listed in pedido and Color listed in ejes', function () {
    cimpAuthor();
    $auditBefore = AuditLog::query()->count();
    $rows = [
        cimpRow(['codigo' => '1', 'ejes' => 'Modelo=Columbia', 'pedido' => 'Tela=ALG-OXF Pima|Talla=S']),
        cimpRow(['producto' => 'Otra camisa', 'codigo' => '2', 'ejes' => 'Color=Rojo|Modelo=Columbia', 'pedido' => 'Talla=S']),
    ];

    [$exit, $output] = cimpRun($rows);
    $report = cimpReport($output);

    expect($exit)->toBe(1)
        ->and(cimpReason($report, 'combinaciones', 2, 'pedido'))->toBe(__('validation.structure_role_fabric'))
        ->and(cimpReason($report, 'combinaciones', 3, 'ejes'))->toBe(__('validation.structure_role_color'));
    cimpExpectUntouched($auditBefore);
});

it('PRD-018 rejects rows of the same product that disagree in category, line, mode or axes', function () {
    cimpAuthor();
    $auditBefore = AuditLog::query()->count();
    $rows = [
        cimpRow(['codigo' => '1']),
        cimpRow(['codigo' => '2', 'categoria' => 'Otras', 'ejes' => 'Tela=ALG-OXF Pima|Modelo=Columbia|Género=Caballero']),
        cimpRow(['codigo' => '3', 'linea' => 'pañales', 'ejes' => 'Tela=ALG-OXF Pima|Modelo=Columbia|Género=Otro']),
        cimpRow(['codigo' => '4', 'modo' => 'stock_agotable', 'ejes' => 'Modelo=Columbia|Tela=ALG-OXF Pima|Género=Otro2']),
    ];

    [$exit, $output] = cimpRun($rows);
    $report = cimpReport($output);

    expect($exit)->toBe(1)
        ->and(cimpReason($report, 'combinaciones', 3, 'categoria'))->toBe('Difiere de la categoría de la fila 2.')
        ->and(cimpReason($report, 'combinaciones', 4, 'linea'))->toBe('Difiere de la línea de la fila 2.')
        ->and(cimpReason($report, 'combinaciones', 5, 'modo'))->toBe('Difiere del modo de la fila 2.')
        ->and(cimpReason($report, 'combinaciones', 5, 'ejes'))->toBe('El orden de los ejes difiere del de la fila 2.');
    cimpExpectUntouched($auditBefore);
});

it('PRD-018 rejects a combination that overlaps an earlier one on every axis', function () {
    cimpAuthor();
    $auditBefore = AuditLog::query()->count();
    $rows = [
        cimpRow(['codigo' => '1', 'ejes' => 'Modelo=Columbia|Género=Dama,Caballero', 'pedido' => 'Talla=S']),
        cimpRow(['codigo' => '2', 'ejes' => 'Modelo=Columbia|Género=Caballero', 'pedido' => 'Talla=S']),
    ];

    [$exit, $output] = cimpRun($rows);

    expect($exit)->toBe(1)
        ->and(cimpReason(cimpReport($output), 'combinaciones', 3, 'ejes'))->toBe('Coincide con la combinación de la fila 2 en todos sus ejes.');
    cimpExpectUntouched($auditBefore);
});

it('PRD-018 rejects an order attribute without values unless it is the color of a product with fabric', function () {
    cimpAuthor();
    $auditBefore = AuditLog::query()->count();
    $rows = cimpRows();
    $rows[0]['pedido'] = 'Talla=|Color=';
    $rows[3]['pedido'] = 'Color=';

    [$exit, $output] = cimpRun($rows);
    $report = cimpReport($output);

    expect($exit)->toBe(1)
        ->and(cimpReason($report, 'combinaciones', 2, 'pedido'))->not->toBeNull()
        ->and(cimpReason($report, 'combinaciones', 5, 'pedido'))->not->toBeNull()
        ->and(cimpReason($report, 'combinaciones', 3, 'pedido'))->toBeNull();
    cimpExpectUntouched($auditBefore);
});

it('PRD-018 rejects color values on a product that declares the fabric', function () {
    cimpAuthor();
    $auditBefore = AuditLog::query()->count();
    $rows = cimpRows();
    $rows[0]['pedido'] = 'Talla=XS,S,M,L|Color=Rojo';

    [$exit, $output] = cimpRun($rows);

    expect($exit)->toBe(1)
        ->and(cimpReason(cimpReport($output), 'combinaciones', 2, 'pedido'))->toBe(__('validation.structure_color_values_not_allowed'));
    cimpExpectUntouched($auditBefore);
});

it('E-32 never prints cell values, even for every failure class at once', function () {
    cimpAuthor();
    $rows = cimpRows();
    $rows[0]['producto'] = 'Zzqx secreto';
    $rows[0]['linea'] = 'Wwvy oculta';
    $rows[0]['codigo'] = 'Qqrt-999';
    $rows[1]['codigo'] = 'qqrt-999';
    $colors = cimpColors();
    $colors[0]['color'] = 'Jjkl privado';
    $colors[0]['tono'] = 'Mmnb';

    [$exit, $output] = cimpRun($rows, $colors);

    expect($exit)->toBe(1)
        ->and(cimpReport($output))->not->toBe([]);

    foreach (['Zzqx', 'Wwvy', 'Qqrt', 'Jjkl', 'Mmnb'] as $secret) {
        expect($output)->not->toContain($secret);
    }
});

// ---------------------------------------------------------------------------------------------
// Phase 2: creation through the real Actions, in one transaction
// ---------------------------------------------------------------------------------------------

it('PRD-018 creates the catalog from valid files in one run', function () {
    cimpAuthor();

    [$exit, $output] = cimpRun();

    expect($exit)->toBe(0)
        ->and($output)->toContain('Se importó')
        ->and(cimpCatalogCounts())->toBe([
            'categories' => 2,
            'attributes' => 5,
            'values' => 13,
            'products' => 2,
            'product_attributes' => 6,
            'combinations' => 4,
            'codes' => 4,
            'combos' => 0,
            'offered_colors' => 3,
        ])
        ->and(ProductCategory::query()->orderBy('sort_order')->pluck('name')->all())->toBe(['Camisas', 'Gorras'])
        ->and(CatalogCode::query()->orderBy('code')->pluck('code')->all())->toBe(['110-1', '110-2', '110-3', '210-1']);
});

it('DEC-PRD-49 assigns the special uses to Tela, Genero and Talla and Color as the color attribute', function () {
    cimpAuthor();

    cimpRun();
    $attributes = CatalogAttribute::query()->orderBy('sort_order')->get()->keyBy('name');

    expect($attributes->keys()->all())->toBe(['Tela', 'Modelo', 'Género', 'Talla', 'Color'])
        ->and($attributes['Tela']->special_use)->toBe(AttributeSpecialUse::Fabric)
        ->and($attributes['Género']->special_use)->toBe(AttributeSpecialUse::Gender)
        ->and($attributes['Talla']->special_use)->toBe(AttributeSpecialUse::Size)
        ->and($attributes['Modelo']->special_use)->toBeNull()
        ->and($attributes['Modelo']->presentation)->toBe(AttributePresentation::Text)
        ->and($attributes['Color']->presentation)->toBe(AttributePresentation::Color)
        ->and($attributes['Color']->special_use)->toBeNull();
});

it('PRD-018 creates the palette in file order with uppercase tones and the offered colors per fabric', function () {
    cimpAuthor();

    cimpRun();
    $color = CatalogAttribute::query()->where('name', 'Color')->firstOrFail();
    $palette = $color->values()->orderBy('sort_order')->get();
    $fabric = fn (string $name) => AttributeValue::query()->where('name', $name)->firstOrFail()->offeredColors()->orderBy('name')->pluck('name')->all();

    expect($palette->pluck('name')->all())->toBe(['Rojo', 'Azul', 'Verde'])
        ->and($palette->pluck('tone')->all())->toBe(['#FF0000', '#0000FF', '#00FF00'])
        ->and($fabric('ALG-OXF Pima'))->toBe(['Azul', 'Rojo'])
        ->and($fabric('Dril Fino'))->toBe(['Rojo']);
});

it('PRD-018 creates values in order of first appearance and never sets an SVG layer', function () {
    cimpAuthor();

    cimpRun();
    $values = fn (string $attribute) => CatalogAttribute::query()->where('name', $attribute)->firstOrFail()->values()->orderBy('sort_order')->pluck('name')->all();

    expect($values('Tela'))->toBe(['ALG-OXF Pima', 'Dril Fino'])
        ->and($values('Género'))->toBe(['Dama', 'Caballero'])
        ->and($values('Talla'))->toBe(['XS', 'S', 'M', 'L', 'XL'])
        ->and(AttributeValue::query()->whereNotNull('svg_layer')->count())->toBe(0);
});

it('DEC-PRD-50 declares Tela as an axis, Color as an order attribute and a color without values on a fabric product', function () {
    cimpAuthor();

    cimpRun();
    $structure = function (string $product): array {
        return Product::query()->where('name', $product)->firstOrFail()
            ->productAttributes()->with(['catalogAttribute', 'allowedValues'])->orderBy('sort_order')->get()
            ->map(fn ($row): array => [
                $row->catalogAttribute->name,
                $row->role,
                $row->allowedValues->sortBy('sort_order')->pluck('name')->all(),
            ])->all();
    };

    expect($structure('Camisa corporativa'))->toBe([
        ['Tela', AttributeRole::Axis, ['ALG-OXF Pima', 'Dril Fino']],
        ['Modelo', AttributeRole::Axis, ['Columbia']],
        ['Género', AttributeRole::Axis, ['Dama', 'Caballero']],
        ['Talla', AttributeRole::Order, ['XS', 'S', 'M', 'L', 'XL']],
        ['Color', AttributeRole::Order, []],
    ])->and($structure('Gorra'))->toBe([
        ['Color', AttributeRole::Order, ['Rojo', 'Azul']],
    ]);
});

it('DEC-PRD-36 turns a row whose order values are a strict subset of the product into a restriction', function () {
    cimpAuthor();

    cimpRun();
    $talla = CatalogAttribute::query()->where('name', 'Talla')->firstOrFail();
    $restriction = fn (string $code) => CatalogCode::query()->where('code', $code)->firstOrFail()->combination
        ->values()->wherePivot('catalog_attribute_id', $talla->id)->orderBy('attribute_values.sort_order')->pluck('attribute_values.name')->all();

    expect($restriction('110-1'))->toBe(['XS', 'S', 'M', 'L'])
        ->and($restriction('110-2'))->toBe(['S', 'M', 'L', 'XL'])
        ->and($restriction('110-3'))->toBe([])
        ->and($restriction('210-1'))->toBe([]);
});

it('PRD-018 stores the product data of the first row and the description of each combination', function () {
    cimpAuthor();

    cimpRun();
    $cap = Product::query()->where('name', 'Gorra')->firstOrFail();

    expect($cap->business_line->value)->toBe('uniforms')
        ->and($cap->supply_mode->value)->toBe('stock_with_minimum')
        ->and($cap->min_stock_default)->toBe(6)
        ->and($cap->category->name)->toBe('Gorras')
        ->and(CatalogCode::query()->where('code', '210-1')->firstOrFail()->combination->description)->toBe('Gorra ficticia');
});

it('PRD-018 audits every created row as an import with the console origin and the author', function () {
    $author = cimpAuthor();
    $auditBefore = (int) AuditLog::query()->max('id');

    cimpRun();
    $logs = AuditLog::query()->where('id', '>', $auditBefore)->get();

    expect($logs)->not->toBeEmpty()
        ->and($logs->pluck('action')->unique()->sort()->values()->all())->toBe([
            AuditAction::CatalogCreated->value,
            AuditAction::CatalogFabricColorsUpdated->value,
            AuditAction::ProductAttributesUpdated->value,
            AuditAction::CombinationCreated->value,
            AuditAction::ProductCreated->value,
        ]);

    foreach ($logs as $log) {
        expect($log->actor_id)->toBe($author->id)
            ->and($log->context['import'])->toBeTrue()
            ->and($log->context['source'])->toBe('console')
            ->and($log->context['command'])->toBe('catalog:import')
            ->and($log->context['import_file'])->toBeIn(['combinaciones', 'colores'])
            ->and($log->context['import_row'])->toBeInt();
    }

    $red = $logs->first(fn (AuditLog $log): bool => $log->action === AuditAction::CatalogCreated->value && ($log->new_values['name'] ?? null) === 'Rojo');
    $shirt = $logs->first(fn (AuditLog $log): bool => $log->action === AuditAction::CombinationCreated->value && ($log->new_values['code'] ?? null) === '110-2');

    expect($red->context['import_file'])->toBe('colores')
        ->and($red->context['import_row'])->toBe(2)
        ->and($shirt->context['import_file'])->toBe('combinaciones')
        ->and($shirt->context['import_row'])->toBe(3);
});

it('PRD-018 rolls everything back when an exception happens in the middle of the run', function () {
    cimpAuthor();
    $auditBefore = AuditLog::query()->count();
    $this->app->bind(CreateCombination::class, fn ($app) => new class($app->make(RecordAuditEvent::class)) extends CreateCombination
    {
        private int $calls = 0;

        public function handle(Product $product, array $data, User $actor, ?AuditOrigin $origin = null, array $auditContext = []): Combination
        {
            if (++$this->calls > 2) {
                throw new RuntimeException('fallo forzado');
            }

            return parent::handle($product, $data, $actor, $origin, $auditContext);
        }
    });

    [$exit, $output] = cimpRun();

    expect($exit)->toBe(1)
        ->and($output)->toContain('No se importó nada')
        ->and($output)->toContain('RuntimeException')
        ->and($output)->not->toContain('fallo forzado');
    cimpExpectUntouched($auditBefore);
});

it('E-32 reports a row rejected while creating, rolls everything back and never prints the value', function () {
    cimpAuthor();
    $auditBefore = AuditLog::query()->count();
    $this->app->bind(CreateProduct::class, fn ($app) => new class($app->make(RecordAuditEvent::class)) extends CreateProduct
    {
        private int $calls = 0;

        public function handle(array $data, User $actor, ?AuditOrigin $origin = null, array $auditContext = []): Product
        {
            if (++$this->calls > 1) {
                throw ValidationException::withMessages(['name' => __('validation.product_name_unique')]);
            }

            return parent::handle($data, $actor, $origin, $auditContext);
        }
    });

    [$exit, $output] = cimpRun();

    expect($exit)->toBe(1)
        ->and(cimpReport($output))->toBe([['combinaciones', '5', 'producto', __('validation.product_name_unique')]])
        ->and($output)->not->toContain('Gorra');
    cimpExpectUntouched($auditBefore);
});
