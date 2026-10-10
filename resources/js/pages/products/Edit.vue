<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import ProductForm from '@/components/products/ProductForm.vue';
import StockMinimumsEditor from '@/components/products/StockMinimumsEditor.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { show, update } from '@/routes/products';
import type {
    ProductEditable,
    ProductFormOptions,
    ProductStockEditor,
} from '@/types/products';

defineOptions({ layout: AppLayout });

// The form always sends the complete general data plus the admitted detail locations and
// customizations: `PUT` replaces them. `stock` only exists in the `stock_with_minimum` mode.
const props = defineProps<{
    product: ProductEditable;
    options: ProductFormOptions;
    stock: ProductStockEditor | null;
}>();
</script>

<template>
    <Head title="Editar producto" />

    <div class="mx-auto flex w-full max-w-2xl flex-col gap-space-md">
        <ProductForm
            title="Editar producto"
            submit-label="Guardar cambios"
            :cancel-href="show(props.product.id).url"
            :action="update(props.product.id)"
            :options="props.options"
            :product="props.product"
        />

        <StockMinimumsEditor
            v-if="props.stock !== null"
            :product-id="props.product.id"
            :stock="props.stock"
            :default-minimum="props.product.min_stock_default"
        />
    </div>
</template>
