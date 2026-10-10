<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import CombinationForm from '@/components/products/CombinationForm.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { show } from '@/routes/products';
import { store } from '@/routes/products/combinations';
import type {
    CombinationAttribute,
    CombinationFields,
    CombinationSubject,
    ProductFormOption,
} from '@/types/products';

defineOptions({ layout: AppLayout });

// `initial` is empty, or the values of the combination being duplicated without its code.
defineProps<{
    product: CombinationSubject;
    axes: CombinationAttribute[];
    order_attributes: CombinationAttribute[];
    services: ProductFormOption<number>[];
    initial: CombinationFields;
}>();
</script>

<template>
    <Head :title="`Nueva combinación de ${product.name}`" />

    <CombinationForm
        :title="`Nueva combinación de ${product.name}`"
        submit-label="Registrar combinación"
        :cancel-href="show(product.id).url"
        :action="store(product.id)"
        :axes="axes"
        :order-attributes="order_attributes"
        :services="services"
        :initial="initial"
    />
</template>
