<?php

use App\Actions\Customers\CreateCustomer;
use App\Enums\AuditAction;
use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Enums\DocumentType;
use App\Enums\PermissionName;
use App\Enums\VenezuelanState;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\User;
use App\Support\Audit\AuditOrigin;
use App\Support\Customers\CustomerCsvReader;
use App\Support\Customers\InvalidCustomerCsv;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/*
 * `customers:import` (CLI-018, design Decision 14). Every CSV file is fictitious and generated at
 * runtime in a temporary directory that is removed after each test: no file is ever committed.
 */

const IMPORT_COLUMNS = [
    'tipo', 'nombre', 'tipo_documento', 'numero_documento', 'telefono', 'correo',
    'cumpleanos_dia', 'cumpleanos_mes', 'aniversario_dia', 'aniversario_mes', 'observaciones',
    'contacto_nombre', 'contacto_cargo', 'contacto_telefono', 'contacto_correo',
    'direccion', 'direccion_ciudad', 'direccion_estado', 'direccion_referencia',
    'correo_asesora',
];

beforeEach(function () {
    $this->importDirectory = sys_get_temp_dir().'/customers-import-'.bin2hex(random_bytes(6));
    mkdir($this->importDirectory);
});

afterEach(function () {
    foreach (glob($this->importDirectory.'/*') ?: [] as $file) {
        unlink($file);
    }

    rmdir($this->importDirectory);
});

/**
 * Writes a file with the given content and returns its path.
 */
function importFile(string $content, string $name = 'clientes.csv'): string
{
    $path = test()->importDirectory.'/'.$name;
    file_put_contents($path, $content);

    return $path;
}

/**
 * Builds CSV text from rows keyed by column name, in the order of `$columns`.
 *
 * @param  list<array<string, string>>  $rows
 * @param  list<string>  $columns
 */
function importCsv(array $rows, string $delimiter = ',', array $columns = IMPORT_COLUMNS): string
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
 * Runs the command and returns its exit code and the whole console output.
 *
 * @param  array<string, mixed>  $options
 * @return array{0: int, 1: string}
 */
function runImport(string $path, ?string $author, array $options = []): array
{
    $arguments = ['file' => $path, ...$options];

    if ($author !== null) {
        $arguments['--author'] = $author;
    }

    $exit = Artisan::call('customers:import', $arguments);

    return [$exit, Artisan::output()];
}

function importAuthor(): User
{
    return User::factory()->create(['email' => 'autor@ecolekua.test']);
}

it('E-39 rejects a file that is not UTF-8 and imports nothing', function () {
    $author = importAuthor();
    $latin1 = mb_convert_encoding(importCsv([['tipo' => 'natural', 'nombre' => 'Muñoz', 'telefono' => '0414-1234567']]), 'ISO-8859-1', 'UTF-8');

    [$exit, $output] = runImport(importFile($latin1), $author->email);

    expect($exit)->toBe(1)
        ->and($output)->toContain('no está codificado en UTF-8')
        ->and(Customer::query()->count())->toBe(0);
});

it('E-39 accepts the same text once it is valid UTF-8', function () {
    $content = importCsv([['tipo' => 'natural', 'nombre' => 'Muñoz', 'telefono' => '0414-1234567']]);

    $rows = (new CustomerCsvReader)->read(importFile($content));

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['cells']['nombre'])->toBe('Muñoz');
});

it('E-39 lists the missing column when the header has no telefono', function () {
    $author = importAuthor();
    $columns = array_values(array_diff(IMPORT_COLUMNS, ['telefono']));
    $csv = importCsv([['tipo' => 'natural', 'nombre' => 'Ana']], columns: $columns);

    [$exit, $output] = runImport(importFile($csv), $author->email);

    expect($exit)->toBe(1)
        ->and($output)->toContain('Faltan las columnas: telefono')
        ->and($output)->not->toContain('Columnas no reconocidas')
        ->and(Customer::query()->count())->toBe(0);
});

it('E-39 lists unknown extra columns and the missing ones together', function () {
    $author = importAuthor();
    $columns = [...array_values(array_diff(IMPORT_COLUMNS, ['telefono', 'correo'])), 'color_favorito', 'extra'];
    $csv = importCsv([['tipo' => 'natural']], columns: $columns);

    [$exit, $output] = runImport(importFile($csv), $author->email);

    expect($exit)->toBe(1)
        ->and($output)->toContain('Faltan las columnas: telefono, correo')
        ->and($output)->toContain('Columnas no reconocidas: color_favorito, extra')
        ->and(Customer::query()->count())->toBe(0);
});

it('E-39 rejects a repeated column in the header', function () {
    $author = importAuthor();
    $csv = importCsv([['tipo' => 'natural']], columns: [...IMPORT_COLUMNS, 'NOMBRE']);

    [$exit, $output] = runImport(importFile($csv), $author->email);

    expect($exit)->toBe(1)
        ->and($output)->toContain('Columnas repetidas: nombre');
});

it('E-39 accepts the header in any order and case, with spaces around the names', function () {
    $columns = array_reverse(IMPORT_COLUMNS);
    $header = implode(',', array_map(fn (string $column): string => ' '.strtoupper($column).' ', $columns));
    $values = implode(',', array_map(fn (string $column): string => $column === 'nombre' ? 'Ana' : '', $columns));

    $rows = (new CustomerCsvReader)->read(importFile($header."\n".$values."\n"));

    expect($rows)->toHaveCount(1)
        ->and(array_keys($rows[0]['cells']))->toBe($columns)
        ->and($rows[0]['cells']['nombre'])->toBe('Ana')
        ->and($rows[0]['cells']['telefono'])->toBe('');
});

it('E-39 accepts a UTF-8 file with a byte order mark', function () {
    $content = "\xEF\xBB\xBF".importCsv([['tipo' => 'natural', 'nombre' => 'Ana', 'telefono' => '0414-1234567']]);

    $rows = (new CustomerCsvReader)->read(importFile($content));

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['cells']['tipo'])->toBe('natural')
        ->and($rows[0]['cells']['telefono'])->toBe('0414-1234567');
});

it('E-39 accepts the semicolon delimiter', function () {
    $content = importCsv([['tipo' => 'natural', 'nombre' => 'Ana, la primera', 'telefono' => '0414-1234567']], ';');

    $rows = (new CustomerCsvReader)->read(importFile($content));

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['cells']['nombre'])->toBe('Ana, la primera')
        ->and($rows[0]['cells']['telefono'])->toBe('0414-1234567');
});

it('E-39 parses a quoted cell with a delimiter and a line break and numbers rows by physical line', function () {
    $content = importCsv([
        ['tipo' => 'natural', 'nombre' => 'Primera', 'telefono' => '0414-1000001', 'observaciones' => "uno, dos\ntres"],
        ['tipo' => 'natural', 'nombre' => 'Segunda', 'telefono' => '0414-1000002'],
    ]);

    $rows = (new CustomerCsvReader)->read(importFile($content));

    expect($rows)->toHaveCount(2)
        ->and($rows[0]['cells']['observaciones'])->toBe("uno, dos\ntres")
        ->and($rows[0]['line'])->toBe(2)
        ->and($rows[1]['cells']['nombre'])->toBe('Segunda')
        ->and($rows[1]['line'])->toBe(4);
});

it('E-39 skips blank lines, reads CRLF files and keeps the physical line numbers', function () {
    $rows = [
        ['tipo' => 'natural', 'nombre' => 'Primera', 'telefono' => '0414-1000001'],
        ['tipo' => 'natural', 'nombre' => 'Tercera', 'telefono' => '0414-1000003'],
    ];
    $lines = explode("\n", rtrim(importCsv($rows)));
    $content = $lines[0]."\r\n".$lines[1]."\r\n"."\r\n".str_repeat(',', count(IMPORT_COLUMNS) - 1)."\r\n".$lines[2]."\r\n\r\n";

    $read = (new CustomerCsvReader)->read(importFile($content));

    expect($read)->toHaveCount(2)
        ->and($read[0]['cells']['nombre'])->toBe('Primera')
        ->and($read[0]['line'])->toBe(2)
        ->and($read[1]['cells']['nombre'])->toBe('Tercera')
        ->and($read[1]['line'])->toBe(5);
});

it('E-39 rejects a row whose number of cells differs from the header, naming only the line', function () {
    $author = importAuthor();
    $lines = explode("\n", rtrim(importCsv([['tipo' => 'natural', 'nombre' => 'Secreto', 'telefono' => '0414-1234567']])));
    $content = $lines[0]."\n".$lines[1].",sobra\n";

    [$exit, $output] = runImport(importFile($content), $author->email);

    expect($exit)->toBe(1)
        ->and($output)->toContain('distinto número de columnas que el encabezado: 2')
        ->and($output)->not->toContain('Secreto')
        ->and(Customer::query()->count())->toBe(0);
});

it('E-39 rejects an empty file and a file with a header but no rows', function () {
    $author = importAuthor();

    [$emptyExit, $emptyOutput] = runImport(importFile(''), $author->email);
    [$headerExit, $headerOutput] = runImport(importFile(importCsv([])), $author->email);

    expect($emptyExit)->toBe(1)
        ->and($emptyOutput)->toContain('El archivo está vacío')
        ->and($headerExit)->toBe(1)
        ->and($headerOutput)->toContain('El archivo no contiene filas para importar');
});

it('E-39 rejects a path that does not exist, with a reason', function () {
    $author = importAuthor();

    [$exit, $output] = runImport($this->importDirectory.'/no-existe.csv', $author->email);

    expect($exit)->toBe(1)
        ->and($output)->toContain('no existe o no se puede leer')
        ->and(Customer::query()->count())->toBe(0);
});

it('E-39 reports a reader failure as a list of reasons', function () {
    $columns = array_values(array_diff(IMPORT_COLUMNS, ['telefono']));

    try {
        (new CustomerCsvReader)->read(importFile(importCsv([], columns: $columns)));
        $problems = [];
    } catch (InvalidCustomerCsv $exception) {
        $problems = $exception->problems;
    }

    expect($problems)->toBe(['Faltan las columnas: telefono.']);
});

/*
|--------------------------------------------------------------------------
| Row validation, report and transaction (unit 9b)
|--------------------------------------------------------------------------
*/

const IMPORT_ADVISOR_REASON = 'La asesora debe ser un usuario activo con permiso para tener cartera.';

const IMPORT_AUTHOR_REASON = 'El autor indicado no existe o está inactivo.';

const IMPORT_REPORT_HEADER = ['Fila', 'Columna', 'Motivo'];

const IMPORT_WARNING_HEADER = ['Fila', 'Advertencia'];

/** Marker put in every cell of the leak tests: it must never come back in the console output. */
const IMPORT_SENTINEL = 'zq7';

/**
 * A valid natural customer row with a phone that no other row of the same run shares.
 *
 * @param  array<string, string>  $overrides
 * @return array<string, string>
 */
function importRow(array $overrides = []): array
{
    static $sequence = 0;
    $sequence++;

    return [
        'tipo' => 'natural',
        'nombre' => "Cliente Importado {$sequence}",
        'telefono' => sprintf('0414-%07d', 2000000 + $sequence),
        ...$overrides,
    ];
}

function importAdvisor(string $email = 'asesora@ecolekua.test'): User
{
    $advisor = userWithPermissions(PermissionName::CustomersPortfolio);
    $advisor->update(['email' => $email]);

    return $advisor;
}

/**
 * Rows of the console table whose header is `$header`, as lists of trimmed cells.
 *
 * @param  list<string>  $header
 * @return list<list<string>>|null `null` when the output has no such table
 */
function importTable(string $output, array $header): ?array
{
    $tables = [];
    $current = null;

    foreach (explode("\n", $output) as $line) {
        $line = rtrim($line);

        if (str_starts_with($line, '|')) {
            $current[] = array_map(trim(...), explode('|', trim($line, '|')));
        } elseif (! str_starts_with($line, '+') && $current !== null) {
            $tables[] = $current;
            $current = null;
        }
    }

    if ($current !== null) {
        $tables[] = $current;
    }

    foreach ($tables as $table) {
        if ($table[0] === $header) {
            return array_slice($table, 1);
        }
    }

    return null;
}

it('E-30 reports row, column and reason for a missing phone and an already registered document, without cell values', function () {
    $author = importAuthor();
    $existing = Customer::factory()->withDocument(DocumentType::CedulaV)->create();
    $typedDigits = substr($existing->document_number, 1);
    $csv = importCsv([
        importRow(['nombre' => 'Valida-'.IMPORT_SENTINEL, 'observaciones' => 'Obs-'.IMPORT_SENTINEL]),
        importRow(['nombre' => 'SinTelefono-'.IMPORT_SENTINEL, 'telefono' => '', 'correo' => 'c-'.IMPORT_SENTINEL.'@ficticio.test']),
        importRow(['nombre' => 'Repetida-'.IMPORT_SENTINEL, 'tipo_documento' => 'cedula_v', 'numero_documento' => $typedDigits]),
    ]);

    [$exit, $output] = runImport(importFile($csv), $author->email);

    expect($exit)->toBe(1)
        ->and(importTable($output, IMPORT_REPORT_HEADER))->toBe([
            ['3', 'telefono', 'El campo teléfono es obligatorio.'],
            ['4', 'numero_documento', 'Ya existe un cliente con ese documento.'],
        ])
        ->and($output)->not->toContain(IMPORT_SENTINEL)
        ->and($output)->not->toContain($typedDigits)
        ->and(Customer::query()->count())->toBe(1)
        ->and(AuditLog::query()->where('action', AuditAction::CustomerCreated->value)->count())->toBe(0);
});

it('E-30 reports a document repeated between rows with the row of its first occurrence', function () {
    $author = importAuthor();
    $csv = importCsv([
        importRow(['tipo_documento' => 'cedula_v', 'numero_documento' => 'V-12.345.678']),
        importRow(),
        importRow(['tipo_documento' => 'cedula_v', 'numero_documento' => '12345678']),
        importRow(['tipo' => 'empresa', 'tipo_documento' => 'rif_j', 'numero_documento' => 'J-12345678-4']),
        importRow(['tipo' => 'empresa', 'tipo_documento' => 'rif_j', 'numero_documento' => 'j123456784']),
    ]);

    [$exit, $output] = runImport(importFile($csv), $author->email);

    expect($exit)->toBe(1)
        ->and(importTable($output, IMPORT_REPORT_HEADER))->toBe([
            ['4', 'numero_documento', 'Documento repetido en la fila 2.'],
            ['6', 'numero_documento', 'Documento repetido en la fila 5.'],
        ])
        ->and(Customer::query()->count())->toBe(0);
});

it('E-30 treats the same number under another document type as a different document', function () {
    $author = importAuthor();
    $csv = importCsv([
        importRow(['tipo_documento' => 'cedula_v', 'numero_documento' => '12345678']),
        importRow(['tipo_documento' => 'cedula_e', 'numero_documento' => '12345678']),
    ]);

    [$exit] = runImport(importFile($csv), $author->email);

    expect($exit)->toBe(0)
        ->and(Customer::query()->count())->toBe(2);
});

it('E-30 reports an unknown state as a row error and accepts known states ignoring case and accents', function () {
    $author = importAuthor();
    $address = ['direccion' => 'Calle Ficticia 1', 'direccion_ciudad' => 'Ciudad Nueva'];
    $csv = importCsv([
        importRow([...$address, 'direccion_estado' => 'TÁCHIRA']),
        importRow([...$address, 'direccion_estado' => 'Narnia']),
        importRow([...$address, 'direccion_estado' => ' mérida ']),
    ]);

    [$exit, $output] = runImport(importFile($csv), $author->email);

    expect($exit)->toBe(1)
        ->and(importTable($output, IMPORT_REPORT_HEADER))->toBe([
            ['3', 'direccion_estado', 'El valor seleccionado en estado no es válido.'],
        ])
        ->and($output)->not->toContain('Narnia')
        ->and(Customer::query()->count())->toBe(0);
});

it('E-30 maps each validation error back to its CSV column', function (array $overrides, array $columns) {
    $author = importAuthor();

    [$exit, $output] = runImport(importFile(importCsv([importRow($overrides)])), $author->email);

    $table = importTable($output, IMPORT_REPORT_HEADER);

    expect($exit)->toBe(1)
        ->and($table)->not->toBeNull()
        ->and(array_unique(array_column($table, 0)))->toBe(['2'])
        ->and(array_column($table, 1))->toEqualCanonicalizing($columns)
        ->and(array_filter(array_column($table, 2), fn (string $reason): bool => $reason === ''))->toBe([])
        ->and(Customer::query()->count())->toBe(0);
})->with([
    'tipo desconocido' => [['tipo' => 'pyme'], ['tipo']],
    'nombre vacío' => [['nombre' => ''], ['nombre']],
    'nombre demasiado largo' => [['nombre' => str_repeat('a', 201)], ['nombre']],
    'tipo de documento desconocido' => [['tipo_documento' => 'dni'], ['tipo_documento', 'numero_documento']],
    'documento sin número' => [['tipo_documento' => 'cedula_v'], ['numero_documento']],
    'cédula con formato inválido' => [['tipo_documento' => 'cedula_v', 'numero_documento' => '12'], ['numero_documento']],
    'RIF J para persona natural' => [['tipo_documento' => 'rif_j', 'numero_documento' => 'J123456784'], ['tipo_documento']],
    'teléfono fijo' => [['telefono' => '0212-5551234'], ['telefono']],
    'teléfono extranjero' => [['telefono' => '+1 305 555 1234'], ['telefono']],
    'correo inválido' => [['correo' => 'no-es-un-correo'], ['correo']],
    'cumpleaños 31 de abril' => [['cumpleanos_dia' => '31', 'cumpleanos_mes' => '4'], ['cumpleanos_dia']],
    'cumpleaños sin mes' => [['cumpleanos_dia' => '5'], ['cumpleanos_mes']],
    'cumpleaños no numérico' => [['cumpleanos_dia' => 'x', 'cumpleanos_mes' => '4'], ['cumpleanos_dia']],
    'aniversario de persona natural' => [['aniversario_dia' => '1', 'aniversario_mes' => '1'], ['aniversario_dia', 'aniversario_mes']],
    'contacto de persona natural' => [['contacto_nombre' => 'Ana', 'contacto_telefono' => '0414-1112233'], ['contacto_nombre']],
    'contacto sin nombre ni teléfono' => [['tipo' => 'empresa', 'contacto_cargo' => 'Compras'], ['contacto_nombre', 'contacto_telefono']],
    'contacto con teléfono extranjero' => [['tipo' => 'empresa', 'contacto_nombre' => 'Ana', 'contacto_telefono' => '+1 305 555 1234'], ['contacto_telefono']],
    'dirección sin ciudad ni estado' => [['direccion' => 'Calle Ficticia 1'], ['direccion_ciudad', 'direccion_estado']],
    'estado desconocido' => [['direccion' => 'Calle 1', 'direccion_ciudad' => 'Ciudad', 'direccion_estado' => 'Narnia'], ['direccion_estado']],
]);

it('E-30 never prints cell values, whatever the error', function () {
    $author = importAuthor();
    $tag = IMPORT_SENTINEL;
    $csv = importCsv([
        importRow([
            'nombre' => "Nombre-{$tag}", 'tipo_documento' => 'cedula_v', 'numero_documento' => "n{$tag}",
            'correo' => "correo-{$tag}", 'observaciones' => "Obs-{$tag}", 'cumpleanos_dia' => "d{$tag}", 'cumpleanos_mes' => '4',
            'direccion' => "Linea-{$tag}", 'direccion_ciudad' => "Ciudad-{$tag}", 'direccion_estado' => "Estado-{$tag}",
            'correo_asesora' => "asesora-{$tag}@ficticia.test",
        ]),
        importRow(['tipo' => "tipo-{$tag}", 'telefono' => "tel-{$tag}", 'contacto_nombre' => "Contacto-{$tag}"]),
    ]);

    [$exit, $output] = runImport(importFile($csv), $author->email);

    expect($exit)->toBe(1)
        ->and(importTable($output, IMPORT_REPORT_HEADER))->not->toBeEmpty()
        ->and($output)->not->toContain($tag);
});

it('E-32 rejects an advisor that is inactive, lacks customers.portfolio or does not exist', function (string $case) {
    $author = importAuthor();

    match ($case) {
        'inactive' => importAdvisor('baja@ecolekua.test')->update(['is_active' => false]),
        'without portfolio' => userWithPermissions(PermissionName::CustomersView)->update(['email' => 'baja@ecolekua.test']),
        'unknown' => null,
    };

    $csv = importCsv([importRow(), importRow(['correo_asesora' => 'baja@ecolekua.test'])]);

    [$exit, $output] = runImport(importFile($csv), $author->email);

    expect($exit)->toBe(1)
        ->and(importTable($output, IMPORT_REPORT_HEADER))->toBe([['3', 'correo_asesora', IMPORT_ADVISOR_REASON]])
        ->and(Customer::query()->count())->toBe(0);
})->with(['inactive', 'without portfolio', 'unknown']);

it('E-32 rejects an author that does not exist, is inactive or is missing, before reading the file', function (?string $email, string $reason) {
    User::factory()->create(['email' => 'inactivo@ecolekua.test', 'is_active' => false]);
    $notUtf8 = mb_convert_encoding(importCsv([importRow(['nombre' => 'Muñoz'])]), 'ISO-8859-1', 'UTF-8');

    [$exit, $output] = runImport(importFile($notUtf8), $email);

    expect($exit)->toBe(1)
        ->and($output)->toContain($reason)
        ->and($output)->not->toContain('UTF-8')
        ->and(Customer::query()->count())->toBe(0);
})->with([
    'unknown author' => ['nadie@ecolekua.test', IMPORT_AUTHOR_REASON],
    'inactive author' => ['inactivo@ecolekua.test', IMPORT_AUTHOR_REASON],
    'author option missing' => [null, 'Debe indicar el autor con --author=correo.'],
]);

it('E-32 accepts the author email in any case and with surrounding spaces', function () {
    importAuthor();

    [$exit] = runImport(importFile(importCsv([importRow()])), '  AUTOR@Ecolekua.TEST ');

    expect($exit)->toBe(0)
        ->and(Customer::query()->count())->toBe(1);
});

it('E-31 warns about phones registered or repeated in the file, creating nothing without the confirmation option', function () {
    $author = importAuthor();
    $registered = Customer::factory()->create(['name' => 'Existente Uno', 'phone' => '+584141000001']);
    $csv = importCsv([
        importRow(['nombre' => 'Nuevo-'.IMPORT_SENTINEL, 'telefono' => '0414-1000001']),
        importRow(['telefono' => '0414-3000002']),
        importRow(['telefono' => '+58 414 300 00 02']),
    ]);

    [$exit, $output] = runImport(importFile($csv), $author->email);

    expect($exit)->toBe(1)
        ->and(importTable($output, IMPORT_REPORT_HEADER))->toBeNull()
        ->and(importTable($output, IMPORT_WARNING_HEADER))->toBe([
            ['2', "El teléfono ya está registrado en el cliente #{$registered->id} (Existente Uno)."],
            ['3', 'El teléfono se repite en la fila 4.'],
            ['4', 'El teléfono se repite en la fila 3.'],
        ])
        ->and($output)->toContain('--confirm-duplicate-phones')
        ->and($output)->not->toContain(IMPORT_SENTINEL)
        ->and(Customer::query()->count())->toBe(1);
});

it('E-31 imports every row when the command is repeated with the confirmation option', function () {
    $author = importAuthor();
    Customer::factory()->create(['phone' => '+584141000001']);
    $csv = importFile(importCsv([
        importRow(['telefono' => '0414-1000001']),
        importRow(['telefono' => '0414-3000002']),
        importRow(['telefono' => '+58 414 300 00 02']),
    ]));

    [$exit, $output] = runImport($csv, $author->email, ['--confirm-duplicate-phones' => true]);

    expect($exit)->toBe(0)
        ->and($output)->toContain('Se importaron 3 clientes.')
        ->and(Customer::query()->count())->toBe(4)
        ->and(Customer::query()->where('created_by', $author->id)->pluck('phone')->sort()->values()->all())
        ->toBe(['+584141000001', '+584143000002', '+584143000002']);
});

it('E-31 also matches an inactive registered customer and lists every other row sharing a phone', function () {
    $author = importAuthor();
    $inactive = Customer::factory()->inactive()->create(['name' => 'Inactivo Dos', 'phone' => '+584141000009']);
    $csv = importCsv([
        importRow(['telefono' => '0414-4000003']),
        importRow(['telefono' => '0414-1000009']),
        importRow(['telefono' => '0414-4000003']),
        importRow(['telefono' => '0414-4000003']),
    ]);

    [$exit, $output] = runImport(importFile($csv), $author->email);

    expect($exit)->toBe(1)
        ->and(importTable($output, IMPORT_WARNING_HEADER))->toBe([
            ['2', 'El teléfono se repite en las filas 4, 5.'],
            ['3', "El teléfono ya está registrado en el cliente #{$inactive->id} (Inactivo Dos)."],
            ['4', 'El teléfono se repite en las filas 2, 5.'],
            ['5', 'El teléfono se repite en las filas 2, 4.'],
        ]);
});

it('E-31 does not let the confirmation option import a file that has row errors', function () {
    $author = importAuthor();
    $csv = importCsv([
        importRow(['telefono' => '0414-5000001']),
        importRow(['telefono' => '0414-5000001']),
        importRow(['nombre' => '']),
    ]);

    [$exit, $output] = runImport(importFile($csv), $author->email, ['--confirm-duplicate-phones' => true]);

    expect($exit)->toBe(1)
        ->and(importTable($output, IMPORT_REPORT_HEADER))->toBe([['4', 'nombre', 'El campo nombre es obligatorio.']])
        ->and(importTable($output, IMPORT_WARNING_HEADER))->toHaveCount(2)
        ->and(Customer::query()->count())->toBe(0);
});

it('E-29 imports every valid row as an active customer created by the author, with the advisor of the row only', function () {
    $author = userWithPermissions(PermissionName::CustomersPortfolio);
    $author->update(['email' => 'autor@ecolekua.test']);
    $advisor = importAdvisor('asesora@ecolekua.test');
    $other = importAdvisor('otra@ecolekua.test');

    $csv = importCsv([
        importRow([
            'tipo' => 'EMPRESA', 'nombre' => 'Textiles Ficticios C.A.', 'tipo_documento' => 'rif_j', 'numero_documento' => 'j-12345678-4',
            'telefono' => '0414-1234567', 'correo' => 'compras@ficticia.test', 'aniversario_dia' => '15', 'aniversario_mes' => '3',
            'observaciones' => 'Cliente de prueba',
            'contacto_nombre' => 'Rosa Ficticia', 'contacto_cargo' => 'Compras', 'contacto_telefono' => '0212-555-1234', 'contacto_correo' => 'rosa@ficticia.test',
            'direccion' => 'Av. Inventada 1', 'direccion_ciudad' => 'Ciudad Nueva', 'direccion_estado' => '  táchira ', 'direccion_referencia' => 'Frente a la plaza',
            'correo_asesora' => ' ASESORA@ecolekua.test ',
        ]),
        importRow([
            'nombre' => 'Persona Ficticia', 'tipo_documento' => 'cedula_v', 'numero_documento' => '12.345.678', 'telefono' => '+58 414 765 43 21',
            'cumpleanos_dia' => '29', 'cumpleanos_mes' => '2',
        ]),
        importRow(['nombre' => 'Otra Persona', 'telefono' => '0416-5551234', 'correo_asesora' => 'otra@ecolekua.test']),
    ]);

    [$exit, $output] = runImport(importFile($csv), 'autor@ecolekua.test');

    expect($exit)->toBe(0)
        ->and($output)->toContain('Se importaron 3 clientes.')
        ->and(Customer::query()->count())->toBe(3);

    $company = Customer::query()->where('phone', '+584141234567')->with(['contact', 'address'])->sole();
    $person = Customer::query()->where('phone', '+584147654321')->sole();
    $unassigned = Customer::query()->where('phone', '+584165551234')->sole();

    expect($company->type)->toBe(CustomerType::Company)
        ->and($company->status)->toBe(CustomerStatus::Active)
        ->and($company->created_by)->toBe($author->id)
        ->and($company->document_number)->toBe('J123456784')
        ->and($company->advisor_id)->toBe($advisor->id)
        ->and($company->contact->phone)->toBe('+582125551234')
        ->and($company->address->state)->toBe(VenezuelanState::Tachira)
        ->and($person->status)->toBe(CustomerStatus::Active)
        ->and($person->created_by)->toBe($author->id)
        ->and($person->document_number)->toBe('V12345678')
        ->and($person->advisor_id)->toBeNull()
        ->and($unassigned->advisor_id)->toBe($other->id);

    $audits = AuditLog::query()->where('action', AuditAction::CustomerCreated->value)->orderBy('id')->get();

    expect($audits)->toHaveCount(3);

    foreach ($audits as $audit) {
        expect($audit->actor_id)->toBe($author->id)
            ->and($audit->actor_email)->toBe('autor@ecolekua.test')
            ->and($audit->ip_address)->toBeNull()
            ->and($audit->entity_type)->toBe((new Customer)->getMorphClass())
            ->and($audit->old_values)->toBe([])
            ->and($audit->context['import'])->toBeTrue()
            ->and($audit->context['source'])->toBe('console')
            ->and($audit->context['command'])->toBe('customers:import')
            ->and($audit->context)->toHaveKeys(['os_user', 'host', 'import_row']);
    }

    expect($audits->pluck('entity_id')->all())->toBe([$company->id, $person->id, $unassigned->id])
        ->and($audits->map(fn (AuditLog $audit): int => $audit->context['import_row'])->all())->toBe([2, 3, 4])
        ->and($audits[0]->new_values)->toEqual([
            'type' => 'company',
            'name' => 'Textiles Ficticios C.A.',
            'document_type' => 'rif_j',
            'document_number' => 'J123456784',
            'phone' => '+584141234567',
            'email' => 'compras@ficticia.test',
            'birthday_day' => null,
            'birthday_month' => null,
            'anniversary_day' => 15,
            'anniversary_month' => 3,
            'notes' => 'Cliente de prueba',
            'status' => 'active',
            'advisor_id' => $advisor->id,
            'advisor_name' => $advisor->fullName(),
            'contact' => ['name' => 'Rosa Ficticia', 'position' => 'Compras', 'phone' => '+582125551234', 'email' => 'rosa@ficticia.test'],
            'address' => ['line' => 'Av. Inventada 1', 'city' => 'Ciudad Nueva', 'state' => 'tachira', 'reference' => 'Frente a la plaza'],
        ])
        ->and($audits[1]->new_values['advisor_id'])->toBeNull()
        ->and($audits[1]->new_values['advisor_name'])->toBeNull()
        ->and($audits[1]->new_values['birthday_day'])->toBe(29)
        ->and($audits[1]->new_values['birthday_month'])->toBe(2)
        ->and($audits[2]->new_values['advisor_id'])->toBe($other->id);
});

it('E-29 never assigns the author as advisor, even when the author holds customers.portfolio', function () {
    $author = userWithPermissions(PermissionName::CustomersPortfolio);
    $author->update(['email' => 'autor@ecolekua.test']);

    [$exit] = runImport(importFile(importCsv([importRow(), importRow()])), 'autor@ecolekua.test');

    expect($exit)->toBe(0)
        ->and($author->isEligibleAdvisor())->toBeTrue()
        ->and(Customer::query()->count())->toBe(2)
        ->and(Customer::query()->whereNotNull('advisor_id')->count())->toBe(0);
});

it('E-29 imports a UTF-8 file with a byte order mark and the semicolon delimiter, numbering rows by physical line', function () {
    $author = importAuthor();
    $csv = "\xEF\xBB\xBF".importCsv([
        importRow(['nombre' => 'Muñoz, Ana', 'telefono' => '0414-6000001', 'observaciones' => "línea uno\nlínea dos"]),
        importRow(['nombre' => 'Segunda', 'telefono' => '0414-6000002']),
    ], ';');

    [$exit] = runImport(importFile($csv), $author->email);

    $first = Customer::query()->where('phone', '+584146000001')->sole();

    expect($exit)->toBe(0)
        ->and($first->name)->toBe('Muñoz, Ana')
        ->and($first->notes)->toBe("línea uno\nlínea dos")
        ->and(AuditLog::query()->where('action', AuditAction::CustomerCreated->value)->orderBy('id')->get()
            ->map(fn (AuditLog $audit): int => $audit->context['import_row'])->all())->toBe([2, 4]);
});

it('E-29 rolls everything back when a row fails in the middle of the run, without logging cell values', function () {
    Log::spy();
    $author = importAuthor();
    Customer::creating(function () {
        static $created = 0;

        if (++$created === 2) {
            throw new RuntimeException('detalle interno '.IMPORT_SENTINEL);
        }
    });
    $csv = importCsv([importRow(), importRow(['nombre' => 'Falla-'.IMPORT_SENTINEL]), importRow()]);

    [$exit, $output] = runImport(importFile($csv), $author->email);

    expect($exit)->toBe(1)
        ->and($output)->toContain('No se guardó ningún cliente')
        ->and($output)->toContain('RuntimeException')
        ->and($output)->not->toContain(IMPORT_SENTINEL)
        ->and(Customer::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('action', AuditAction::CustomerCreated->value)->count())->toBe(0);

    Log::shouldHaveReceived('error')->withArgs(
        fn (string $message, array $context = []): bool => ! str_contains(json_encode([$message, $context]) ?: '', IMPORT_SENTINEL),
    )->once();
});

it('CLI-018 creates nothing when the advisor stops being eligible between validation and creation', function () {
    $author = importAuthor();
    $advisor = importAdvisor();
    $advisor->update(['is_active' => false]);

    $action = app(CreateCustomer::class);

    expect(fn () => $action->handle(importRowInput(), $author, true, AuditOrigin::console('customers:import'), false, $advisor))
        ->toThrow(ValidationException::class);

    try {
        $action->handle(importRowInput(), $author, true, AuditOrigin::console('customers:import'), false, $advisor);
    } catch (ValidationException $exception) {
        expect($exception->errors())->toBe(['advisor_id' => [IMPORT_ADVISOR_REASON]]);
    }

    expect(Customer::query()->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe(0);
});

it('R3-stale-advisor-recheck CreateCustomer re-reads the advisor from the database, not the instance it was given', function () {
    $author = importAuthor();
    $advisor = importAdvisor();
    $stale = User::query()->findOrFail($advisor->id);

    User::query()->whereKey($advisor->id)->update(['is_active' => false]);

    expect($stale->is_active)->toBeTrue();

    try {
        app(CreateCustomer::class)->handle(importRowInput(), $author, true, AuditOrigin::console('customers:import'), false, $stale);
        $errors = [];
    } catch (ValidationException $exception) {
        $errors = $exception->errors();
    }

    expect($errors)->toBe(['advisor_id' => [IMPORT_ADVISOR_REASON]])
        ->and(Customer::query()->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe(0);
});

it('R3-stale-advisor-recheck CreateCustomer rejects an advisor whose row no longer exists', function () {
    $author = importAuthor();
    $advisor = importAdvisor();
    $stale = User::query()->findOrFail($advisor->id);

    DB::table('role_user')->where('user_id', $advisor->id)->delete();
    DB::table('users')->where('id', $advisor->id)->delete();

    try {
        app(CreateCustomer::class)->handle(importRowInput(), $author, true, null, false, $stale);
        $errors = [];
    } catch (ValidationException $exception) {
        $errors = $exception->errors();
    }

    expect($errors)->toBe(['advisor_id' => [IMPORT_ADVISOR_REASON]])
        ->and(Customer::query()->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe(0);
});

it('R3-phase2-error-loses-row E-30 reports row and column of a document taken by another process during creation, rolling everything back', function () {
    $author = importAuthor();
    $tag = IMPORT_SENTINEL;
    Customer::creating(function (Customer $customer) use ($tag) {
        if ($customer->name === "Conflicto-{$tag}") {
            Customer::factory()->create(['document_type' => 'cedula_v', 'document_number' => 'V12345678', 'name' => 'Otro proceso']);
        }
    });
    $csv = importCsv([
        importRow(['nombre' => "Primera-{$tag}"]),
        importRow(['nombre' => "Conflicto-{$tag}", 'tipo_documento' => 'cedula_v', 'numero_documento' => '12345678', 'observaciones' => "Obs-{$tag}"]),
        importRow(['nombre' => "Tercera-{$tag}"]),
    ]);

    [$exit, $output] = runImport(importFile($csv), $author->email);

    expect($exit)->toBe(1)
        ->and(importTable($output, IMPORT_REPORT_HEADER))->toBe([['3', 'numero_documento', 'Ya existe un cliente con ese documento.']])
        ->and($output)->not->toContain($tag)
        ->and($output)->not->toContain('12345678')
        ->and($output)->not->toContain('ValidationException')
        ->and(Customer::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('action', AuditAction::CustomerCreated->value)->count())->toBe(0);
});

it('R3-phase2-error-loses-row E-32 reports the advisor column of a row whose advisor was deactivated during creation, rolling everything back', function () {
    $author = importAuthor();
    $advisor = importAdvisor('asesora@ecolekua.test');
    $tag = IMPORT_SENTINEL;
    Customer::creating(function (Customer $customer) use ($advisor, $tag) {
        if ($customer->name === "Primera-{$tag}") {
            User::query()->whereKey($advisor->id)->update(['is_active' => false]);
        }
    });
    $csv = importCsv([
        importRow(['nombre' => "Primera-{$tag}"]),
        importRow(['nombre' => "Segunda-{$tag}", 'correo_asesora' => 'asesora@ecolekua.test']),
    ]);

    [$exit, $output] = runImport(importFile($csv), $author->email);

    expect($exit)->toBe(1)
        ->and(importTable($output, IMPORT_REPORT_HEADER))->toBe([['3', 'correo_asesora', IMPORT_ADVISOR_REASON]])
        ->and($output)->not->toContain($tag)
        ->and($output)->not->toContain('asesora@ecolekua.test')
        ->and(Customer::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('action', AuditAction::CustomerCreated->value)->count())->toBe(0);
});

it('CLI-018 CreateCustomer assigns only the given advisor when auto assignment is off, even for an eligible creator', function () {
    $creator = userWithPermissions(PermissionName::CustomersPortfolio);
    $advisor = importAdvisor();

    $withAdvisor = app(CreateCustomer::class)->handle(importRowInput(), $creator, true, AuditOrigin::console('customers:import'), false, $advisor);
    $without = app(CreateCustomer::class)->handle(importRowInput(['phone' => '0414-7000002']), $creator, true, AuditOrigin::console('customers:import'), false);

    expect($withAdvisor->advisor_id)->toBe($advisor->id)
        ->and($without->advisor_id)->toBeNull();
});

it('CLI-018 CreateCustomer keeps the creator as advisor by default and ignores a given advisor then', function () {
    $creator = userWithPermissions(PermissionName::CustomersPortfolio);
    $advisor = importAdvisor();

    $customer = app(CreateCustomer::class)->handle(importRowInput(), $creator, true);

    expect($customer->advisor_id)->toBe($creator->id);

    $ignored = app(CreateCustomer::class)->handle(importRowInput(['phone' => '0414-7000002']), $creator, true, null, true, $advisor);

    expect($ignored->advisor_id)->toBe($creator->id);
});

it('CLI-018 CreateCustomer merges the audit context with the console origin', function () {
    $author = importAuthor();

    $customer = app(CreateCustomer::class)->handle(importRowInput(), $author, true, AuditOrigin::console('customers:import'), false, null, ['import' => true, 'import_row' => 9]);

    $audit = AuditLog::query()->where('entity_id', $customer->id)->sole();

    expect($audit->ip_address)->toBeNull()
        ->and($audit->context['import'])->toBeTrue()
        ->and($audit->context['import_row'])->toBe(9)
        ->and($audit->context['source'])->toBe('console');
});

/**
 * Minimal valid input for `CreateCustomer::handle()`.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function importRowInput(array $overrides = []): array
{
    return ['type' => 'natural', 'name' => 'Cliente Directo', 'phone' => '0414-7000001', ...$overrides];
}
