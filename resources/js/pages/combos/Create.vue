<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import ComboForm from '@/components/products/ComboForm.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { index, store } from '@/routes/combos';
import type { ComboFields, ComboProductOption } from '@/types/products';

defineOptions({ layout: AppLayout });

defineProps<{
    products: ComboProductOption[];
    initial: ComboFields;
}>();
</script>

<template>
    <Head title="Nuevo combo" />

    <ComboForm
        title="Nuevo combo"
        submit-label="Registrar combo"
        :cancel-href="index().url"
        :action="store()"
        :products="products"
        :initial="initial"
    />
</template>
