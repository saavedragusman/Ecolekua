<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import AppButton from '@/components/AppButton.vue';
import AppCard from '@/components/AppCard.vue';
import AppIcon from '@/components/AppIcon.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import StructureEditor from '@/components/products/StructureEditor.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { FOCUS_RING } from '@/lib/ui';
import { show } from '@/routes/products';
import type {
    ProductStructureAttribute,
    ProductStructureEntry,
    ProductStructureSubject,
} from '@/types/products';

defineOptions({ layout: AppLayout });

// The structure arrives ready to be sent back: attributes in display order with their admitted
// value ids. The catalog lists what the product can declare, labelled by the backend.
defineProps<{
    product: ProductStructureSubject;
    structure: ProductStructureEntry[];
    catalog: ProductStructureAttribute[];
}>();
</script>

<template>
    <Head :title="`Estructura de ${product.name}`" />

    <div class="mx-auto flex w-full max-w-3xl flex-col gap-space-md">
        <AppCard>
            <div
                class="flex flex-col gap-space-sm md:flex-row md:items-center md:justify-between"
            >
                <div class="flex items-center gap-space-sm">
                    <Link
                        :href="show(product.id).url"
                        aria-label="Volver al producto"
                        title="Volver al producto"
                        :class="[
                            'inline-flex size-11 shrink-0 items-center justify-center rounded-full text-primary transition-colors hover:bg-surface-container-low',
                            FOCUS_RING,
                        ]"
                    >
                        <AppIcon name="arrow_back" />
                    </Link>
                    <h1 class="font-headline-md text-headline-md text-primary">
                        Estructura de {{ product.name }}
                    </h1>
                </div>
                <StatusBadge
                    :category="product.status === 'active' ? 'done' : 'neutral'"
                    :label="product.status_label"
                />
            </div>
        </AppCard>

        <StructureEditor
            :product-id="product.id"
            :catalog="catalog"
            :structure="structure"
            :has-combinations="product.has_combinations"
        >
            <template #cancel>
                <AppButton variant="outlined" :href="show(product.id).url">
                    Volver al producto
                </AppButton>
            </template>
        </StructureEditor>
    </div>
</template>
