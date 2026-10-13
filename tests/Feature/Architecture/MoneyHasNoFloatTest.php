<?php

use Symfony\Component\Finder\Finder;

// This directory is not part of the `cot` group of tests/Pest.php, so the file joins it explicitly.
pest()->group('cot');

// DT-01 (design Decision 3, spec §12): no amount uses `float`. Token scan, so comments and docblocks
// that mention the word are ignored but a type, a cast, a conversion call or a cast string is caught.

/**
 * Float usages found in PHP source, ignoring comments.
 *
 * @return list<string>
 */
function floatOffendersInPhp(string $source): array
{
    $offenders = [];
    $names = ['float', 'double', 'floatval', 'doubleval', 'tofloat', 'unsignedfloat', 'unsigneddouble'];

    foreach (token_get_all($source) as $token) {
        if (! is_array($token)) {
            continue;
        }

        [$id, $text] = $token;

        if ($id === T_DOUBLE_CAST) {
            $offenders[] = $text;
        } elseif ($id === T_STRING && in_array(strtolower($text), $names, true)) {
            $offenders[] = $text;
        } elseif ($id === T_CONSTANT_ENCAPSED_STRING && in_array(strtolower(trim($text, '\'"')), ['float', 'double', 'real'], true)) {
            $offenders[] = $text;
        } elseif ($id === T_CONSTANT_ENCAPSED_STRING && preg_match('/\b(?:float|double|real)\b/i', $text) === 1 && preg_match('/\bCREATE|ALTER\b/i', $text) === 1) {
            // Raw DDL in a migration declaring a floating point column.
            $offenders[] = $text;
        }
    }

    return $offenders;
}

/**
 * Files of the money code that exist today; a directory that a later phase creates is skipped.
 *
 * @return list<string>
 */
function moneyCodeFiles(): array
{
    $directories = array_filter([
        app_path('Support/Pricing'),
        app_path('Support/Quotations'),
        app_path('Actions/Pricing'),
        app_path('Actions/Quotations'),
    ], 'is_dir');

    $files = [app_path('Casts/AsBigDecimal.php')];

    foreach ($directories as $directory) {
        foreach (Finder::create()->files()->in($directory)->name('*.php') as $file) {
            $files[] = $file->getRealPath();
        }
    }

    return $files;
}

it('DT-01 detects a float type, cast, conversion call and cast string in the scanner itself', function (string $code) {
    expect(floatOffendersInPhp('<?php '.$code))->not->toBeEmpty();
})->with([
    'parameter type' => ['function a(float $x) {}'],
    'return type' => ['function a(): ?float { return null; }'],
    'cast' => ['$a = (float) $x;'],
    'double cast' => ['$a = (double) $x;'],
    'floatval' => ['$a = floatval($x);'],
    'toFloat' => ['$a = $big->toFloat();'],
    'cast string' => ["return ['price' => 'float'];"],
    'double cast string' => ['return ["price" => "double"];'],
    'migration column' => ['$table->float("price");'],
    'raw ddl column' => ["DB::statement('ALTER TABLE t ADD price DOUBLE');"],
]);

it('DT-01 ignores comments and decimal casts in the scanner itself', function () {
    $code = "<?php\n// no float here\n/** A float is wrong. */\n\$a = ['unit_price' => 'decimal:2', 'x' => AsBigDecimal::class.':2'];";

    expect(floatOffendersInPhp($code))->toBe([]);
});

it('DT-01 finds no float in the money code', function () {
    $files = moneyCodeFiles();
    $offenders = [];

    foreach ($files as $path) {
        $found = floatOffendersInPhp(file_get_contents($path));

        if ($found !== []) {
            $offenders[basename($path)] = $found;
        }
    }

    // Money and the cast always exist, so the scan cannot pass by scanning nothing.
    expect(array_map('basename', $files))->toContain('Money.php', 'AsBigDecimal.php')
        ->and($offenders)->toBe([]);
});
