<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import AppButton from '@/components/AppButton.vue';
import AppCard from '@/components/AppCard.vue';
import AppCheckboxGroup from '@/components/AppCheckboxGroup.vue';
import AppIcon from '@/components/AppIcon.vue';
import AppInput from '@/components/AppInput.vue';
import AppTextarea from '@/components/AppTextarea.vue';
import ActionErrors from '@/components/catalog/ActionErrors.vue';
import { useSaveScroll } from '@/composables/useSaveScroll';
import { FOCUS_RING } from '@/lib/ui';
import type {
    CombinationAttribute,
    CombinationFields,
    CombinationValueMap,
    ProductFormOption,
} from '@/types/products';
import type { RouteDefinition } from '@/wayfinder';

// Fields and messages only. The backend applies every rule (unique code, a value for each axis,
// values the product admits, restrictions only on order attributes, no overlap with another active
// combination, services that can be included) and words the errors this form shows.
const props = defineProps<{
    title: string;
    submitLabel: string;
    cancelHref: string;
    backLabel: string;
    // `store(product)` or `update({ product, combination })` Wayfinder definition.
    action: RouteDefinition<'post'> | RouteDefinition<'put'>;
    axes: CombinationAttribute[];
    orderAttributes: CombinationAttribute[];
    services: ProductFormOption<number>[];
    // The product is of the diaper line: its combinations include no customizations (DEC-PRD-98).
    diaper: boolean;
    // Stored combination, or the pre-filled values of a duplicate.
    initial: CombinationFields;
}>();

// A map arrives as an empty array when it has no entries (PHP encoding): copy it into a plain
// record so each checkbox list owns its array.
function copyMap(map: CombinationValueMap): CombinationValueMap {
    const copy: CombinationValueMap = {};

    for (const [attributeId, valueIds] of Object.entries(map)) {
        copy[Number(attributeId)] = [...valueIds];
    }

    return copy;
}

const form = useForm<CombinationFields>({
    code: props.initial.code,
    description: props.initial.description,
    axes: copyMap(props.initial.axes),
    restrictions: copyMap(props.initial.restrictions),
    included_customization_ids: [...props.initial.included_customization_ids],
});

// The included customizations only travel when the section is shown.
form.transform((data) => {
    const { included_customization_ids: included, ...rest } = data;

    return props.diaper
        ? rest
        : { ...rest, included_customization_ids: included };
});

function valuesOf(map: CombinationValueMap, attributeId: number): number[] {
    return map[attributeId] ?? [];
}

function setValues(
    map: CombinationValueMap,
    attributeId: number,
    value: Array<string | number>,
): void {
    map[attributeId] = value.map(Number);
}

// Errors of the lists arrive keyed by attribute (`axes.12`) or by position (`axes.12.0`,
// `included_customization_ids.0`).
function listError(field: string): string | undefined {
    const errors = form.errors as Record<string, string | undefined>;
    const key = Object.keys(errors).find(
        (name) => name === field || name.startsWith(`${field}.`),
    );

    return key === undefined ? undefined : errors[key];
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

        <ActionErrors :errors="{ axes: form.errors.axes }" />

        <AppCard>
            <h2 class="font-headline-sm text-headline-sm text-primary">
                Código
            </h2>
            <AppInput
                v-model="form.code"
                label="Código"
                autocomplete="off"
                :maxlength="30"
                required
                hint="Se guarda tal como lo escribe, sin espacios."
                :error="form.errors.code"
            />
            <AppTextarea
                v-model="form.description"
                label="Descripción"
                :maxlength="255"
                :rows="2"
                :error="form.errors.description"
            />
        </AppCard>

        <AppCard>
            <h2 class="font-headline-sm text-headline-sm text-primary">Ejes</h2>
            <p class="font-body-md text-body-md text-on-surface-variant">
                Elija al menos un valor por eje. Los valores que no se ofrecen
                no están admitidos por el producto.
            </p>
            <p
                v-if="axes.length === 0"
                class="font-body-md text-body-md text-on-surface"
            >
                El producto no declara ejes.
            </p>
            <AppCheckboxGroup
                v-for="axis in axes"
                :key="axis.id"
                :model-value="valuesOf(form.axes, axis.id)"
                :label="axis.name"
                :options="axis.values"
                filterable
                empty-text="El producto no admite valores para este eje."
                :error="listError(`axes.${axis.id}`)"
                @update:model-value="setValues(form.axes, axis.id, $event)"
            />
        </AppCard>

        <AppCard v-if="orderAttributes.length > 0">
            <h2 class="font-headline-sm text-headline-sm text-primary">
                Restricciones
            </h2>
            <p class="font-body-md text-body-md text-on-surface-variant">
                Sin selección, la combinación admite todos los valores del
                atributo. Marque solo los que quiera limitar.
            </p>
            <AppCheckboxGroup
                v-for="attribute in orderAttributes"
                :key="attribute.id"
                :model-value="valuesOf(form.restrictions, attribute.id)"
                :label="`Restringir ${attribute.name}`"
                :options="attribute.values"
                filterable
                :error="listError(`restrictions.${attribute.id}`)"
                @update:model-value="
                    setValues(form.restrictions, attribute.id, $event)
                "
            />
        </AppCard>

        <AppCard v-if="!diaper">
            <h2 class="font-headline-sm text-headline-sm text-primary">
                Personalizaciones incluidas
            </h2>
            <AppCheckboxGroup
                v-model="form.included_customization_ids"
                label="Servicios que el precio ya cubre"
                :options="services"
                filterable
                empty-text="No hay servicios activos en el catálogo."
                :error="listError('included_customization_ids')"
            />
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
