<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppButton from '@/components/AppButton.vue';
import AppCard from '@/components/AppCard.vue';
import AppCheckbox from '@/components/AppCheckbox.vue';
import AppCheckboxGroup from '@/components/AppCheckboxGroup.vue';
import AppInput from '@/components/AppInput.vue';
import AppSelect from '@/components/AppSelect.vue';
import AppTextarea from '@/components/AppTextarea.vue';
import { useSaveScroll } from '@/composables/useSaveScroll';
import type { ProductEditable, ProductFormOptions } from '@/types/products';
import type { RouteDefinition } from '@/wayfinder';

// Fields and messages only. Every rule (unique name, active category, which mode admits a minimum
// or a custom color, services and locations that can be added) is applied by the backend, which
// also words the errors this form shows. Showing the minimum only in `stock_with_minimum` and the
// custom color only in `on_demand` is presentation: a field the mode does not admit is not sent.
const props = withDefaults(
    defineProps<{
        title: string;
        submitLabel: string;
        cancelHref: string;
        // `store()` or `update(id)` Wayfinder definition.
        action: RouteDefinition<'post'> | RouteDefinition<'put'>;
        options: ProductFormOptions;
        // Present when editing: the stored product.
        product?: ProductEditable;
        // Minimum proposed by the backend when registering (DEC-PRD-46).
        proposedMinimum?: number;
    }>(),
    { product: undefined, proposedMinimum: undefined },
);

const STOCK_WITH_MINIMUM = 'stock_with_minimum';
const ON_DEMAND = 'on_demand';

const stored = props.product;
const editing = stored !== undefined;

type FormData = {
    name: string;
    description: string;
    product_category_id: number | '';
    business_line: string;
    supply_mode: string;
    min_stock_default: string;
    allows_custom_color: boolean;
    portal_visible: boolean;
    detail_location_ids: number[];
    customization_ids: number[];
};

const form = useForm<FormData>({
    name: stored?.name ?? '',
    description: stored?.description ?? '',
    product_category_id: stored?.product_category_id ?? '',
    business_line: stored?.business_line ?? '',
    supply_mode: stored?.supply_mode ?? '',
    min_stock_default: String(
        stored?.min_stock_default ?? props.proposedMinimum ?? '',
    ),
    // Custom color defaults to on when the mode becomes `on_demand` (DEC-PRD-34): a product that
    // is in another mode stores false, which would otherwise show as an explicit refusal.
    allows_custom_color:
        stored?.supply_mode === ON_DEMAND ? stored.allows_custom_color : true,
    portal_visible: stored?.portal_visible ?? true,
    detail_location_ids: stored?.detail_location_ids ?? [],
    customization_ids: stored?.customization_ids ?? [],
});

const showsMinimum = computed(() => form.supply_mode === STOCK_WITH_MINIMUM);
const showsCustomColor = computed(() => form.supply_mode === ON_DEMAND);

// Only the fields the selected mode shows travel; the relations only exist when editing.
form.transform((data) => ({
    name: data.name,
    description: data.description,
    product_category_id: data.product_category_id,
    business_line: data.business_line,
    supply_mode: data.supply_mode,
    portal_visible: data.portal_visible,
    ...(data.supply_mode === STOCK_WITH_MINIMUM
        ? { min_stock_default: data.min_stock_default }
        : {}),
    ...(data.supply_mode === ON_DEMAND
        ? { allows_custom_color: data.allows_custom_color }
        : {}),
    ...(editing
        ? {
              detail_location_ids: data.detail_location_ids,
              customization_ids: data.customization_ids,
          }
        : {}),
}));

// Errors of the checkbox lists arrive keyed by position (`detail_location_ids.0`).
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
            <h1 class="font-headline-md text-headline-md text-primary">
                {{ title }}
            </h1>
        </AppCard>

        <AppCard>
            <h2 class="font-headline-sm text-headline-sm text-primary">
                Datos generales
            </h2>

            <AppInput
                v-model="form.name"
                label="Nombre"
                autocomplete="off"
                :maxlength="150"
                required
                :error="form.errors.name"
            />
            <AppTextarea
                v-model="form.description"
                label="Descripción"
                :maxlength="5000"
                :error="form.errors.description"
            />

            <AppSelect
                v-model="form.product_category_id"
                label="Categoría"
                placeholder="Seleccione la categoría"
                :options="options.categories"
                required
                :error="form.errors.product_category_id"
            />
            <div class="grid grid-cols-1 gap-space-md md:grid-cols-2">
                <AppSelect
                    v-model="form.business_line"
                    label="Línea de negocio"
                    placeholder="Seleccione la línea"
                    :options="options.lines"
                    required
                    :error="form.errors.business_line"
                />
                <AppSelect
                    v-model="form.supply_mode"
                    label="Modo de abastecimiento"
                    placeholder="Seleccione el modo"
                    :options="options.modes"
                    required
                    :error="form.errors.supply_mode"
                />
            </div>

            <AppInput
                v-if="showsMinimum"
                v-model="form.min_stock_default"
                label="Stock mínimo por defecto"
                type="number"
                inputmode="numeric"
                required
                hint="Lo hereda cada artículo que no tenga un mínimo propio."
                :error="form.errors.min_stock_default"
            />
            <AppCheckbox
                v-if="showsCustomColor"
                v-model="form.allows_custom_color"
                label="Admite color personalizado"
                :error="form.errors.allows_custom_color"
            />
            <AppCheckbox
                v-model="form.portal_visible"
                label="Visible en el portal"
                :error="form.errors.portal_visible"
            />
        </AppCard>

        <AppCard v-if="editing">
            <h2 class="font-headline-sm text-headline-sm text-primary">
                Detalles
            </h2>
            <AppCheckboxGroup
                v-model="form.detail_location_ids"
                label="Ubicaciones de detalle que admite"
                :options="options.detail_locations ?? []"
                filterable
                empty-text="No hay ubicaciones de detalle activas en el catálogo."
                :error="listError('detail_location_ids')"
            />
        </AppCard>

        <AppCard v-if="editing">
            <h2 class="font-headline-sm text-headline-sm text-primary">
                Personalizaciones
            </h2>
            <AppCheckboxGroup
                v-model="form.customization_ids"
                label="Personalizaciones que admite como extra"
                :options="options.customizations ?? []"
                filterable
                empty-text="No hay servicios activos en el catálogo."
                :error="listError('customization_ids')"
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
