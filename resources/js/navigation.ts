import { home } from '@/routes';
import { index as usersIndex } from '@/routes/users';

// Display order of the navigation groups. Groups without a visible entry are
// omitted. The type already admits Comercial, Producción and Inventario, but no
// entries are declared for them until their specs and routes exist
// (AGENTS.md §0; design Decision 20).
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
};

// Single source of truth for the sidebar, the bottom bar and the "Más" sheet.
// Filtering by permission in the frontend is visual only: authorization stays
// in the backend (FND-019). Permission names come from the catalog
// (`App\Enums\PermissionName`). `home` uses its Wayfinder helper; the other
// hrefs are the URIs declared in design.md "Routes and authorization" and
// switch to Wayfinder helpers when their routes exist (`users.index` done in
// 4.18; roles and audit in 6.12 and 7.8).
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
        key: 'users',
        label: 'Usuarios',
        icon: 'group',
        href: usersIndex.url(),
        permission: 'users.view',
        group: 'Administración',
        priority: 10,
    },
    {
        // TODO(6.12): replace with the Wayfinder `roles.index` helper.
        key: 'roles',
        label: 'Roles',
        icon: 'admin_panel_settings',
        href: '/roles',
        permission: 'roles.view',
        group: 'Administración',
        priority: 20,
    },
    {
        // TODO(7.8): replace with the Wayfinder `audit.index` helper.
        key: 'audit',
        label: 'Auditoría',
        icon: 'history',
        href: '/audit',
        permission: 'audit.view',
        group: 'Administración',
        priority: 30,
    },
];
