import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { NAV_ENTRIES, NAV_GROUPS } from '@/navigation';
import type { NavEntry } from '@/navigation';
import type { NavItem, NavSection } from '@/types';

// Maximum destinations in the bottom bar; the "Más" button is the extra slot.
const MAX_PRIMARY = 4;

const ADMIN_GROUP = 'Administración';

function toSections(entries: NavItem[], groups: (string | null)[]) {
    const sections: NavSection[] = [];

    for (const group of groups) {
        const items = entries.filter(
            (item) => (groupByKey.get(item.key) ?? null) === group,
        );

        if (items.length > 0) {
            sections.push({ group, items });
        }
    }

    return sections;
}

const groupByKey = new Map<string, string | null>(
    NAV_ENTRIES.map((entry) => [entry.key, entry.group]),
);

export function useNavigation() {
    const page = usePage();

    function isActive(entry: NavEntry): boolean {
        const path = page.url.split('?')[0];

        const matches = (href: string) =>
            path === href || path.startsWith(`${href}/`);

        return entry.exact
            ? path === entry.href
            : [entry.href, ...(entry.alsoActiveOn ?? [])].some(matches);
    }

    // Visual filtering only: the backend authorizes every operation (FND-019).
    const visible = computed<NavItem[]>(() => {
        const permissions = page.props.auth?.permissions ?? [];

        return NAV_ENTRIES.filter(
            (entry) =>
                entry.permission === null ||
                permissions.includes(entry.permission),
        )
            .sort((a, b) => a.priority - b.priority)
            .map((entry) => ({
                key: entry.key,
                label: entry.label,
                href: entry.href,
                icon: entry.icon,
                active: isActive(entry),
            }));
    });

    const primary = computed<NavItem[]>(() =>
        visible.value
            .filter((item) => groupByKey.get(item.key) !== ADMIN_GROUP)
            .slice(0, MAX_PRIMARY),
    );

    const overflow = computed<NavSection[]>(() => {
        const inBar = new Set(primary.value.map((item) => item.key));

        return toSections(
            visible.value.filter((item) => !inBar.has(item.key)),
            [null, ...NAV_GROUPS],
        );
    });

    // Full menu for the sidebar: ungrouped first, then groups in order.
    const grouped = computed<NavSection[]>(() =>
        toSections(visible.value, [null, ...NAV_GROUPS]),
    );

    return { primary, overflow, grouped };
}
