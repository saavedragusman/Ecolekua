// DTO shared by HandleInertiaRequests (design Decision 15). Never the whole
// User model: only these fields cross to the frontend.
export type AuthUser = {
    id: number;
    first_name: string;
    last_name: string;
    email: string;
    must_change_password: boolean;
};

export type Auth = {
    // null for guests (login page).
    user: AuthUser | null;
    // Permission names (`module.action`). Used only to decide what to show;
    // the backend remains the authority on every operation.
    permissions: string[];
};
