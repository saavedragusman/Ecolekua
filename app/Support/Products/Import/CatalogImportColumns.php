<?php

namespace App\Support\Products\Import;

use App\Enums\AttributeSpecialUse;
use App\Enums\BusinessLine;
use App\Enums\SupplyMode;

/**
 * The layout of the initial load files (PRD-018, design Decision 19, "CSV files"): header columns,
 * the file names used in the report and in the audit context, and the words the files use for
 * lines, supply modes and the attributes with a special use.
 */
final class CatalogImportColumns
{
    public const FILE_COMBINATIONS = 'combinaciones';

    public const FILE_COMBOS = 'combos';

    public const FILE_COLORS = 'colores';

    /** One row per combination. */
    public const COMBINATIONS = ['categoria', 'producto', 'linea', 'modo', 'stock_minimo', 'codigo', 'ejes', 'pedido', 'descripcion'];

    /** One row per combo component. */
    public const COMBOS = ['codigo', 'nombre', 'producto', 'cantidad', 'valores'];

    /** One row per palette color. */
    public const COLORS = ['color', 'tono', 'telas'];

    /** Business line word => enum value; the key is compared in lowercase. */
    public const LINES = [
        'uniformes' => BusinessLine::Uniforms->value,
        'pañales' => BusinessLine::Diapers->value,
    ];

    /** Supply mode word => enum value; the key is compared in lowercase. */
    public const MODES = [
        'bajo_pedido' => SupplyMode::OnDemand->value,
        'stock_con_minimo' => SupplyMode::StockWithMinimum->value,
        'stock_agotable' => SupplyMode::StockDepletable->value,
        'servicio' => SupplyMode::Service->value,
    ];

    /**
     * Special use the initial load assigns to the attribute of each name (DEC-PRD-49), by the
     * lowercase name without accents.
     */
    public const SPECIAL_USES = [
        'tela' => AttributeSpecialUse::Fabric,
        'genero' => AttributeSpecialUse::Gender,
        'talla' => AttributeSpecialUse::Size,
    ];

    /** Known synonyms of the special uses, by the lowercase name without accents (DEC-PRD-104). */
    public const SPECIAL_USE_SYNONYMS = ['sexo', 'sexos', 'talle', 'talles', 'tejido', 'tejidos'];

    /** Name of the one attribute with presentation color, by the lowercase name. */
    public const COLOR_ATTRIBUTE = 'color';
}
