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

// Listed by name; locations have no order (PRD-007). `svg_layer` links the location to a template
// layer (DT-03) and is null until one is set.
export type CatalogDetailLocation = {
    id: number;
    name: string;
    svg_layer: string | null;
    status: CatalogStatus;
    status_label: string;
};
