<script setup lang="ts">
import SegmentedTabs from '@/components/SegmentedTabs.vue';
import type { SegmentedTab } from '@/components/SegmentedTabs.vue';
import { index as categoriesIndex } from '@/routes/catalog/categories';
import { index as locationsIndex } from '@/routes/catalog/detail-locations';

// Switcher between the catalog sections (every page of the catalog is gated by `products.catalog`).
// The attribute section is added with its page.
export type CatalogSection = 'categories' | 'detail-locations';

defineProps<{
    current: CatalogSection;
}>();

const TABS: SegmentedTab[] = [
    { key: 'categories', label: 'Categorías', href: categoriesIndex().url },
    {
        key: 'detail-locations',
        label: 'Ubicaciones de detalle',
        href: locationsIndex().url,
    },
];
</script>

<template>
    <SegmentedTabs
        :tabs="TABS"
        :current="current"
        label="Secciones del catálogo"
    />
</template>
