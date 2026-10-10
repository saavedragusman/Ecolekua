<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref, useTemplateRef } from 'vue';
import AppButton from '@/components/AppButton.vue';
import ActionErrors from '@/components/catalog/ActionErrors.vue';
import CatalogSections from '@/components/catalog/CatalogSections.vue';
import OfferedColorsEditor from '@/components/catalog/OfferedColorsEditor.vue';
import ValueForm from '@/components/catalog/ValueForm.vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import DataTable from '@/components/DataTable.vue';
import type { DataTableColumn } from '@/components/DataTable.vue';
import IconButton from '@/components/IconButton.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { useScrollToForm } from '@/composables/useScrollToForm';
import AppLayout from '@/layouts/AppLayout.vue';
import { index } from '@/routes/catalog/attributes';
import { activate, deactivate, move } from '@/routes/catalog/values';
import type {
    CatalogAttribute,
    CatalogAttributeValue,
    CatalogCan,
    CatalogColorRef,
} from '@/types/products';

defineOptions({ layout: AppLayout });

// Values arrive in display order with their labels resolved (PRD-002). On the fabric attribute each
// value lists the colors offered with it and the page receives the active palette to choose from.
// The controls only reflect `can`; the backend authorizes and validates every write.
const props = defineProps<{
    attribute: CatalogAttribute;
    values: CatalogAttributeValue[];
    palette: CatalogColorRef[];
    can: CatalogCan;
}>();

const isColor = computed(() => props.attribute.presentation === 'color');
const isFabric = computed(() => props.attribute.special_use === 'fabric');

const columns = computed<DataTableColumn[]>(() => [
    { key: 'name', label: 'Nombre' },
    ...(isColor.value ? [{ key: 'tone', label: 'Tono' }] : []),
    { key: 'svg_layer', label: 'Capa del SVG' },
    ...(isFabric.value
        ? [{ key: 'offered_colors', label: 'Colores ofrecidos' }]
        : []),
    { key: 'status', label: 'Estado' },
    ...(props.can.manage ? [{ key: 'actions', label: 'Acciones' }] : []),
]);

// At most one panel is open: the value form (creation or edition) or the offered colors editor.
const formOpen = ref(false);
const editing = ref<CatalogAttributeValue | null>(null);
const editingColors = ref<CatalogAttributeValue | null>(null);
const valueForm = useTemplateRef('valueForm');
const colorsEditor = useTemplateRef('colorsEditor');
const { scrollToForm: scrollToValueForm } = useScrollToForm(valueForm);
const { scrollToForm: scrollToColorsEditor } = useScrollToForm(colorsEditor);

function openCreate(): void {
    editingColors.value = null;
    editing.value = null;
    formOpen.value = true;
}

function openEdit(value: CatalogAttributeValue): void {
    editingColors.value = null;
    editing.value = value;
    formOpen.value = true;
    void scrollToValueForm();
}

function openColors(value: CatalogAttributeValue): void {
    formOpen.value = false;
    editing.value = null;
    editingColors.value = value;
    void scrollToColorsEditor();
}

function closePanels(): void {
    formOpen.value = false;
    editing.value = null;
    editingColors.value = null;
}

const moveForm = useForm<{ direction: 'up' | 'down' }>({ direction: 'up' });
const statusForm = useForm({});

function position(value: CatalogAttributeValue): number {
    return props.values.findIndex((row) => row.id === value.id);
}

function moveValue(
    value: CatalogAttributeValue,
    direction: 'up' | 'down',
): void {
    moveForm.direction = direction;
    moveForm.clearErrors();
    moveForm.submit(move(value.id), { preserveScroll: true });
}

// Deactivating asks for confirmation (design-system §7.10); reactivating is reversible and does not.
// A rejection closes the dialog so the backend message shows on the page.
const deactivating = ref<CatalogAttributeValue | null>(null);
const confirmingDeactivate = computed({
    get: () => deactivating.value !== null,
    set: (open: boolean) => {
        if (!open) {
            deactivating.value = null;
        }
    },
});

function deactivateValue(): void {
    const target = deactivating.value;

    if (target === null) {
        return;
    }

    statusForm.clearErrors();
    statusForm.submit(deactivate(target.id), {
        preserveScroll: true,
        onFinish: () => {
            deactivating.value = null;
        },
    });
}

function reactivateValue(value: CatalogAttributeValue): void {
    statusForm.clearErrors();
    statusForm.submit(activate(value.id), { preserveScroll: true });
}
</script>

<template>
    <Head :title="`Valores de ${attribute.name}`" />

    <div class="flex flex-col gap-space-md">
        <div
            class="flex flex-col gap-space-sm md:flex-row md:items-center md:justify-between"
        >
            <div class="flex flex-col gap-space-xs">
                <Link
                    :href="index().url"
                    class="font-label-lg text-label-lg text-primary underline"
                >
                    Volver a atributos
                </Link>
                <h1 class="font-headline-md text-headline-md text-primary">
                    {{ attribute.name }}
                </h1>
                <p class="font-body-md text-body-md text-on-surface-variant">
                    Presentación: {{ attribute.presentation_label }}. Uso
                    especial: {{ attribute.special_use_label ?? 'ninguno' }}.
                </p>
            </div>
            <div class="flex items-center gap-space-sm">
                <StatusBadge
                    :category="
                        attribute.status === 'active' ? 'done' : 'neutral'
                    "
                    :label="attribute.status_label"
                />
                <AppButton v-if="can.manage && !formOpen" @click="openCreate">
                    Nuevo valor
                </AppButton>
            </div>
        </div>

        <CatalogSections current="attributes" />

        <ValueForm
            v-if="can.manage && formOpen"
            ref="valueForm"
            :key="editing?.id ?? 'new'"
            :attribute-id="attribute.id"
            :presentation="attribute.presentation"
            :value="editing"
            @done="closePanels"
            @cancel="closePanels"
        />

        <OfferedColorsEditor
            v-if="can.manage && editingColors !== null"
            ref="colorsEditor"
            :key="editingColors.id"
            :value-id="editingColors.id"
            :value-name="editingColors.name"
            :palette="palette"
            :offered="editingColors.offered_colors ?? []"
            @done="closePanels"
            @cancel="closePanels"
        />

        <ActionErrors :errors="{ ...moveForm.errors, ...statusForm.errors }" />

        <p class="font-body-md text-body-md text-on-surface-variant">
            Cambie el orden con las flechas. Los valores no se eliminan: se
            desactivan.
        </p>

        <DataTable
            fit
            :columns="columns"
            :rows="values"
            row-key="id"
            empty-text="Este atributo aún no tiene valores."
        >
            <template #cell-name="{ row }">
                <span class="font-label-lg text-label-lg">{{ row.name }}</span>
                <span
                    v-if="row.description"
                    class="block font-body-sm text-body-sm text-on-surface-variant"
                >
                    {{ row.description }}
                </span>
            </template>
            <template #cell-tone="{ row }">
                <span class="inline-flex items-center gap-space-sm">
                    <span
                        v-if="row.tone"
                        class="size-6 shrink-0 rounded-full border border-outline"
                        :style="{ backgroundColor: row.tone }"
                        aria-hidden="true"
                    />
                    {{ row.tone ?? 'Sin tono' }}
                </span>
            </template>
            <template #cell-svg_layer="{ row }">
                {{ row.svg_layer ?? 'Sin capa' }}
            </template>
            <template #cell-offered_colors="{ row }">
                {{
                    row.offered_colors && row.offered_colors.length > 0
                        ? row.offered_colors
                              .map((color) => color.name)
                              .join(', ')
                        : 'Sin colores'
                }}
            </template>
            <template #cell-status="{ row }">
                <StatusBadge
                    :category="row.status === 'active' ? 'done' : 'neutral'"
                    :label="row.status_label"
                />
            </template>
            <template #cell-actions="{ row }">
                <div
                    class="flex flex-wrap items-center gap-x-space-md gap-y-space-sm"
                >
                    <IconButton
                        icon="arrow_upward"
                        variant="reorder"
                        :label="`Subir ${row.name}`"
                        :disabled="position(row) === 0 || moveForm.processing"
                        @click="moveValue(row, 'up')"
                    />
                    <IconButton
                        icon="arrow_downward"
                        variant="reorder"
                        :label="`Bajar ${row.name}`"
                        :disabled="
                            position(row) === values.length - 1 ||
                            moveForm.processing
                        "
                        @click="moveValue(row, 'down')"
                    />
                    <AppButton variant="outlined" @click="openEdit(row)">
                        Editar
                    </AppButton>
                    <AppButton
                        v-if="isFabric"
                        variant="outlined"
                        @click="openColors(row)"
                    >
                        Colores
                    </AppButton>
                    <AppButton
                        v-if="row.status === 'active'"
                        variant="outlined"
                        :disabled="statusForm.processing"
                        @click="deactivating = row"
                    >
                        Desactivar
                    </AppButton>
                    <AppButton
                        v-else
                        variant="outlined"
                        :disabled="statusForm.processing"
                        @click="reactivateValue(row)"
                    >
                        Reactivar
                    </AppButton>
                </div>
            </template>
        </DataTable>

        <ConfirmDialog
            v-model:open="confirmingDeactivate"
            title="Desactivar valor"
            message="El valor se desactivará. Puede reactivarlo cuando quiera."
            confirm-label="Desactivar"
            :processing="statusForm.processing"
            @confirm="deactivateValue"
        />
    </div>
</template>
