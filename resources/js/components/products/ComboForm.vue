<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppButton from '@/components/AppButton.vue';
import AppCard from '@/components/AppCard.vue';
import AppCheckbox from '@/components/AppCheckbox.vue';
import AppCheckboxGroup from '@/components/AppCheckboxGroup.vue';
import AppIcon from '@/components/AppIcon.vue';
import AppInput from '@/components/AppInput.vue';
import AppSelect from '@/components/AppSelect.vue';
import type { SelectOption } from '@/components/AppSelect.vue';
import ActionErrors from '@/components/catalog/ActionErrors.vue';
import { useSaveScroll } from '@/composables/useSaveScroll';
import { FOCUS_RING } from '@/lib/ui';
import type {
    CombinationValueMap,
    ComboFields,
    ComboProductOption,
} from '@/types/products';
import type { RouteDefinition } from '@/wayfinder';

// Fields and messages only. The backend applies every rule (unique name and code, products of the
// diaper line that are not services, active products, a quantity between 1 and 999, the values each
// product admits) and words the errors this form shows. The product list and the values each
// product offers come already resolved from the backend.
const props = defineProps<{
    title: string;
    submitLabel: string;
    cancelHref: string;
    backLabel: string;
    // `store()` or `update(id)` Wayfinder definition.
    action: RouteDefinition<'post'> | RouteDefinition<'put'>;
    products: ComboProductOption[];
    // Stored combo, or the empty form.
    initial: ComboFields;
}>();

// A row keeps a local key so adding and removing components never reuses the state of another one.
type Row = {
    key: number;
    // The stored component the row edits; null for a component added in this form.
    id: number | null;
    product_id: number | '';
    quantity: string;
    values: CombinationValueMap;
};

type FormData = {
    name: string;
    code: string;
    portal_visible: boolean;
    components: Row[];
};

let nextKey = 0;

// A map arrives as an empty array when it has no entries (PHP encoding): copy it into a plain
// record so each checkbox list owns its array.
function copyMap(map: CombinationValueMap): CombinationValueMap {
    const copy: CombinationValueMap = {};

    for (const [attributeId, valueIds] of Object.entries(map)) {
        copy[Number(attributeId)] = [...valueIds];
    }

    return copy;
}

function blankRow(): Row {
    return {
        key: nextKey++,
        id: null,
        product_id: '',
        quantity: '1',
        values: {},
    };
}

const form = useForm<FormData>({
    name: props.initial.name,
    code: props.initial.code,
    portal_visible: props.initial.portal_visible,
    components:
        props.initial.components.length > 0
            ? props.initial.components.map((component) => ({
                  key: nextKey++,
                  id: component.id,
                  product_id: component.product_id,
                  quantity: String(component.quantity),
                  values: copyMap(component.values),
              }))
            : [blankRow()],
});

// Only the data the backend expects travels: no local key and no attribute left without a value.
form.transform((data) => ({
    name: data.name,
    code: data.code,
    portal_visible: data.portal_visible,
    components: data.components.map((row) => ({
        id: row.id,
        product_id: row.product_id,
        quantity: row.quantity,
        values: Object.fromEntries(
            Object.entries(row.values).filter(
                ([, valueIds]) => valueIds.length > 0,
            ),
        ),
    })),
}));

const productOptions = computed<SelectOption[]>(() =>
    props.products.map((product) => ({
        value: product.id,
        label: product.name,
    })),
);

function attributesOf(row: Row): ComboProductOption['attributes'] {
    return (
        props.products.find((product) => product.id === row.product_id)
            ?.attributes ?? []
    );
}

// A new product offers other values: the previous restrictions would not apply to it.
function chooseProduct(row: Row, value: string | number): void {
    row.product_id = value === '' ? '' : Number(value);
    row.values = {};
}

function setValues(
    row: Row,
    attributeId: number,
    value: Array<string | number>,
): void {
    row.values[attributeId] = value.map(Number);
}

function addComponent(): void {
    form.components.push(blankRow());
}

function removeComponent(index: number): void {
    form.components.splice(index, 1);
}

// Errors arrive keyed by position (`components.0.quantity`, `components.0.values.12`).
function fieldError(index: number, field: string): string | undefined {
    const errors = form.errors as Record<string, string | undefined>;

    return errors[`components.${index}.${field}`];
}

const { saveOptions } = useSaveScroll();

function submit(): void {
    form.submit(props.action, saveOptions);
}
</script>

<template>
    <form
        class="mx-auto flex w-full max-w-2xl flex-col gap-space-md"
        novalidate
        @submit.prevent="submit"
    >
        <AppCard>
            <div class="flex items-center gap-space-sm">
                <Link
                    :href="cancelHref"
                    :aria-label="backLabel"
                    :title="backLabel"
                    :class="[
                        'inline-flex size-11 shrink-0 items-center justify-center rounded-full text-primary transition-colors hover:bg-surface-container-low',
                        FOCUS_RING,
                    ]"
                >
                    <AppIcon name="arrow_back" />
                </Link>
                <h1 class="font-headline-md text-headline-md text-primary">
                    {{ title }}
                </h1>
            </div>
        </AppCard>

        <AppCard>
            <h2 class="font-headline-sm text-headline-sm text-primary">
                Datos del combo
            </h2>
            <AppInput
                v-model="form.name"
                label="Nombre"
                autocomplete="off"
                :maxlength="150"
                required
                :error="form.errors.name"
            />
            <AppInput
                v-model="form.code"
                label="Código"
                autocomplete="off"
                :maxlength="30"
                required
                hint="Se guarda tal como lo escribe, sin espacios."
                :error="form.errors.code"
            />
            <AppCheckbox
                v-model="form.portal_visible"
                label="Visible en el portal"
                :error="form.errors.portal_visible"
            />
        </AppCard>

        <ActionErrors :errors="{ components: form.errors.components }" />

        <AppCard>
            <h2 class="font-headline-sm text-headline-sm text-primary">
                Componentes
            </h2>
            <p class="font-body-md text-body-md text-on-surface-variant">
                Cada componente es un producto de la línea de pañales con la
                cantidad que incluye el combo. Sin selección en un atributo, el
                componente admite todos sus valores.
            </p>

            <div
                v-for="(row, index) in form.components"
                :key="row.key"
                class="flex flex-col gap-space-md rounded-xl border border-outline-variant p-space-md"
            >
                <h3 class="font-label-md text-label-md text-on-surface-variant">
                    Componente {{ index + 1 }}
                </h3>

                <AppSelect
                    :model-value="row.product_id"
                    :label="`Producto del componente ${index + 1}`"
                    placeholder="Seleccione el producto"
                    :options="productOptions"
                    required
                    :error="fieldError(index, 'product_id')"
                    @update:model-value="chooseProduct(row, $event)"
                />
                <AppInput
                    v-model="row.quantity"
                    :label="`Cantidad del componente ${index + 1}`"
                    type="number"
                    inputmode="numeric"
                    required
                    :error="fieldError(index, 'quantity')"
                />
                <AppCheckboxGroup
                    v-for="attribute in attributesOf(row)"
                    :key="attribute.id"
                    :model-value="row.values[attribute.id] ?? []"
                    :label="`${attribute.name} que admite el componente ${index + 1}`"
                    :options="attribute.values"
                    filterable
                    :error="fieldError(index, `values.${attribute.id}`)"
                    @update:model-value="setValues(row, attribute.id, $event)"
                />

                <div>
                    <AppButton
                        variant="outlined"
                        :disabled="form.components.length === 1"
                        @click="removeComponent(index)"
                    >
                        Quitar componente
                    </AppButton>
                </div>
            </div>

            <div>
                <AppButton variant="outlined" @click="addComponent">
                    Agregar componente
                </AppButton>
            </div>
        </AppCard>

        <div class="flex flex-col gap-space-sm md:flex-row">
            <AppButton type="submit" :disabled="form.processing">
                {{ submitLabel }}
            </AppButton>
            <AppButton variant="outlined" :href="cancelHref">
                Cancelar
            </AppButton>
        </div>
    </form>
</template>
