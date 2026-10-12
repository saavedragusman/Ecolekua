<?php

use App\Support\Csv\CsvReader;
use App\Support\Csv\InvalidCsv;

/*
 * Generic CSV reader extracted from the 002 customer reader (design Decision 19). Every file is
 * fictitious and written to a temporary directory that is removed after each test.
 */

beforeEach(function () {
    $this->csvDirectory = sys_get_temp_dir().'/csv-reader-'.bin2hex(random_bytes(6));
    mkdir($this->csvDirectory);
});

afterEach(function () {
    foreach (glob($this->csvDirectory.'/*') ?: [] as $file) {
        unlink($file);
    }

    rmdir($this->csvDirectory);
});

function csvFile(string $content): string
{
    $path = test()->csvDirectory.'/archivo.csv';
    file_put_contents($path, $content);

    return $path;
}

/**
 * The reasons of the InvalidCsv thrown when reading, or an empty list when the file reads.
 *
 * @param  list<string>  $columns
 * @return list<string>
 */
function csvProblems(string $content, array $columns = ['uno', 'dos'], bool $allowEmpty = false): array
{
    try {
        (new CsvReader)->read(csvFile($content), $columns, $allowEmpty);
    } catch (InvalidCsv $invalid) {
        return $invalid->problems;
    }

    return [];
}

it('Decision 19 reads the columns given as a parameter, not a fixed list', function () {
    $reader = new CsvReader;

    $first = $reader->read(csvFile("uno,dos\nA,B\n"), ['uno', 'dos']);
    $second = $reader->read(csvFile("color,tono,telas\nRojo,#FF0000,Algodón\n"), ['color', 'tono', 'telas']);

    expect($first)->toBe([['line' => 2, 'cells' => ['uno' => 'A', 'dos' => 'B']]])
        ->and($second)->toBe([['line' => 2, 'cells' => ['color' => 'Rojo', 'tono' => '#FF0000', 'telas' => 'Algodón']]]);
});

it('Decision 19 drops the UTF-8 byte order mark and keeps accents', function () {
    $rows = (new CsvReader)->read(csvFile("\xEF\xBB\xBFuno,dos\nMuñoz,B\n"), ['uno', 'dos']);

    expect($rows[0]['cells'])->toBe(['uno' => 'Muñoz', 'dos' => 'B']);
});

it('Decision 19 rejects text that is not UTF-8 and an empty file', function () {
    $latin1 = mb_convert_encoding("uno,dos\nMuñoz,B\n", 'ISO-8859-1', 'UTF-8');

    expect(csvProblems($latin1))->toBe(['El archivo no está codificado en UTF-8.'])
        ->and(csvProblems("  \n"))->toBe(['El archivo está vacío.']);
});

it('Decision 19 reports a path that cannot be read', function () {
    $problems = [];

    try {
        (new CsvReader)->read($this->csvDirectory.'/no-existe.csv', ['uno']);
    } catch (InvalidCsv $invalid) {
        $problems = $invalid->problems;
    }

    expect($problems)->toBe(['No se pudo leer el archivo: la ruta no existe o no se puede leer.']);
});

it('Decision 19 detects a comma or a semicolon from the header', function () {
    $comma = (new CsvReader)->read(csvFile("uno,dos\nA,B\n"), ['uno', 'dos']);
    $semicolon = (new CsvReader)->read(csvFile("uno;dos\nA,x;B\n"), ['uno', 'dos']);

    expect($comma[0]['cells'])->toBe(['uno' => 'A', 'dos' => 'B'])
        ->and($semicolon[0]['cells'])->toBe(['uno' => 'A,x', 'dos' => 'B']);
});

it('Decision 19 accepts the header in any order and case and returns the cells by name', function () {
    $rows = (new CsvReader)->read(csvFile("DOS, Uno\nB,A\n"), ['uno', 'dos']);

    expect($rows[0]['cells'])->toBe(['dos' => 'B', 'uno' => 'A']);
});

it('Decision 19 lists the missing, unknown and repeated header columns', function () {
    expect(csvProblems("uno\nA\n"))->toBe(['Faltan las columnas: dos.'])
        ->and(csvProblems("uno,dos,tres\nA,B,C\n"))->toBe(['Columnas no reconocidas: tres.'])
        ->and(csvProblems("uno,dos,dos\nA,B,C\n"))->toBe(['Columnas repetidas: dos.']);
});

it('Decision 19 keeps multiline quoted cells and numbers rows by physical line', function () {
    $rows = (new CsvReader)->read(csvFile("uno,dos\n\"A\nB\",C\n\nD,E\n"), ['uno', 'dos']);

    expect($rows)->toHaveCount(2)
        ->and($rows[0])->toBe(['line' => 2, 'cells' => ['uno' => "A\nB", 'dos' => 'C']])
        ->and($rows[1]['line'])->toBe(5);
});

it('Decision 19 rejects rows with a different number of columns', function () {
    expect(csvProblems("uno,dos\nA,B\nC\nD,E,F\n"))
        ->toBe(['Filas con distinto número de columnas que el encabezado: 3, 4.']);
});

it('Decision 19 rejects a file with only a header unless allowEmpty is set', function () {
    $reader = new CsvReader;

    expect(csvProblems("uno,dos\n"))->toBe(['El archivo no contiene filas para importar.'])
        ->and(csvProblems("uno,dos\n", allowEmpty: true))->toBe([])
        ->and($reader->read(csvFile("uno,dos\n\n"), ['uno', 'dos'], allowEmpty: true))->toBe([])
        ->and($reader->read(csvFile("uno,dos\nA,B\n"), ['uno', 'dos'], allowEmpty: true))->toHaveCount(1);
});

it('Decision 19 still validates the header of an allowEmpty file', function () {
    expect(csvProblems("uno\n", allowEmpty: true))->toBe(['Faltan las columnas: dos.']);
});
