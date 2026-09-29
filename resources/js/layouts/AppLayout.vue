<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import BottomNav from '@/components/BottomNav.vue';
import FlashMessage from '@/components/FlashMessage.vue';
import IconButton from '@/components/IconButton.vue';
import SideNav from '@/components/SideNav.vue';
import { useAppearance } from '@/composables/useAppearance';
import { home } from '@/routes';
import { edit as passwordEdit } from '@/routes/password';
import type { NavItem } from '@/types';

const page = usePage();
const { appearance, toggleAppearance } = useAppearance();

type Destination = {
    label: string;
    href: string;
    icon: string;
    // Permission name required to show the entry; null = always shown.
    permission: string | null;
    // Exact match for the landing page, prefix match for the others.
    exact?: boolean;
};

// At most 5 destinations (design-system §7.8). Permission names come from
// the catalog (`App\Enums\PermissionName`). The URIs are the ones declared in
// design.md "Routes and authorization"; `home` and `password.edit` already use
// Wayfinder helpers, the rest follow when their routes exist (tasks 4.18, 6.12, 7.8).
const DESTINATIONS: Destination[] = [
    {
        label: 'Inicio',
        href: home.url(),
        icon: 'home',
        permission: null,
        exact: true,
    },
    {
        label: 'Usuarios',
        href: '/users',
        icon: 'group',
        permission: 'users.view',
    },
    {
        label: 'Roles',
        href: '/roles',
        icon: 'admin_panel_settings',
        permission: 'roles.view',
    },
    {
        label: 'Auditoría',
        href: '/audit',
        icon: 'history',
        permission: 'audit.view',
    },
    {
        label: 'Cambiar contraseña',
        href: passwordEdit.url(),
        icon: 'key',
        permission: null,
    },
];

function isActive(destination: Destination): boolean {
    const path = page.url.split('?')[0];

    return destination.exact
        ? path === destination.href
        : path === destination.href || path.startsWith(`${destination.href}/`);
}

const navItems = computed<NavItem[]>(() => {
    const permissions = page.props.auth?.permissions ?? [];

    return DESTINATIONS.filter(
        (destination) =>
            destination.permission === null ||
            permissions.includes(destination.permission),
    ).map((destination) => ({
        label: destination.label,
        href: destination.href,
        icon: destination.icon,
        active: isActive(destination),
    }));
});

const userName = computed(() => {
    const user = page.props.auth?.user;

    return user ? `${user.first_name} ${user.last_name}` : '';
});

const isDark = computed(() => appearance.value === 'dark');
</script>

<template>
    <div class="min-h-dvh bg-surface text-on-surface">
        <SideNav :items="navItems" />

        <div class="lg:pl-64">
            <header
                class="flex items-center justify-end gap-space-sm px-gutter-mobile pt-space-md md:px-gutter"
            >
                <span
                    v-if="userName"
                    class="font-label-md text-label-md text-on-surface-variant"
                    >{{ userName }}</span
                >
                <IconButton
                    variant="secondary"
                    :icon="isDark ? 'light_mode' : 'dark_mode'"
                    :label="
                        isDark ? 'Activar modo claro' : 'Activar modo oscuro'
                    "
                    @click="toggleAppearance"
                />
            </header>

            <main
                class="px-gutter-mobile pt-space-md pb-28 md:px-gutter lg:pb-space-xl"
            >
                <FlashMessage class="mb-space-md" />
                <slot />
            </main>
        </div>

        <BottomNav :items="navItems" />
    </div>
</template>
