<?php

use Symfony\Component\Finder\Finder;

// PRD-011, PRD-019, DT-02: selecting and listing options read the catalog only. The selection
// engine and its two Actions import nothing from pricing or stock and query no price or stock table.

/**
 * Files of the selection engine: the support namespace and the two Actions over it.
 *
 * @return list<string> absolute paths
 */
function selectionFiles(): array
{
    $files = [app_path('Actions/Products/ResolveSelection.php'), app_path('Actions/Products/ListSelectionOptions.php')];

    foreach (Finder::create()->files()->in(app_path('Support/Products/Selection'))->name('*.php') as $file) {
        $files[] = $file->getPathname();
    }

    return $files;
}

/**
 * What a file makes use of, comments left out: the imports and the string literals (table and
 * column names) that mention pricing or stock.
 *
 * @return list<string>
 */
function selectionPriceOrStockUses(string $path): array
{
    $uses = [];
    $importing = false;

    foreach (PhpToken::tokenize((string) file_get_contents($path)) as $token) {
        if ($token->is(T_USE)) {
            $importing = true;
        } elseif ($token->is(';')) {
            $importing = false;
        } elseif ($importing && $token->is([T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED]) && preg_match('/\b(?:pric\w*|stock\w*|inventor\w*|cost\w*)\b/i', $token->text) === 1) {
            $uses[] = $token->text;
        } elseif ($token->is(T_CONSTANT_ENCAPSED_STRING) && preg_match('/pric|stock|inventor|cost/i', $token->text) === 1) {
            $uses[] = $token->text;
        }
    }

    return $uses;
}

it('PRD-019 finds the files of the selection engine and the two Actions', function () {
    $names = array_map('basename', selectionFiles());

    expect($names)->toContain('SelectionRules.php', 'CatalogSnapshotLoader.php', 'SelectionOptions.php', 'ResolveSelection.php', 'ListSelectionOptions.php')
        ->and(count($names))->toBeGreaterThan(10);
});

it('PRD-011 PRD-019 DT-02 imports nothing from pricing or stock and queries no price or stock table', function () {
    $offenders = [];

    foreach (selectionFiles() as $path) {
        $uses = selectionPriceOrStockUses($path);

        if ($uses !== []) {
            $offenders[basename($path)] = $uses;
        }
    }

    expect($offenders)->toBe([]);
});

it('PRD-011 PRD-019 DT-02 detects an import of a stock class and a price table in a file of the engine', function () {
    $path = tempnam(sys_get_temp_dir(), 'sel');
    file_put_contents($path, "<?php\nuse App\\Models\\StockMovement;\nuse App\\Support\\Pricing\\PriceList;\n\$rows = DB::table('product_prices');\n\$name = 'supply';\n// stock in a comment is fine\n");

    expect(selectionPriceOrStockUses($path))->toBe(['App\\Models\\StockMovement', 'App\\Support\\Pricing\\PriceList', "'product_prices'"]);

    unlink($path);
});
