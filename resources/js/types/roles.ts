// Shapes sent by `App\Http\Controllers\Roles\RoleController` (read-only DTOs).
export type RoleSummary = {
    id: number;
    name: string;
    description: string | null;
    // Protected role (Administrador): the backend rejects renaming and deleting it.
    is_protected: boolean;
    // Counts are only present on the list and detail pages.
    users_count: number | null;
    permissions_count: number | null;
};

// Catalog entry; `description` is the Spanish text shown to the user.
export type PermissionItem = {
    id: number;
    name: string;
    description: string;
};

export type RoleDetail = RoleSummary & {
    permissions: PermissionItem[];
};
