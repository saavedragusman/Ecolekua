<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import AppIcon from '@/components/AppIcon.vue';
import { FOCUS_RING_ON_DARK } from '@/lib/ui';
import type { NavItem } from '@/types';

defineProps<{
    items: NavItem[];
}>();
</script>

<template>
    <nav
        aria-label="Navegación principal"
        class="hidden w-64 shrink-0 flex-col gap-space-lg bg-primary-container p-space-md lg:fixed lg:inset-y-0 lg:left-0 lg:flex"
    >
        <p
            class="px-space-sm font-headline-sm text-headline-sm text-on-primary"
        >
            Ecolekua
        </p>
        <ul class="flex flex-col gap-space-sm">
            <li v-for="item in items" :key="item.href">
                <Link
                    :href="item.href"
                    :aria-current="item.active ? 'page' : undefined"
                    class="flex min-h-11 items-center gap-space-sm rounded-lg px-space-md font-label-lg text-label-lg"
                    :class="[
                        item.active
                            ? 'bg-secondary-container text-primary'
                            : 'text-on-primary-container',
                        FOCUS_RING_ON_DARK,
                    ]"
                >
                    <AppIcon :name="item.icon" />
                    <span>{{ item.label }}</span>
                </Link>
            </li>
        </ul>
    </nav>
</template>
