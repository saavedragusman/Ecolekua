// Shapes sent by the catalog controllers (`App\Http\Controllers\Catalog\*`, spec 003). Read-only
// DTOs: every value is already resolved by the backend (labels, order) and the UI only renders it.

import type { Paginated } from '@/types/users';

export type CatalogStatus = 'active' | 'inactive';

// Flags mirror `CatalogPolicy`; they only decide what to show, the backend authorizes every write.
export type CatalogCan = {
    manage: boolean;
};

// Listed in `sort_order` (PRD-001); inactive categories are included.
export type CatalogCategory = {
    id: number;
    name: string;
    status: CatalogStatus;
    status_label: string;
    sort_order: number;
};

export type CatalogPresentation = 'text' | 'image' | 'color';
export type CatalogSpecialUse = 'fabric' | 'size' | 'gender';

// Listed in `sort_order` (PRD-002); inactive attributes are included. `special_use` is null when the
// attribute has none; the labels are resolved by the backend.
export type CatalogAttribute = {
    id: number;
    name: string;
    presentation: CatalogPresentation;
    presentation_label: string;
    special_use: CatalogSpecialUse | null;
    special_use_label: string | null;
    status: CatalogStatus;
    status_label: string;
    sort_order: number;
    values_count: number;
};

export type CatalogSelectOption = {
    value: string;
    label: string;
};

// Labels of the attribute enums, for the create and edit form.
export type CatalogAttributeOptions = {
    presentations: CatalogSelectOption[];
    special_uses: CatalogSelectOption[];
};

// A color of the palette (an active value of the color attribute) or one offered by a fabric.
export type CatalogColorRef = {
    id: number;
    name: string;
    tone: string | null;
    status: CatalogStatus;
};

// Listed in `sort_order` (PRD-002). `tone` only exists on the values of the color attribute.
// `offered_colors` is an array (possibly empty) on the values of the fabric attribute and null on
// every other value (E-46).
export type CatalogAttributeValue = {
    id: number;
    name: string;
    description: string | null;
    tone: string | null;
    svg_layer: string | null;
    status: CatalogStatus;
    status_label: string;
    sort_order: number;
    offered_colors: CatalogColorRef[] | null;
};

// Listed by name; locations have no order (PRD-007). `svg_layer` links the location to a template
// layer (DT-03) and is null until one is set.
export type CatalogDetailLocation = {
    id: number;
    name: string;
    svg_layer: string | null;
    status: CatalogStatus;
    status_label: string;
};

// --- Product list and page (PRD-015) ---------------------------------------------------------

export type ProductStatusFilter = CatalogStatus | 'all';

// Counts of the three views, computed by the backend with the same search and filters.
export type ProductStatusCounts = Record<ProductStatusFilter, number>;

export type ProductListRow = {
    id: number;
    name: string;
    category: string;
    business_line_label: string;
    supply_mode_label: string;
    status: CatalogStatus;
    status_label: string;
    combinations_count: number;
};

export type ProductListPage = Paginated<ProductListRow>;

export type ProductFilterOption<T extends string | number = string> = {
    value: T;
    label: string;
};

// Options of the three list filters, labelled by the backend.
export type ProductFilterOptions = {
    categories: ProductFilterOption<number>[];
    lines: ProductFilterOption[];
    modes: ProductFilterOption[];
};

export type ProductAttributeRow = {
    id: number;
    name: string;
    role: 'axis' | 'order';
    role_label: string;
    values: { id: number; name: string }[];
    // A color declared without values takes its options from the chosen fabric (DEC-PRD-35).
    follows_fabric: boolean;
};

export type ProductCombinationRow = {
    id: number;
    code: string | null;
    // Product name plus the axis values (E-07).
    name: string;
    status: CatalogStatus;
    status_label: string;
    restrictions: { attribute: string; values: string[] }[];
    included_customizations: string[];
};

export type ProductReference = {
    id: number;
    name: string;
    status: CatalogStatus;
    status_label: string;
};

export type ProductStockOverride = {
    code: string;
    size: string | null;
    minimum: number;
};

export type ProductDetail = {
    id: number;
    name: string;
    description: string | null;
    category: string;
    business_line: string;
    business_line_label: string;
    supply_mode: string;
    supply_mode_label: string;
    portal_visible: boolean;
    // Effective custom color, computed by the backend (DEC-PRD-34).
    admits_custom_color: boolean;
    status: CatalogStatus;
    status_label: string;
    attributes: ProductAttributeRow[];
    combinations: ProductCombinationRow[];
    detail_locations: ProductReference[];
    customizations: ProductReference[];
    stock: {
        default: number | null;
        overrides: ProductStockOverride[];
    };
    // Image URLs and templates arrive with the upload slices.
    images: { has_main: boolean; templates: string[] };
};

// Flags mirror the policies; they only decide what to show.
export type ProductCan = {
    update: boolean;
    deactivate: boolean;
    delete: boolean;
    createCombination: boolean;
};
