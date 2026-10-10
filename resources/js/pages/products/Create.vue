<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import ProductForm from '@/components/products/ProductForm.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { index, store } from '@/routes/products';
import type { ProductFormDefaults, ProductFormOptions } from '@/types/products';

defineOptions({ layout: AppLayout });

// Options are labelled by the backend, which also proposes the default minimum (DEC-PRD-46).
defineProps<{
    options: ProductFormOptions;
    defaults: ProductFormDefaults;
}>();
</script>

<template>
    <Head title="Nuevo producto" />

    <ProductForm
        title="Nuevo producto"
        submit-label="Registrar producto"
        :cancel-href="index().url"
        :action="store()"
        :options="options"
        :proposed-minimum="defaults.min_stock_default"
    />
</template>
