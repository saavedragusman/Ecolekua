// Shapes sent by the catalog controllers (`App\Http\Controllers\Catalog\*`, spec 003). Read-only
// DTOs: every value is already resolved by the backend (labels, order) and the UI only renders it.

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
