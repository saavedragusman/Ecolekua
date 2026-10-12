<?php

namespace App\Actions\Products;

use App\Models\AttributeValue;
use App\Models\CatalogAttribute;
use App\Models\Combo;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use App\Support\Audit\AuditOrigin;
use App\Support\Csv\CsvReader;
use App\Support\Csv\InvalidCsv;
use App\Support\Products\Import\CatalogImportColumns;
use App\Support\Products\Import\CatalogImportPlan;
use App\Support\Products\Import\CatalogImportReport;
use App\Support\Users\UserRules;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * One-time, all-or-nothing initial load of the catalog (PRD-018, design Decision 19). Phase 0 checks the author, the options, that the catalog is empty and the
 * files; phase 1 (`CatalogImportPlan`) validates every row in memory with the rules of the HTTP
 * forms; only when nothing blocks, phase 2 creates everything through the real Actions in one
 * transaction with the console origin and the import marker, so any exception leaves the database
 * untouched. Combos are created last, through `CreateCombo`, once the products they name exist.
 *
 * It is not a generic import engine: it knows this one file layout. The report holds file names,
 * row numbers, CSV column names and fixed reasons only, never cell values.
 */
class ImportCatalog
{
    private const COMMAND = 'catalog:import';

    public function __construct(
        private readonly CsvReader $reader,
        private readonly CreateCategory $createCategory,
        private readonly CreateCatalogAttribute $createAttribute,
        private readonly CreateAttributeValue $createValue,
        private readonly SyncFabricOfferedColors $syncOfferedColors,
        private readonly CreateProduct $createProduct,
        private readonly SyncProductAttributes $syncStructure,
        private readonly CreateCombination $createCombination,
        private readonly CreateCombo $createCombo,
    ) {}

    /**
     * @throws \Throwable when an unexpected failure happens while creating; nothing has been saved then
     */
    public function handle(?string $combinations, ?string $combos, ?string $colors, ?string $authorEmail): CatalogImportReport
    {
        $author = $this->activeUser($authorEmail);
        $problems = $this->optionProblems($author, $authorEmail, [
            ['--combinations', 'de combinaciones', $combinations],
            ['--combos', 'de combos', $combos],
            ['--colors', 'de colores', $colors],
        ]);

        if ($problems === [] && ! $this->catalogIsEmpty()) {
            $problems[] = 'El catálogo no está vacío: la carga inicial solo se ejecuta sobre un catálogo sin categorías, atributos, productos ni combos.';
        }

        if ($author === null || $problems !== []) {
            return CatalogImportReport::blocked($problems);
        }

        [$problems, $files] = $this->readFiles((string) $combinations, (string) $combos, (string) $colors);

        if ($problems !== []) {
            return CatalogImportReport::blocked($problems);
        }

        $plan = CatalogImportPlan::build($files['combinations'], $files['colors'], $files['combos']);

        if (! $plan->passes()) {
            return new CatalogImportReport(errors: $plan->errors);
        }

        return $this->create($plan, $author);
    }

    private function activeUser(?string $email): ?User
    {
        if ($email === null || trim($email) === '') {
            return null;
        }

        return User::query()
            ->where('email', UserRules::normalizeEmail($email))
            ->where('is_active', true)
            ->first();
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string|null}>  $files  option name, label and value
     * @return list<string>
     */
    private function optionProblems(?User $author, ?string $authorEmail, array $files): array
    {
        $problems = [];

        if ($author === null) {
            $problems[] = trim((string) $authorEmail) === ''
                ? 'Debe indicar el autor con --author=correo.'
                : 'El autor indicado no existe o está inactivo.';
        }

        foreach ($files as [$option, $label, $path]) {
            if ($path === null || trim($path) === '') {
                $problems[] = "Debe indicar el archivo {$label} con {$option}=ruta.";
            }
        }

        return $problems;
    }

    private function catalogIsEmpty(): bool
    {
        return ! ProductCategory::query()->exists()
            && ! CatalogAttribute::query()->exists()
            && ! Product::query()->exists()
            && ! Combo::query()->exists();
    }

    /**
     * Phase 0: the three files with their exact headers. The combos and colors files may hold only a header.
     *
     * @return array{0: list<string>, 1: array{combinations: list<array{line: int, cells: array<string, string>}>, combos: list<array{line: int, cells: array<string, string>}>, colors: list<array{line: int, cells: array<string, string>}>}}
     */
    private function readFiles(string $combinations, string $combos, string $colors): array
    {
        $problems = [];

        $read = [
            'combinations' => $this->readFile(CatalogImportColumns::FILE_COMBINATIONS, $combinations, CatalogImportColumns::COMBINATIONS, false, $problems),
            'combos' => $this->readFile(CatalogImportColumns::FILE_COMBOS, $combos, CatalogImportColumns::COMBOS, true, $problems),
            'colors' => $this->readFile(CatalogImportColumns::FILE_COLORS, $colors, CatalogImportColumns::COLORS, true, $problems),
        ];

        return [$problems, $read];
    }

    /**
     * @param  list<string>  $columns
     * @param  list<string>  $problems  receives the reasons the file cannot be used, prefixed with its name
     * @return list<array{line: int, cells: array<string, string>}>
     */
    private function readFile(string $name, string $path, array $columns, bool $allowEmpty, array &$problems): array
    {
        try {
            return $this->reader->read($path, $columns, $allowEmpty);
        } catch (InvalidCsv $invalid) {
            foreach ($invalid->problems as $problem) {
                $problems[] = "Archivo {$name}: {$problem}";
            }

            return [];
        }
    }

    /**
     * Phase 2: everything in one transaction. A row rejected by an Action (a name that a database
     * index treats as repeated, for example) rolls the whole import back and is reported like a
     * phase 1 error: file, row, CSV column and reason, never the cell values.
     */
    private function create(CatalogImportPlan $plan, User $author): CatalogImportReport
    {
        $origin = AuditOrigin::console(self::COMMAND);
        $errors = [];

        try {
            $imported = DB::transaction(function () use ($plan, $author, $origin, &$errors): array {
                return $this->createAll($plan, $author, $origin, $errors);
            });
        } catch (ValidationException) {
            return new CatalogImportReport(errors: $errors);
        }

        return new CatalogImportReport(imported: $imported);
    }

    /**
     * @param  list<array{file: string, row: int, column: string, reason: string}>  $errors  filled when an Action rejects a row
     * @return array<string, int>
     */
    private function createAll(CatalogImportPlan $plan, User $author, AuditOrigin $origin, array &$errors): array
    {
        $categories = [];

        foreach ($plan->categories as $category) {
            $categories[mb_strtolower($category['name'])] = $this->attempt(
                $errors, CatalogImportColumns::FILE_COMBINATIONS, $category['row'], 'categoria',
                fn (array $context): ProductCategory => $this->createCategory->handle(['name' => $category['name']], $author, $origin, $context),
            );
        }

        $attributes = [];
        $values = [];
        $valueCount = 0;

        foreach ($plan->attributes as $attribute) {
            $attributes[$attribute['key']] = $this->attempt(
                $errors, $attribute['file'], $attribute['row'], $attribute['column'],
                fn (array $context): CatalogAttribute => $this->createAttribute->handle(
                    array_filter(['name' => $attribute['name'], 'presentation' => $attribute['presentation'], 'special_use' => $attribute['special_use']]),
                    $author, $origin, $context,
                ),
            );
        }

        foreach ($plan->attributes as $attribute) {
            foreach ($plan->values[$attribute['key']] ?? [] as $value) {
                $values[$attribute['key']][$value['key']] = $this->attempt(
                    $errors, $value['file'], $value['row'], $value['column'],
                    fn (array $context): AttributeValue => $this->createValue->handle(
                        $attributes[$attribute['key']],
                        array_filter(['name' => $value['name'], 'tone' => $value['tone']]),
                        $author, $origin, $context,
                    ),
                );
                $valueCount++;
            }
        }

        $this->createOfferedColors($plan, $author, $origin, $errors, $attributes, $values);

        $combinations = 0;
        $products = [];

        foreach ($plan->products as $draft) {
            $products[mb_strtolower($draft['name'])] = $product = $this->attempt(
                $errors, CatalogImportColumns::FILE_COMBINATIONS, $draft['row'], 'producto',
                fn (array $context): Product => $this->createProduct->handle(
                    array_filter([
                        'name' => $draft['name'],
                        'product_category_id' => $categories[$draft['category']]->id,
                        'business_line' => $draft['line'],
                        'supply_mode' => $draft['mode'],
                        'min_stock_default' => $draft['min_stock'],
                    ], fn (mixed $value): bool => $value !== null),
                    $author, $origin, $context,
                ),
            );

            $entries = array_map(fn (array $entry): array => [
                'attribute_id' => $attributes[$entry['attribute']]->id,
                'role' => $entry['role'],
                'allowed_value_ids' => array_map(fn (string $key): int => $values[$entry['attribute']][$key]->id, $entry['values']),
            ], $draft['structure']);

            $this->attempt(
                $errors, CatalogImportColumns::FILE_COMBINATIONS, $draft['row'], 'ejes',
                fn (array $context): Product => $this->syncStructure->handle($product, $entries, $author, $origin, $context),
            );

            foreach ($draft['combinations'] as $combination) {
                $this->attempt(
                    $errors, CatalogImportColumns::FILE_COMBINATIONS, $combination['row'], 'ejes',
                    fn (array $context) => $this->createCombination->handle($product, [
                        'code' => $combination['code'],
                        'description' => $combination['description'],
                        'axes' => $this->idMap($combination['axes'], $attributes, $values),
                        'restrictions' => $this->idMap($combination['restrictions'], $attributes, $values),
                    ], $author, $origin, $context),
                );
                $combinations++;
            }
        }

        foreach ($plan->combos as $combo) {
            $this->attempt(
                $errors, CatalogImportColumns::FILE_COMBOS, $combo['row'], 'nombre',
                fn (array $context): Combo => $this->createCombo->handle([
                    'name' => $combo['name'],
                    'code' => $combo['code'],
                    // DEC-PRD-105: imported combos are published in the portal.
                    'portal_visible' => true,
                    'components' => array_map(fn (array $component): array => [
                        'product_id' => $products[$component['product']]->id,
                        'quantity' => $component['quantity'],
                        'values' => $this->idMap($component['values'], $attributes, $values),
                    ], $combo['components']),
                ], $author, $origin, $context),
            );
        }

        return [
            'categorías' => count($categories),
            'atributos' => count($attributes),
            'valores' => $valueCount,
            'productos' => count($plan->products),
            'combinaciones' => $combinations,
            'combos' => count($plan->combos),
        ];
    }

    /**
     * @param  list<array{file: string, row: int, column: string, reason: string}>  $errors
     * @param  array<string, CatalogAttribute>  $attributes
     * @param  array<string, array<string, AttributeValue>>  $values
     */
    private function createOfferedColors(CatalogImportPlan $plan, User $author, AuditOrigin $origin, array &$errors, array $attributes, array $values): void
    {
        $fabricKey = null;

        foreach ($plan->attributes as $attribute) {
            if ($attribute['special_use'] === 'fabric') {
                $fabricKey = $attribute['key'];
            }
        }

        foreach ($plan->offered as $offer) {
            if ($fabricKey === null) {
                continue;
            }

            $this->attempt(
                $errors, CatalogImportColumns::FILE_COLORS, $offer['row'], 'telas',
                fn (array $context) => $this->syncOfferedColors->handle(
                    $values[$fabricKey][$offer['fabric']],
                    array_map(fn (string $key): int => $values[CatalogImportColumns::COLOR_ATTRIBUTE][$key]->id, $offer['colors']),
                    $author, $origin, $context,
                ),
            );
        }
    }

    /**
     * Runs one Action call with the import marker of its CSV row; a rejection is recorded under
     * the CSV column its field maps to and rethrown so the transaction rolls back.
     *
     * @template T
     *
     * @param  list<array{file: string, row: int, column: string, reason: string}>  $errors
     * @param  Closure(array<string, mixed>): T  $callback
     * @return T
     *
     * @throws ValidationException
     */
    private function attempt(array &$errors, string $file, int $row, string $column, Closure $callback): mixed
    {
        try {
            return $callback(['import' => true, 'import_file' => $file, 'import_row' => $row]);
        } catch (ValidationException $rejected) {
            foreach ($rejected->validator->errors()->messages() as $field => $reasons) {
                foreach ($reasons as $reason) {
                    $errors[] = ['file' => $file, 'row' => $row, 'column' => $this->columnOf($field, $column), 'reason' => $reason];
                }
            }

            throw $rejected;
        }
    }

    /**
     * The CSV column of a field of an Action rejection; any other field keeps the step's column.
     */
    private function columnOf(string $field, string $default): string
    {
        return match (true) {
            str_starts_with($field, 'axes') => 'ejes',
            str_starts_with($field, 'restrictions') => 'pedido',
            str_ends_with($field, 'product_id') => 'producto',
            str_ends_with($field, 'quantity') => 'cantidad',
            str_starts_with($field, 'components') => 'valores',
            $field === 'code' => 'codigo',
            $field === 'description' => 'descripcion',
            default => $default,
        };
    }

    /**
     * @param  array<string, list<string>>  $map  attribute key => value keys
     * @param  array<string, CatalogAttribute>  $attributes
     * @param  array<string, array<string, AttributeValue>>  $values
     * @return array<int, list<int>> attribute id => value ids
     */
    private function idMap(array $map, array $attributes, array $values): array
    {
        $ids = [];

        foreach ($map as $attribute => $keys) {
            $ids[$attributes[$attribute]->id] = array_map(fn (string $key): int => $values[$attribute][$key]->id, $keys);
        }

        return $ids;
    }
}
