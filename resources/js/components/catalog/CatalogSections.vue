<script setup lang="ts">
import SegmentedTabs from '@/components/SegmentedTabs.vue';
import type { SegmentedTab } from '@/components/SegmentedTabs.vue';
import { index as attributesIndex } from '@/routes/catalog/attributes';
import { index as categoriesIndex } from '@/routes/catalog/categories';
import { index as locationsIndex } from '@/routes/catalog/detail-locations';

// Switcher between the catalog sections (every page of the catalog is gated by `products.catalog`).
export type CatalogSection = 'categories' | 'attributes' | 'detail-locations';

defineProps<{
    current: CatalogSection;
}>();

const TABS: SegmentedTab[] = [
    { key: 'categories', label: 'Categorías', href: categoriesIndex().url },
    { key: 'attributes', label: 'Atributos', href: attributesIndex().url },
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
