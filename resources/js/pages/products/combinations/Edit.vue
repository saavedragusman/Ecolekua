<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import CombinationForm from '@/components/products/CombinationForm.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { show } from '@/routes/products';
import { update } from '@/routes/products/combinations';
import type {
    CombinationAttribute,
    CombinationEditable,
    CombinationSubject,
    ProductFormOption,
} from '@/types/products';

defineOptions({ layout: AppLayout });

// The form always sends the complete combination: `PUT` replaces its axes, restrictions and
// included services.
const props = defineProps<{
    product: CombinationSubject;
    axes: CombinationAttribute[];
    order_attributes: CombinationAttribute[];
    services: ProductFormOption<number>[];
    combination: CombinationEditable;
}>();
</script>

<template>
    <Head :title="`Editar combinación ${props.combination.code}`" />

    <CombinationForm
        :title="`Editar combinación ${props.combination.code} de ${props.product.name}`"
        submit-label="Guardar cambios"
        :cancel-href="show(props.product.id).url"
        back-label="Volver al producto"
        :action="
            update({
                product: props.product.id,
                combination: props.combination.id,
            })
        "
        :axes="props.axes"
        :order-attributes="props.order_attributes"
        :services="props.services"
        :initial="props.combination"
    />
</template>
