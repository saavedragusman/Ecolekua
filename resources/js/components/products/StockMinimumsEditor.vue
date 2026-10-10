<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppButton from '@/components/AppButton.vue';
import AppCard from '@/components/AppCard.vue';
import AppInput from '@/components/AppInput.vue';
import AppSelect from '@/components/AppSelect.vue';
import type { SelectOption } from '@/components/AppSelect.vue';
import { update } from '@/routes/products/stock-minimums';
import type { ProductStockEditor } from '@/types/products';

// Own minimum stock per article (PRD-009, DEC-PRD-46): the set replaces the stored one. The backend
// decides which combination and size are valid and words the errors; this editor only lists the
// articles and the sizes each combination admits, as the backend sent them.
const props = defineProps<{
    productId: number;
    stock: ProductStockEditor;
    // Minimum every article without its own value inherits.
    defaultMinimum: number | null;
}>();

type Row = {
    combination_id: number | '';
    size_value_id: number | '';
    minimum: string;
};

const form = useForm<{ overrides: Row[] }>({
    overrides: props.stock.overrides.map((override) => ({
        combination_id: override.combination_id,
        size_value_id: override.size_value_id ?? '',
        minimum: String(override.minimum),
    })),
});

// An empty size means "no size" (the product does not declare one).
form.transform((data) => ({
    overrides: data.overrides.map((row) => ({
        combination_id: row.combination_id,
        size_value_id: row.size_value_id === '' ? null : row.size_value_id,
        minimum: row.minimum,
    })),
}));

const articleOptions = computed<SelectOption[]>(() =>
    props.stock.combinations.map((article) => ({
        value: article.id,
        label:
            article.code === null
                ? article.name
                : `${article.code} · ${article.name}`,
    })),
);

function sizeOptions(row: Row): SelectOption[] {
    return (
        props.stock.combinations.find(
            (article) => article.id === row.combination_id,
        )?.sizes ?? []
    );
}

function fieldError(index: number, field: string): string | undefined {
    return (form.errors as Record<string, string | undefined>)[
        `overrides.${index}.${field}`
    ];
}

function addRow(): void {
    form.overrides.push({ combination_id: '', size_value_id: '', minimum: '' });
}

function removeRow(index: number): void {
    form.overrides.splice(index, 1);
    form.clearErrors();
}

// Sizes depend on the combination chosen, so a previous size no longer applies.
function changeArticle(row: Row, value: string | number): void {
    row.combination_id = value === '' ? '' : Number(value);
    row.size_value_id = '';
}

function changeSize(row: Row, value: string | number): void {
    row.size_value_id = value === '' ? '' : Number(value);
}

function submit(): void {
    form.submit(update(props.productId), { preserveScroll: true });
}
</script>

<template>
    <form novalidate @submit.prevent="submit">
        <AppCard>
            <h2 class="font-headline-sm text-headline-sm text-primary">
                Stock mínimo por artículo
            </h2>
            <p class="font-body-md text-body-md text-on-surface-variant">
                Los artículos que no aparecen aquí usan el mínimo por
                defecto<template v-if="defaultMinimum !== null">
                    ({{ defaultMinimum }})</template
                >.
            </p>

            <p
                v-if="stock.combinations.length === 0"
                class="font-body-md text-body-md text-on-surface"
            >
                El producto aún no tiene combinaciones.
            </p>
            <template v-else>
                <p
                    v-if="form.errors.overrides"
                    role="alert"
                    class="font-body-sm text-body-sm text-error"
                >
                    {{ form.errors.overrides }}
                </p>
                <p
                    v-if="form.overrides.length === 0"
                    class="font-body-md text-body-md text-on-surface"
                >
                    Sin mínimos propios.
                </p>

                <div
                    v-for="(row, index) in form.overrides"
                    :key="index"
                    class="flex flex-col gap-space-sm rounded-lg bg-surface-container-low p-space-md"
                >
                    <AppSelect
                        :model-value="row.combination_id"
                        label="Combinación"
                        placeholder="Seleccione la combinación"
                        :options="articleOptions"
                        required
                        :error="fieldError(index, 'combination_id')"
                        @update:model-value="changeArticle(row, $event)"
                    />
                    <AppSelect
                        v-if="stock.size_attribute !== null"
                        :model-value="row.size_value_id"
                        :label="stock.size_attribute"
                        placeholder="Seleccione"
                        :options="sizeOptions(row)"
                        required
                        :error="fieldError(index, 'size_value_id')"
                        @update:model-value="changeSize(row, $event)"
                    />
                    <AppInput
                        v-model="row.minimum"
                        label="Mínimo propio"
                        type="number"
                        inputmode="numeric"
                        required
                        :error="fieldError(index, 'minimum')"
                    />
                    <div>
                        <AppButton variant="outlined" @click="removeRow(index)">
                            Quitar
                        </AppButton>
                    </div>
                </div>

                <div class="flex flex-col gap-space-sm md:flex-row">
                    <AppButton variant="secondary" @click="addRow">
                        Agregar mínimo propio
                    </AppButton>
                    <AppButton type="submit" :disabled="form.processing">
                        Guardar mínimos
                    </AppButton>
                </div>
            </template>
        </AppCard>
    </form>
</template>
