<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import AppIcon from '@/components/AppIcon.vue';
import { FOCUS_RING } from '@/lib/ui';
import type { NavItem } from '@/types';

defineProps<{
    items: NavItem[];
}>();
</script>

<template>
    <nav
        aria-label="Navegación principal"
        class="fixed inset-x-0 bottom-0 z-40 px-space-md pb-safe lg:hidden"
    >
        <ul
            class="mb-space-sm flex items-center justify-around rounded-full bg-surface-container-high p-space-xs shadow-md"
        >
            <li v-for="item in items" :key="item.href">
                <Link
                    :href="item.href"
                    :aria-current="item.active ? 'page' : undefined"
                    class="inline-flex min-h-12 min-w-12 flex-col items-center justify-center rounded-full px-space-sm font-label-sm text-label-sm"
                    :class="[
                        item.active
                            ? 'bg-primary text-on-primary'
                            : 'text-on-surface-variant',
                        FOCUS_RING,
                    ]"
                >
                    <AppIcon :name="item.icon" />
                    <span>{{ item.label }}</span>
                </Link>
            </li>
        </ul>
    </nav>
</template>
