<?php

namespace App\Support\Products\Import;

use App\Enums\AttributePresentation;
use App\Enums\AttributeRole;
use App\Enums\AttributeSpecialUse;
use App\Models\CatalogAttribute;
use App\Support\Products\CatalogRules;
use App\Support\Products\CombinationOverlap;
use App\Support\Products\CombinationRules;
use App\Support\Products\ProductRules;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Phase 1 of the initial load (PRD-018, design Decision 19) for the colors and combinations files:
 * every row is parsed and validated in memory with the same rules and pure classes as the HTTP
 * forms (`CatalogRules`, `ProductRules`, `CombinationRules`, `CombinationOverlap`), so nothing is
 * written until the whole load is known to be valid. It also puts the rows in the shape phase 2
 * creates through the real Actions: categories, attributes, values, offered colors, products with
 * their structure, and combinations with their restrictions (DEC-PRD-36).
 *
 * Attributes and values are identified by their lowercase name; every id used for the structural
 * checks is a synthetic counter, because the catalog is empty and nothing exists yet. A reason is
 * a rule message or a fixed text: it never repeats a cell value, and the plan never writes to disk.
 *
 * @phpstan-type Reported array{file: string, row: int, column: string, reason: string}
 * @phpstan-type Draft array{
 *     name: string, row: int, category: string, line: string, mode: string, stock: string,
 *     axes: list<string>, order: list<string>, valid: bool, fabric: bool,
 *     rows: list<array{line: int, code: string, description: string, axes: array<string, list<string>>, order: array<string, list<string>>}>
 * }
 */
final class CatalogImportPlan
{
    /** @var list<Reported> */
    public array $errors = [];

    /** @var list<array{name: string, row: int}> */
    public array $categories = [];

    /** @var list<array{key: string, name: string, presentation: string, special_use: string|null, row: int, file: string, column: string}> */
    public array $attributes = [];

    /** @var array<string, list<array{key: string, name: string, tone: string|null, row: int, file: string, column: string}>> */
    public array $values = [];

    /** @var list<array{fabric: string, colors: list<string>, row: int}> fabric value => palette colors it offers */
    public array $offered = [];

    /** @var list<array{name: string, row: int, category: string, line: string, mode: string, min_stock: int|null, structure: list<array{attribute: string, role: string, values: list<string>}>, combinations: list<array{code: string, description: string|null, axes: array<string, list<string>>, restrictions: array<string, list<string>>, row: int}>}> */
    public array $products = [];

    /** @var array<string, true> */
    private array $categoryKeys = [];

    /** @var array<string, int> attribute key => synthetic id */
    private array $attributeIds = [];

    /** @var array<string, CatalogAttribute> unsaved attributes, only for their presentation and special use */
    private array $attributeModels = [];

    /** @var array<string, array<string, int>> attribute key => value key => synthetic id */
    private array $valueIds = [];

    private int $nextValueId = 1;

    /** @var array<string, int> lowercase code => row */
    private array $codes = [];

    /** @var array<string, int> palette color key => row */
    private array $paletteRows = [];

    /** @var array<string, array{row: int, fabrics: list<string>}> valid palette colors => fabrics that offer them */
    private array $paletteFabrics = [];

    /** @var array<string, Draft> */
    private array $drafts = [];

    /**
     * @param  list<array{line: int, cells: array<string, string>}>  $combinationRows
     * @param  list<array{line: int, cells: array<string, string>}>  $colorRows
     */
    public static function build(array $combinationRows, array $colorRows): self
    {
        $plan = new self;
        $plan->readPalette($colorRows);

        foreach ($combinationRows as $row) {
            $plan->readCombination($row['line'], $row['cells']);
        }

        if (isset($plan->values[CatalogImportColumns::COLOR_ATTRIBUTE]) && ! isset($plan->attributeIds[CatalogImportColumns::COLOR_ATTRIBUTE])) {
            $plan->registerAttribute('Color', $plan->values[CatalogImportColumns::COLOR_ATTRIBUTE][0]['row'], CatalogImportColumns::FILE_COLORS, 'color');
        }

        foreach ($plan->drafts as $draft) {
            if ($draft['valid']) {
                $plan->finishProduct($draft);
            }
        }

        $plan->readOfferedColors();

        return $plan;
    }

    public function passes(): bool
    {
        return $this->errors === [];
    }

    // -----------------------------------------------------------------------------------------
    // Colors file
    // -----------------------------------------------------------------------------------------

    /**
     * @param  list<array{line: int, cells: array<string, string>}>  $rows
     */
    private function readPalette(array $rows): void
    {
        $model = $this->model(AttributePresentation::Color, null);
        $rules = Arr::only(CatalogRules::valueRules($model, null), ['name', 'tone']);

        foreach ($rows as ['line' => $line, 'cells' => $cells]) {
            $key = mb_strtolower($cells['color']);

            if ($key !== '' && isset($this->paletteRows[$key])) {
                $this->error(CatalogImportColumns::FILE_COLORS, $line, 'color', __('validation.catalog_import_color_repeated', ['row' => $this->paletteRows[$key]]));

                continue;
            }

            $this->paletteRows[$key] = $line;
            $data = array_filter(['name' => $cells['color'], 'tone' => $cells['tono']], fn (string $cell): bool => $cell !== '');

            if (! $this->check($data, $rules, ['name' => 'color', 'tone' => 'tono'], CatalogImportColumns::FILE_COLORS, $line)) {
                continue;
            }

            $this->valueIds[CatalogImportColumns::COLOR_ATTRIBUTE][$key] = $this->nextValueId++;
            $this->values[CatalogImportColumns::COLOR_ATTRIBUTE][] = [
                'key' => $key, 'name' => $cells['color'], 'tone' => $cells['tono'], 'row' => $line,
                'file' => CatalogImportColumns::FILE_COLORS, 'column' => 'color',
            ];
            $this->paletteFabrics[$key] = ['row' => $line, 'fabrics' => $this->splitNames($cells['telas'], '|')];
        }
    }

    /**
     * PRD-018: every fabric a color is offered in must be a Tela value of the combinations file.
     */
    private function readOfferedColors(): void
    {
        $fabricAttribute = null;

        foreach (array_keys($this->attributeIds) as $key) {
            if ($this->specialUse($key) === AttributeSpecialUse::Fabric) {
                $fabricAttribute = $key;

                break;
            }
        }

        $offered = [];

        foreach ($this->paletteFabrics as $colorKey => ['row' => $line, 'fabrics' => $fabrics]) {
            foreach ($fabrics as $fabric) {
                $fabricKey = mb_strtolower($fabric);

                if ($fabricAttribute === null || ! isset($this->valueIds[$fabricAttribute][$fabricKey])) {
                    $this->error(CatalogImportColumns::FILE_COLORS, $line, 'telas', __('validation.catalog_import_fabric_not_found'));

                    break;
                }

                $offered[$fabricKey]['row'] ??= $line;
                $offered[$fabricKey]['colors'][] = $colorKey;
            }
        }

        foreach ($offered as $fabricKey => $entry) {
            $this->offered[] = ['fabric' => $fabricKey, 'colors' => $entry['colors'], 'row' => $entry['row']];
        }
    }

    // -----------------------------------------------------------------------------------------
    // Combinations file
    // -----------------------------------------------------------------------------------------

    /**
     * @param  array<string, string>  $cells
     */
    private function readCombination(int $line, array $cells): void
    {
        $axes = $this->parseList($cells['ejes'], $line, 'ejes');
        $order = $this->parseList($cells['pedido'], $line, 'pedido');
        $productKey = mb_strtolower($cells['producto']);

        $this->validateCode($cells, $line);

        $draft = $this->drafts[$productKey] ?? null;
        $isFirst = $draft === null;

        if ($isFirst) {
            $draft = $this->startProduct($cells, $line);

            if ($draft === null) {
                return;
            }
        }

        $ok = $axes !== null && $order !== null;
        $rowAxes = [];
        $rowOrder = [];

        if ($axes !== null && $order !== null) {
            [$rowAxes, $rowOrder, $ok] = $this->readStructure($axes, $order, $line);
        }

        if ($isFirst) {
            $draft['axes'] = array_keys($rowAxes);
            $draft['order'] = array_keys($rowOrder);
            $draft['fabric'] = $this->declaresFabric($rowAxes);
            $draft['valid'] = $this->rolesAreValid($draft, $line) && $ok && $draft['valid'];
        } elseif ($draft['valid']) {
            $ok = $this->agreesWithFirstRow($draft, $cells, $rowAxes, $rowOrder, $line) && $ok;
        }

        if ($ok && $draft['valid']) {
            $draft['rows'][] = [
                'line' => $line,
                'code' => $cells['codigo'],
                'description' => $cells['descripcion'],
                'axes' => $rowAxes,
                'order' => $rowOrder,
            ];
        }

        $this->drafts[$productKey] = $draft;
    }

    /**
     * Category and product data of the first row of a product (PRD-003, PRD-009).
     *
     * @param  array<string, string>  $cells
     * @return Draft|null null when the product has no name to key it by
     */
    private function startProduct(array $cells, int $line): ?array
    {
        $file = CatalogImportColumns::FILE_COMBINATIONS;
        $mode = CatalogImportColumns::MODES[mb_strtolower($cells['modo'])] ?? ($cells['modo'] === '' ? null : 'desconocido');
        $businessLine = CatalogImportColumns::LINES[mb_strtolower($cells['linea'])] ?? ($cells['linea'] === '' ? null : 'desconocida');
        $stock = $cells['stock_minimo'];

        $data = array_filter([
            'name' => $cells['producto'],
            'business_line' => $businessLine,
            'supply_mode' => $mode,
            'min_stock_default' => $stock === '' ? null : (ctype_digit($stock) ? (int) $stock : $stock),
        ], fn (mixed $value): bool => $value !== null && $value !== '');

        $rules = Arr::only(CatalogRules::productRules(null, $mode), ['name', 'business_line', 'supply_mode', 'min_stock_default']);
        $columns = ['name' => 'producto', 'business_line' => 'linea', 'supply_mode' => 'modo', 'min_stock_default' => 'stock_minimo'];
        $valid = $this->check($data, $rules, $columns, $file, $line, CatalogRules::productMessages());

        if ($cells['producto'] === '') {
            return null;
        }

        $this->registerCategory($cells['categoria'], $line);

        return [
            'name' => $cells['producto'], 'row' => $line, 'category' => mb_strtolower($cells['categoria']),
            'line' => mb_strtolower($cells['linea']), 'mode' => mb_strtolower($cells['modo']), 'stock' => $stock,
            'axes' => [], 'order' => [], 'valid' => $valid, 'fabric' => false, 'rows' => [],
        ];
    }

    private function registerCategory(string $name, int $line): void
    {
        $key = mb_strtolower($name);

        if (isset($this->categoryKeys[$key])) {
            return;
        }

        $this->categoryKeys[$key] = true;
        $this->categories[] = ['name' => $name, 'row' => $line];
        $this->check(['name' => $name], ['name' => ['required', 'string', 'max:100']], ['name' => 'categoria'], CatalogImportColumns::FILE_COMBINATIONS, $line);
    }

    /**
     * @param  array<string, string>  $cells
     */
    private function validateCode(array $cells, int $line): void
    {
        $file = CatalogImportColumns::FILE_COMBINATIONS;
        $rules = Arr::only(CombinationRules::rules(null), ['code', 'description']);

        $this->check(array_filter(['code' => $cells['codigo'], 'description' => $cells['descripcion']], fn (string $cell): bool => $cell !== ''), $rules, ['code' => 'codigo', 'description' => 'descripcion'], $file, $line, CombinationRules::messages());

        $key = mb_strtolower($cells['codigo']);

        if ($key === '') {
            return;
        }

        if (isset($this->codes[$key])) {
            $this->error($file, $line, 'codigo', __('validation.catalog_import_code_repeated', ['row' => $this->codes[$key]]));

            return;
        }

        $this->codes[$key] = $line;
    }

    /**
     * Registers the attributes and values of one row and applies the row-level rules of the order
     * attributes: the color of a product with fabric carries no values (DEC-PRD-35), any other
     * order attribute carries at least one, and the colors come from the palette (DEC-PRD-38).
     *
     * @param  list<array{name: string, values: list<string>}>  $axes
     * @param  list<array{name: string, values: list<string>}>  $order
     * @return array{0: array<string, list<string>>, 1: array<string, list<string>>, 2: bool}
     */
    private function readStructure(array $axes, array $order, int $line): array
    {
        $file = CatalogImportColumns::FILE_COMBINATIONS;
        $ok = true;
        $rowAxes = [];
        $rowOrder = [];

        foreach ($axes as $entry) {
            $key = $this->registerAttribute($entry['name'], $line, $file, 'ejes');
            $rowAxes[$key] = $key === CatalogImportColumns::COLOR_ATTRIBUTE ? [] : $this->registerValues($key, $entry['values'], $line, 'ejes');
        }

        $fabric = $this->declaresFabric($rowAxes);

        foreach ($order as $entry) {
            $key = $this->registerAttribute($entry['name'], $line, $file, 'pedido');

            if (isset($rowAxes[$key])) {
                $this->error($file, $line, 'pedido', __('validation.catalog_import_attribute_in_both'));
                $ok = false;

                continue;
            }

            [$rowOrder[$key], $valid] = $this->orderValues($key, $entry['values'], $fabric, $line);
            $ok = $ok && $valid;
        }

        return [$rowAxes, $rowOrder, $ok];
    }

    /**
     * @param  list<string>  $names
     * @return array{0: list<string>, 1: bool} the value keys and whether the row-level rules hold
     */
    private function orderValues(string $attribute, array $names, bool $fabric, int $line): array
    {
        $file = CatalogImportColumns::FILE_COMBINATIONS;
        $isColor = $attribute === CatalogImportColumns::COLOR_ATTRIBUTE;

        if ($isColor && $fabric) {
            if ($names !== []) {
                $this->error($file, $line, 'pedido', __('validation.structure_color_values_not_allowed'));
            }

            return [[], $names === []];
        }

        if ($names === []) {
            $this->error($file, $line, 'pedido', __('validation.structure_values_required'));

            return [[], false];
        }

        if (! $isColor) {
            return [$this->registerValues($attribute, $names, $line, 'pedido'), true];
        }

        $keys = array_map(mb_strtolower(...), $names);
        $known = array_filter($keys, fn (string $key): bool => isset($this->valueIds[$attribute][$key]));

        if (count($known) !== count($keys)) {
            $this->error($file, $line, 'pedido', __('validation.catalog_import_color_not_in_palette'));
        }

        return [array_values($known), count($known) === count($keys)];
    }

    /**
     * @param  list<string>  $names
     * @return list<string> value keys
     */
    private function registerValues(string $attribute, array $names, int $line, string $column): array
    {
        $keys = [];

        foreach ($names as $name) {
            $key = mb_strtolower($name);
            $keys[] = $key;

            if (isset($this->valueIds[$attribute][$key])) {
                continue;
            }

            $this->valueIds[$attribute][$key] = $this->nextValueId++;
            $this->values[$attribute][] = [
                'key' => $key, 'name' => $name, 'tone' => null, 'row' => $line,
                'file' => CatalogImportColumns::FILE_COMBINATIONS, 'column' => $column,
            ];
            $this->check(['name' => $name], Arr::only(CatalogRules::valueRules($this->attributeModels[$attribute], null), ['name']), ['name' => $column], CatalogImportColumns::FILE_COMBINATIONS, $line);
        }

        return $keys;
    }

    private function registerAttribute(string $name, int $line, string $file, string $column): string
    {
        $key = mb_strtolower($name);

        if (isset($this->attributeIds[$key])) {
            return $key;
        }

        $isColor = $key === CatalogImportColumns::COLOR_ATTRIBUTE;
        $presentation = $isColor ? AttributePresentation::Color : AttributePresentation::Text;
        $specialUse = $this->specialUse($key);
        $name = $isColor ? 'Color' : $name;

        if ($specialUse === null && $this->resemblesSpecialUse($key)) {
            $this->error($file, $line, $column, __('validation.catalog_import_special_use_lookalike'));
        }

        $this->attributeIds[$key] = count($this->attributeIds) + 1;
        $this->attributeModels[$key] = $this->model($presentation, $specialUse);
        $this->attributes[] = [
            'key' => $key, 'name' => $name, 'presentation' => $presentation->value,
            'special_use' => $specialUse?->value, 'row' => $line, 'file' => $file, 'column' => $column,
        ];

        $data = array_filter(['name' => $name, 'presentation' => $presentation->value, 'special_use' => $specialUse?->value]);
        $this->check($data, CatalogRules::attributeRules(null, $presentation->value, false), ['name' => $column, 'presentation' => $column, 'special_use' => $column], $file, $line);

        return $key;
    }

    /**
     * DEC-PRD-50: the fabric is always an axis and the color always an order attribute, checked
     * for each attribute of the product with the pure `ProductRules::roleViolation()`.
     *
     * @param  Draft  $draft
     */
    private function rolesAreValid(array $draft, int $line): bool
    {
        $valid = true;

        foreach ([[$draft['axes'], AttributeRole::Axis, 'ejes'], [$draft['order'], AttributeRole::Order, 'pedido']] as [$keys, $role, $column]) {
            foreach ($keys as $key) {
                $model = $this->attributeModels[$key];

                $violation = ProductRules::roleViolation([[
                    'attribute_id' => $this->attributeIds[$key],
                    'role' => $role,
                    'presentation' => $model->presentation,
                    'special_use' => $model->special_use,
                ]]);

                if ($violation !== null) {
                    $message = $model->special_use === AttributeSpecialUse::Fabric ? 'structure_role_fabric' : 'structure_role_color';
                    $this->error(CatalogImportColumns::FILE_COMBINATIONS, $line, $column, __("validation.{$message}"));
                    $valid = false;
                }
            }
        }

        return $valid;
    }

    /**
     * DEC-PRD-48: rows of one product agree in category, line, mode, default minimum and structure;
     * the later row reports the difference against the first one.
     *
     * @param  Draft  $draft
     * @param  array<string, string>  $cells
     * @param  array<string, list<string>>  $axes
     * @param  array<string, list<string>>  $order
     */
    private function agreesWithFirstRow(array $draft, array $cells, array $axes, array $order, int $line): bool
    {
        $file = CatalogImportColumns::FILE_COMBINATIONS;
        $first = $draft['row'];
        $differences = [
            'categoria' => [mb_strtolower($cells['categoria']) !== $draft['category'], 'catalog_import_differs_category'],
            'linea' => [mb_strtolower($cells['linea']) !== $draft['line'], 'catalog_import_differs_line'],
            'modo' => [mb_strtolower($cells['modo']) !== $draft['mode'], 'catalog_import_differs_mode'],
            'stock_minimo' => [$cells['stock_minimo'] !== $draft['stock'], 'catalog_import_differs_stock'],
            'ejes' => [array_keys($axes) !== $draft['axes'], 'catalog_import_differs_axes'],
            'pedido' => [$this->sorted(array_keys($order)) !== $this->sorted($draft['order']), 'catalog_import_differs_order'],
        ];
        $agrees = true;

        foreach ($differences as $column => [$differs, $message]) {
            if ($differs) {
                $this->error($file, $line, $column, __("validation.{$message}", ['row' => $first]));
                $agrees = false;
            }
        }

        return $agrees;
    }

    /**
     * Union of the allowed values, restrictions (DEC-PRD-36), structural validation and overlap
     * (DEC-PRD-39) of the rows of one product, then its entry for phase 2.
     *
     * @param  Draft  $draft
     */
    private function finishProduct(array $draft): void
    {
        $file = CatalogImportColumns::FILE_COMBINATIONS;
        $union = [];

        foreach ($draft['rows'] as $row) {
            foreach ([...$row['axes'], ...$row['order']] as $key => $values) {
                $union[$key] = array_values(array_unique([...($union[$key] ?? []), ...$values]));
            }
        }

        $declared = [];
        $structure = [];

        foreach ([[$draft['axes'], AttributeRole::Axis], [$draft['order'], AttributeRole::Order]] as [$keys, $role]) {
            foreach ($keys as $key) {
                $values = $union[$key] ?? [];
                $declared[] = [
                    'attribute_id' => $this->attributeIds[$key],
                    'role' => $role,
                    'presentation' => $this->attributeModels[$key]->presentation,
                    'special_use' => $this->attributeModels[$key]->special_use,
                    'allowed' => $this->ids($key, $values),
                ];
                $structure[] = ['attribute' => $key, 'role' => $role->value, 'values' => $values];
            }
        }

        $others = [];
        $combinations = [];

        foreach ($draft['rows'] as $row) {
            $axes = [];

            foreach ($row['axes'] as $key => $values) {
                $axes[$this->attributeIds[$key]] = $this->ids($key, $values);
            }

            $restrictions = [];
            $restrictionKeys = [];

            foreach ($row['order'] as $key => $values) {
                if ($values !== [] && count($values) < count($union[$key])) {
                    $restrictions[$this->attributeIds[$key]] = $this->ids($key, $values);
                    $restrictionKeys[$key] = $values;
                }
            }

            try {
                CombinationRules::validate($declared, $axes, $restrictions);
            } catch (ValidationException $invalid) {
                foreach ($invalid->errors() as $field => $reasons) {
                    $this->error($file, $row['line'], str_starts_with($field, 'axes') ? 'ejes' : 'pedido', $reasons[0]);
                }

                continue;
            }

            $overlap = CombinationOverlap::firstOverlap($axes, $others);

            if ($overlap !== null) {
                $this->error($file, $row['line'], 'ejes', __('validation.catalog_import_overlap', ['row' => $overlap]));

                continue;
            }

            $others[$row['line']] = $axes;
            $combinations[] = [
                'code' => $row['code'],
                'description' => $row['description'] === '' ? null : $row['description'],
                'axes' => $row['axes'],
                'restrictions' => $restrictionKeys,
                'row' => $row['line'],
            ];
        }

        $this->products[] = [
            'name' => $draft['name'],
            'row' => $draft['row'],
            'category' => $draft['category'],
            'line' => CatalogImportColumns::LINES[$draft['line']],
            'mode' => CatalogImportColumns::MODES[$draft['mode']],
            'min_stock' => $draft['stock'] === '' ? null : (int) $draft['stock'],
            'structure' => $structure,
            'combinations' => $combinations,
        ];
    }

    // -----------------------------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------------------------

    /**
     * `Name=value,value|Name=value` as entries. An empty cell is no entries; a malformed or
     * repeated entry is reported and makes the whole cell unusable (null).
     *
     * @return list<array{name: string, values: list<string>}>|null
     */
    private function parseList(string $cell, int $line, string $column): ?array
    {
        $entries = [];
        $seen = [];

        foreach (explode('|', $cell) as $part) {
            if (trim($part) === '') {
                continue;
            }

            [$name, $rest] = array_pad(explode('=', $part, 2), 2, null);
            $name = trim((string) $name);
            $key = mb_strtolower($name);

            if ($rest === null || $name === '') {
                $this->error(CatalogImportColumns::FILE_COMBINATIONS, $line, $column, __('validation.catalog_import_list_malformed'));

                return null;
            }

            if (isset($seen[$key])) {
                $this->error(CatalogImportColumns::FILE_COMBINATIONS, $line, $column, __('validation.catalog_import_list_repeated'));

                return null;
            }

            $seen[$key] = true;
            $entries[] = ['name' => $name, 'values' => $this->splitNames($rest, ',')];
        }

        return $entries;
    }

    /**
     * Trimmed, non-empty names without repeats (letter case ignored), in order.
     *
     * @param  non-empty-string  $separator
     * @return list<string>
     */
    private function splitNames(string $cell, string $separator): array
    {
        $names = [];

        foreach (explode($separator, $cell) as $name) {
            $name = trim($name);

            if ($name !== '') {
                $names[mb_strtolower($name)] ??= $name;
            }
        }

        return array_values($names);
    }

    /**
     * @param  array<string, list<string>>  $axes
     */
    private function declaresFabric(array $axes): bool
    {
        foreach (array_keys($axes) as $key) {
            if ($this->specialUse($key) === AttributeSpecialUse::Fabric) {
                return true;
            }
        }

        return false;
    }

    private function specialUse(string $attributeKey): ?AttributeSpecialUse
    {
        return CatalogImportColumns::SPECIAL_USES[Str::ascii($attributeKey)] ?? null;
    }

    /**
     * DEC-PRD-104: a name that is not exactly a special use but is a known synonym, is one edit away
     * from a special use (plural, typo) or is a special use plus "es".
     */
    private function resemblesSpecialUse(string $attributeKey): bool
    {
        $name = trim(Str::ascii($attributeKey));

        if (in_array($name, CatalogImportColumns::SPECIAL_USE_SYNONYMS, true)) {
            return true;
        }

        foreach (array_keys(CatalogImportColumns::SPECIAL_USES) as $use) {
            if ($name === $use.'es' || levenshtein($name, $use) <= 1) {
                return true;
            }
        }

        return false;
    }

    private function model(AttributePresentation $presentation, ?AttributeSpecialUse $specialUse): CatalogAttribute
    {
        return (new CatalogAttribute)->forceFill(['presentation' => $presentation, 'special_use' => $specialUse]);
    }

    /**
     * @param  list<string>  $valueKeys
     * @return list<int>
     */
    private function ids(string $attribute, array $valueKeys): array
    {
        return array_map(fn (string $key): int => $this->valueIds[$attribute][$key], $valueKeys);
    }

    /**
     * @param  list<string>  $keys
     * @return list<string>
     */
    private function sorted(array $keys): array
    {
        sort($keys);

        return $keys;
    }

    /**
     * Runs the rules and reports each failed field with the CSV column it maps to.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, list<mixed>>  $rules
     * @param  array<string, string>  $columns  rule field => CSV column (also the name shown in the message)
     * @param  array<string, string>  $messages
     */
    private function check(array $data, array $rules, array $columns, string $file, int $line, array $messages = []): bool
    {
        $validator = Validator::make($data, $rules, $messages, $columns);

        if (! $validator->fails()) {
            return true;
        }

        foreach ($validator->errors()->messages() as $field => $reasons) {
            $this->error($file, $line, $columns[$field] ?? $field, $reasons[0]);
        }

        return false;
    }

    private function error(string $file, int $line, string $column, string $reason): void
    {
        $this->errors[] = ['file' => $file, 'row' => $line, 'column' => $column, 'reason' => $reason];
    }
}
