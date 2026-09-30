// Shapes sent by `App\Http\Controllers\Users\UserController` (read-only DTOs).
export type RoleOption = {
    id: number;
    name: string;
};

export type UserSummary = {
    id: number;
    first_name: string;
    last_name: string;
    email: string;
    is_active: boolean;
    must_change_password: boolean;
    roles: RoleOption[];
};

// Subset of Laravel's paginator JSON that the UI consumes.
export type Paginated<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    prev_page_url: string | null;
    next_page_url: string | null;
};
