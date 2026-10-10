<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import ComboForm from '@/components/products/ComboForm.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { show, update } from '@/routes/combos';
import type {
    ComboFields,
    ComboProductOption,
    ComboSubject,
} from '@/types/products';

defineOptions({ layout: AppLayout });

// The form always sends the complete combo: `PUT` replaces its components and their values.
const props = defineProps<{
    combo: ComboSubject;
    products: ComboProductOption[];
    initial: ComboFields;
}>();
</script>

<template>
    <Head :title="`Editar combo ${props.combo.name}`" />

    <ComboForm
        :title="`Editar combo ${props.combo.name}`"
        submit-label="Guardar cambios"
        :cancel-href="show(props.combo.id).url"
        :action="update(props.combo.id)"
        :products="props.products"
        :initial="props.initial"
    />
</template>
