<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppButton from '@/components/AppButton.vue';
import AppCard from '@/components/AppCard.vue';
import AppCheckboxGroup from '@/components/AppCheckboxGroup.vue';
import AppSelect from '@/components/AppSelect.vue';
import type { SelectOption } from '@/components/AppSelect.vue';
import ActionErrors from '@/components/catalog/ActionErrors.vue';
import IconButton from '@/components/IconButton.vue';
import { useSaveScroll } from '@/composables/useSaveScroll';
import { update } from '@/routes/products/attributes';
import type {
    ProductRole,
    ProductStructureAttribute,
    ProductStructureEntry,
} from '@/types/products';

// Edits the attributes of a product with their role, admitted values and axis order (PRD-004). The
// backend validates the whole list (one role per attribute, fabric as axis, color as order,
// active values, frozen structure) and words the errors; the editor only lists what the backend
// offers and sends the list back in the order shown.
const props = defineProps<{
    productId: number;
    catalog: ProductStructureAttribute[];
    structure: ProductStructureEntry[];
    hasCombinations: boolean;
}>();

const ROLE_LABEL: Record<ProductRole, string> = {
    axis: 'Eje',
    order: 'De pedido',
};
const ROLE_OPTIONS: SelectOption[] = [
    { value: 'axis', label: ROLE_LABEL.axis },
    { value: 'order', label: ROLE_LABEL.order },
];

const form = useForm<{ attributes: ProductStructureEntry[] }>({
    attributes: props.structure.map((entry) => ({
        ...entry,
        allowed_value_ids: [...entry.allowed_value_ids],
    })),
});

function attributeOf(id: number): ProductStructureAttribute | undefined {
    return props.catalog.find((attribute) => attribute.id === id);
}

// A color of a product that declares the fabric takes its options from the chosen fabric and keeps
// no values of its own (DEC-PRD-35): the picker is not shown and nothing is sent for it.
const declaresFabric = computed(() =>
    form.attributes.some((entry) => attributeOf(entry.attribute_id)?.is_fabric),
);

function followsFabric(entry: ProductStructureEntry): boolean {
    return (
        declaresFabric.value &&
        attributeOf(entry.attribute_id)?.is_color === true
    );
}

form.transform((data) => ({
    attributes: data.attributes.map((entry) => ({
        ...entry,
        allowed_value_ids: followsFabric(entry) ? [] : entry.allowed_value_ids,
    })),
}));

type Row = {
    entry: ProductStructureEntry;
    index: number;
    attribute: ProductStructureAttribute | undefined;
};

const rows = computed<Row[]>(() =>
    form.attributes.map((entry, index) => ({
        entry,
        index,
        attribute: attributeOf(entry.attribute_id),
    })),
);

const groups = computed(() => [
    {
        role: 'axis' as const,
        title: 'Ejes',
        hint: 'Definen la combinación comercial y su código. El orden es el de los pasos del cotizador.',
        empty: 'El producto no declara ejes.',
        rows: rows.value.filter((row) => row.entry.role === 'axis'),
    },
    {
        role: 'order' as const,
        title: 'Atributos de pedido',
        hint: 'Se eligen al cotizar o pedir y no cambian el código.',
        empty: 'El producto no declara atributos de pedido.',
        rows: rows.value.filter((row) => row.entry.role === 'order'),
    },
]);

const addable = computed<SelectOption[]>(() =>
    props.catalog
        .filter(
            (attribute) =>
                !form.attributes.some(
                    (entry) => entry.attribute_id === attribute.id,
                ),
        )
        .map((attribute) => ({ value: attribute.id, label: attribute.name })),
);

const toAdd = ref<string | number>('');

function addAttribute(): void {
    const attribute = attributeOf(Number(toAdd.value));

    if (toAdd.value === '' || attribute === undefined) {
        return;
    }

    form.attributes.push({
        attribute_id: attribute.id,
        role: attribute.fixed_role ?? 'axis',
        allowed_value_ids: [],
    });
    toAdd.value = '';
}

function removeAttribute(index: number): void {
    form.attributes.splice(index, 1);
    form.clearErrors();
}

function setRole(entry: ProductStructureEntry, value: string | number): void {
    entry.role = value === 'order' ? 'order' : 'axis';
}

// Swaps the axis with its neighbour among the axes; the other attributes keep their place.
function moveAxis(index: number, direction: 'up' | 'down'): void {
    const axes = rows.value.filter((row) => row.entry.role === 'axis');
    const position = axes.findIndex((row) => row.index === index);
    const neighbour = axes[position + (direction === 'up' ? -1 : 1)];

    if (neighbour === undefined) {
        return;
    }

    const list = form.attributes;
    [list[index], list[neighbour.index]] = [list[neighbour.index], list[index]];
    // Errors are keyed by position: after a move they would point at the wrong attribute.
    form.clearErrors();
}

function fieldError(index: number, field: string): string | undefined {
    return (form.errors as Record<string, string | undefined>)[
        `attributes.${index}.${field}`
    ];
}

const { saveOptions } = useSaveScroll();

function submit(): void {
    form.submit(update(props.productId), saveOptions);
}
</script>

<template>
    <form
        class="mx-auto flex w-full max-w-3xl flex-col gap-space-md"
        novalidate
        @submit.prevent="submit"
    >
        <p
            v-if="hasCombinations"
            class="rounded-lg bg-surface-container-low p-space-md font-body-md text-body-md text-on-surface-variant"
        >
            El producto ya tiene combinaciones: no se puede agregar un eje,
            cambiar el rol de un atributo ni retirar un eje. Si el cambio no es
            posible, el sistema indica qué lo impide.
        </p>

        <ActionErrors
            :errors="{
                attributes: form.errors.attributes,
            }"
        />

        <AppCard v-for="group in groups" :key="group.role">
            <h2 class="font-headline-sm text-headline-sm text-primary">
                {{ group.title }}
            </h2>
            <p class="font-body-md text-body-md text-on-surface-variant">
                {{ group.hint }}
            </p>
            <p
                v-if="group.rows.length === 0"
                class="font-body-md text-body-md text-on-surface"
            >
                {{ group.empty }}
            </p>

            <div
                v-for="(row, position) in group.rows"
                :key="row.entry.attribute_id"
                class="flex flex-col gap-space-sm rounded-lg bg-surface-container-low p-space-md"
            >
                <div class="flex items-center justify-between gap-space-sm">
                    <h3 class="font-label-lg text-label-lg text-on-surface">
                        {{ row.attribute?.name ?? 'Atributo no disponible' }}
                    </h3>
                    <div
                        v-if="group.role === 'axis'"
                        class="flex items-center gap-space-xs"
                    >
                        <IconButton
                            icon="arrow_upward"
                            variant="reorder"
                            :label="`Subir ${row.attribute?.name ?? 'atributo'}`"
                            :disabled="position === 0"
                            @click="moveAxis(row.index, 'up')"
                        />
                        <IconButton
                            icon="arrow_downward"
                            variant="reorder"
                            :label="`Bajar ${row.attribute?.name ?? 'atributo'}`"
                            :disabled="position === group.rows.length - 1"
                            @click="moveAxis(row.index, 'down')"
                        />
                    </div>
                </div>

                <AppSelect
                    v-if="row.attribute?.fixed_role == null"
                    :model-value="row.entry.role"
                    label="Rol"
                    :options="ROLE_OPTIONS"
                    :error="fieldError(row.index, 'role')"
                    @update:model-value="setRole(row.entry, $event)"
                />
                <p
                    v-else
                    class="font-body-md text-body-md text-on-surface-variant"
                >
                    Rol: {{ ROLE_LABEL[row.attribute.fixed_role] }} (fijo para
                    este atributo)
                </p>
                <p
                    v-if="fieldError(row.index, 'attribute_id')"
                    role="alert"
                    class="font-body-sm text-body-sm text-error"
                >
                    {{ fieldError(row.index, 'attribute_id') }}
                </p>

                <p
                    v-if="followsFabric(row.entry)"
                    class="font-body-md text-body-md text-on-surface-variant"
                >
                    Los colores salen de la tela elegida.
                </p>
                <AppCheckboxGroup
                    v-else
                    v-model="row.entry.allowed_value_ids"
                    :label="`Valores admitidos de ${row.attribute?.name ?? 'atributo'}`"
                    :options="row.attribute?.values ?? []"
                    filterable
                    empty-text="El atributo no tiene valores activos."
                    :error="fieldError(row.index, 'allowed_value_ids')"
                />

                <div>
                    <AppButton
                        variant="outlined"
                        @click="removeAttribute(row.index)"
                    >
                        Quitar atributo
                    </AppButton>
                </div>
            </div>
        </AppCard>

        <AppCard>
            <h2 class="font-headline-sm text-headline-sm text-primary">
                Agregar atributo
            </h2>
            <AppSelect
                v-model="toAdd"
                label="Atributo del catálogo"
                placeholder="Seleccione un atributo"
                :options="addable"
            />
            <div>
                <AppButton
                    variant="secondary"
                    :disabled="toAdd === ''"
                    @click="addAttribute"
                >
                    Agregar atributo
                </AppButton>
            </div>
        </AppCard>

        <div class="flex flex-col gap-space-sm md:flex-row">
            <AppButton type="submit" :disabled="form.processing">
                Guardar estructura
            </AppButton>
            <slot name="cancel" />
        </div>
    </form>
</template>
