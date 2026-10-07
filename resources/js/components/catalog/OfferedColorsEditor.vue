<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppButton from '@/components/AppButton.vue';
import AppCard from '@/components/AppCard.vue';
import AppCheckboxGroup from '@/components/AppCheckboxGroup.vue';
import type { CheckboxGroupOption } from '@/components/AppCheckboxGroup.vue';
import { update } from '@/routes/catalog/values/offered-colors';
import type { CatalogColorRef } from '@/types/products';

// Edits the colors Ecolekua offers in one fabric value (PRD-002, E-46). The palette holds the active
// colors; a color already offered that was deactivated since stays listed so it is not dropped by
// accident. The backend decides which additions are valid.
const props = defineProps<{
    valueId: number;
    valueName: string;
    palette: CatalogColorRef[];
    offered: CatalogColorRef[];
}>();

const emit = defineEmits<{
    done: [];
    cancel: [];
}>();

const form = useForm<{ color_ids: number[] }>({
    color_ids: props.offered.map((color) => color.id),
});

const options = computed<CheckboxGroupOption[]>(() => {
    const listed = new Set(props.palette.map((color) => color.id));
    const stale = props.offered.filter((color) => !listed.has(color.id));

    return [...props.palette, ...stale].map((color) => ({
        value: color.id,
        label:
            color.status === 'active' ? color.name : `${color.name} (inactivo)`,
    }));
});

function submit(): void {
    form.submit(update(props.valueId), {
        preserveScroll: true,
        onSuccess: () => emit('done'),
    });
}
</script>

<template>
    <form novalidate @submit.prevent="submit">
        <AppCard>
            <h2 class="font-headline-sm text-headline-sm text-primary">
                Colores ofrecidos: {{ valueName }}
            </h2>
            <AppCheckboxGroup
                v-model="form.color_ids"
                label="Colores disponibles con esta tela"
                :options="options"
                filterable
                empty-text="No hay colores activos en el catálogo."
                :error="form.errors.color_ids"
            />
            <div class="flex flex-col gap-space-sm md:flex-row">
                <AppButton type="submit" :disabled="form.processing">
                    Guardar colores
                </AppButton>
                <AppButton variant="secondary" @click="emit('cancel')">
                    Cancelar
                </AppButton>
            </div>
        </AppCard>
    </form>
</template>
