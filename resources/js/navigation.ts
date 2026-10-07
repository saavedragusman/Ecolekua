import { home } from '@/routes';
import { index as auditIndex } from '@/routes/audit';
import { index as attributesIndex } from '@/routes/catalog/attributes';
import { index as categoriesIndex } from '@/routes/catalog/categories';
import { index as locationsIndex } from '@/routes/catalog/detail-locations';
import { index as customersIndex } from '@/routes/customers';
import { index as rolesIndex } from '@/routes/roles';
import { index as usersIndex } from '@/routes/users';

// Display order of the navigation groups. Groups without a visible entry are
// omitted. The type already admits Comercial, Producción and Inventario; entries
// are declared for a group only once its specs and routes exist (AGENTS.md §0;
// design Decision 20). Comercial has Clientes (spec 002) and Catálogo (spec 003).
export const NAV_GROUPS = [
    'Comercial',
    'Producción',
    'Inventario',
    'Administración',
] as const;

export type NavGroup = (typeof NAV_GROUPS)[number];

export type NavEntry = {
    key: string;
    label: string;
    // Material Symbols Outlined icon name.
    icon: string;
    href: string;
    // Permission name required to show the entry; null = always shown.
    permission: string | null;
    // null = ungrouped (shown first, without a heading).
    group: NavGroup | null;
    // Lower values come first (bottom bar, sidebar and sheet).
    priority: number;
    // Exact path match (landing page); prefix match otherwise.
    exact?: boolean;
    // Other paths that keep the entry highlighted (prefix match), for sections reached from it.
    alsoActiveOn?: string[];
};

// Single source of truth for the sidebar, the bottom bar and the "Más" sheet.
// Filtering by permission in the frontend is visual only: authorization stays
// in the backend (FND-019). Permission names come from the catalog
// (`App\Enums\PermissionName`). Every `href` comes from a Wayfinder helper, so
// no URI is hardcoded here.
export const NAV_ENTRIES: NavEntry[] = [
    {
        key: 'home',
        label: 'Inicio',
        icon: 'home',
        href: home.url(),
        permission: null,
        group: null,
        priority: 0,
        exact: true,
    },
    {
        key: 'customers',
        label: 'Clientes',
        icon: 'groups',
        href: customersIndex.url(),
        permission: 'customers.view',
        group: 'Comercial',
        priority: 10,
    },
    {
        // Entry point of the catalog (spec 003): lands on categories; the other sections are
        // reached from the page and keep the entry highlighted through `alsoActiveOn`.
        key: 'catalog',
        label: 'Catálogo',
        icon: 'category',
        href: categoriesIndex.url(),
        permission: 'products.catalog',
        group: 'Comercial',
        priority: 30,
        alsoActiveOn: [attributesIndex.url(), locationsIndex.url()],
    },
    {
        key: 'users',
        label: 'Usuarios',
        icon: 'group',
        href: usersIndex.url(),
        permission: 'users.view',
        group: 'Administración',
        priority: 10,
    },
    {
        key: 'roles',
        label: 'Roles',
        icon: 'admin_panel_settings',
        href: rolesIndex.url(),
        permission: 'roles.view',
        group: 'Administración',
        priority: 20,
    },
    {
        key: 'audit',
        label: 'Auditoría',
        icon: 'history',
        href: auditIndex.url(),
        permission: 'audit.view',
        group: 'Administración',
        priority: 30,
    },
];
