<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppIcon from '@/components/AppIcon.vue';
import MoreSheet from '@/components/MoreSheet.vue';
import { FOCUS_RING } from '@/lib/ui';
import type { NavItem, NavSection } from '@/types';

const props = defineProps<{
    // At most 4 destinations shown in the bar.
    items: NavItem[];
    // Remaining destinations, listed in the "Más" sheet; the button is
    // rendered only when there are any.
    overflow: NavSection[];
}>();

const sheetOpen = ref(false);

const hasOverflow = computed(() => props.overflow.length > 0);
const overflowActive = computed(() =>
    props.overflow.some((section) => section.items.some((item) => item.active)),
);

const ITEM_CLASSES =
    'inline-flex min-h-12 w-full min-w-0 flex-col items-center justify-center rounded-full px-space-xs font-label-sm text-label-sm';
</script>

<template>
    <nav
        aria-label="Navegación principal"
        class="fixed inset-x-0 bottom-0 z-40 px-space-md pb-safe lg:hidden"
    >
        <ul
            class="mb-space-sm flex items-center justify-around gap-space-xs rounded-full bg-surface-container-high p-space-xs shadow-md"
        >
            <li v-for="item in items" :key="item.key" class="min-w-0 flex-1">
                <Link
                    :href="item.href"
                    :aria-current="item.active ? 'page' : undefined"
                    :class="[
                        ITEM_CLASSES,
                        item.active
                            ? 'bg-primary text-on-primary'
                            : 'text-on-surface-variant',
                        FOCUS_RING,
                    ]"
                >
                    <AppIcon :name="item.icon" />
                    <span class="whitespace-nowrap">{{ item.label }}</span>
                </Link>
            </li>
            <li v-if="hasOverflow" class="min-w-0 flex-1">
                <button
                    type="button"
                    aria-haspopup="dialog"
                    :aria-expanded="sheetOpen"
                    :class="[
                        ITEM_CLASSES,
                        overflowActive
                            ? 'bg-primary text-on-primary'
                            : 'text-on-surface-variant',
                        FOCUS_RING,
                    ]"
                    @click="sheetOpen = true"
                >
                    <AppIcon name="more_horiz" />
                    <span class="whitespace-nowrap">Más</span>
                </button>
            </li>
        </ul>

        <MoreSheet
            v-if="hasOverflow"
            v-model:open="sheetOpen"
            :sections="overflow"
        />
    </nav>
</template>
