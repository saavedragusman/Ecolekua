export type NavItem = {
    key: string;
    label: string;
    href: string;
    // Material Symbols Outlined icon name.
    icon: string;
    active: boolean;
};

// A run of navigation items under one heading. `group` is null for the
// ungrouped items, which are rendered first and without a heading.
export type NavSection = {
    group: string | null;
    items: NavItem[];
};
