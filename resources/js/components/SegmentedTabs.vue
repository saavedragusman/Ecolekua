<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { FOCUS_RING } from '@/lib/ui';

export type SegmentedTab = {
    key: string;
    label: string;
    // Server-computed count shown next to the label.
    count?: number;
    href: string;
};

// Filter views as plain links (design-system §7.11). The selected view comes from
// the server, so this component only renders it; it is not an ARIA tablist.
defineProps<{
    tabs: SegmentedTab[];
    current: string;
    // Accessible name of the `<nav>` landmark.
    label: string;
}>();

const BASE =
    'inline-flex min-h-11 flex-1 items-center justify-center gap-space-xs rounded-lg px-space-md py-space-sm font-label-lg text-label-lg transition-colors md:flex-none';
const SELECTED = 'bg-primary text-on-primary';
const IDLE = 'text-on-surface-variant hover:bg-surface-container-highest';
</script>

<template>
    <nav
        :aria-label="label"
        class="flex w-full gap-space-xs rounded-lg bg-surface-container-high p-space-xs md:w-fit"
    >
        <Link
            v-for="tab in tabs"
            :key="tab.key"
            :href="tab.href"
            :aria-current="tab.key === current ? 'page' : undefined"
            :class="[BASE, FOCUS_RING, tab.key === current ? SELECTED : IDLE]"
        >
            {{ tab.label }}
            <span v-if="tab.count !== undefined">({{ tab.count }})</span>
        </Link>
    </nav>
</template>
