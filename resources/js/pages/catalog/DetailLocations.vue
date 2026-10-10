<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref, useTemplateRef } from 'vue';
import AppButton from '@/components/AppButton.vue';
import AppCard from '@/components/AppCard.vue';
import AppInput from '@/components/AppInput.vue';
import ActionErrors from '@/components/catalog/ActionErrors.vue';
import CatalogSections from '@/components/catalog/CatalogSections.vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import DataTable from '@/components/DataTable.vue';
import type { DataTableColumn } from '@/components/DataTable.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { useScrollToForm } from '@/composables/useScrollToForm';
import AppLayout from '@/layouts/AppLayout.vue';
import {
    activate,
    deactivate,
    store,
    update,
} from '@/routes/catalog/detail-locations';
import type { CatalogCan, CatalogDetailLocation } from '@/types/products';

defineOptions({ layout: AppLayout });

// Rows arrive listed by name with their labels resolved (PRD-007). The controls only reflect `can`;
// the backend authorizes every write and validates the name and the layer.
const props = defineProps<{
    locations: CatalogDetailLocation[];
    can: CatalogCan;
}>();

const columns = computed<DataTableColumn[]>(() => [
    { key: 'name', label: 'Nombre' },
    { key: 'svg_layer', label: 'Capa del SVG' },
    { key: 'status', label: 'Estado' },
    ...(props.can.manage ? [{ key: 'actions', label: 'Acciones' }] : []),
]);

// One form serves creation and editing; `editing` is the location being edited, null when creating.
const formOpen = ref(false);
const editing = ref<CatalogDetailLocation | null>(null);
const form = useForm({ name: '', svg_layer: '' });
const formElement = useTemplateRef<HTMLFormElement>('formElement');
const { scrollToForm } = useScrollToForm(formElement);
const statusForm = useForm({});

function openCreate(): void {
    editing.value = null;
    form.reset();
    form.clearErrors();
    formOpen.value = true;
}

function openEdit(location: CatalogDetailLocation): void {
    editing.value = location;
    form.name = location.name;
    form.svg_layer = location.svg_layer ?? '';
    form.clearErrors();
    formOpen.value = true;
    void scrollToForm();
}

function closeForm(): void {
    formOpen.value = false;
    editing.value = null;
    form.reset();
    form.clearErrors();
}

// An empty layer is sent as an empty string; the backend turns it into "no layer" (null).
function submit(): void {
    const target = editing.value;

    form.submit(target === null ? store() : update(target.id), {
        preserveScroll: true,
        onSuccess: closeForm,
    });
}

// Deactivating asks for confirmation (design-system §7.10); reactivating is reversible and does not.
// A rejection closes the dialog so the backend message shows on the page.
const deactivating = ref<CatalogDetailLocation | null>(null);
const confirmingDeactivate = computed({
    get: () => deactivating.value !== null,
    set: (open: boolean) => {
        if (!open) {
            deactivating.value = null;
        }
    },
});

function deactivateLocation(): void {
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

function reactivateLocation(location: CatalogDetailLocation): void {
    statusForm.clearErrors();
    statusForm.submit(activate(location.id), { preserveScroll: true });
}
</script>

<template>
    <Head title="Ubicaciones de detalle" />

    <div class="flex flex-col gap-space-md">
        <div
            class="flex flex-col gap-space-sm md:flex-row md:items-center md:justify-between"
        >
            <h1 class="font-headline-md text-headline-md text-primary">
                Ubicaciones de detalle
            </h1>
            <AppButton v-if="can.manage && !formOpen" @click="openCreate">
                Nueva ubicación
            </AppButton>
        </div>

        <CatalogSections current="detail-locations" />

        <form
            v-if="can.manage && formOpen"
            ref="formElement"
            novalidate
            @submit.prevent="submit"
        >
            <AppCard>
                <h2 class="font-headline-sm text-headline-sm text-primary">
                    {{
                        editing === null
                            ? 'Nueva ubicación de detalle'
                            : 'Editar ubicación de detalle'
                    }}
                </h2>
                <AppInput
                    v-model="form.name"
                    label="Nombre"
                    autocomplete="off"
                    :maxlength="100"
                    required
                    :error="form.errors.name"
                />
                <AppInput
                    v-model="form.svg_layer"
                    label="Capa del SVG (opcional)"
                    autocomplete="off"
                    :maxlength="64"
                    hint="Minúsculas y guiones, por ejemplo pechera o manga-larga. No puede ser cuerpo ni sombras."
                    :error="form.errors.svg_layer"
                />
                <div class="flex flex-col gap-space-sm md:flex-row">
                    <AppButton type="submit" :disabled="form.processing">
                        Guardar
                    </AppButton>
                    <AppButton variant="secondary" @click="closeForm">
                        Cancelar
                    </AppButton>
                </div>
            </AppCard>
        </form>

        <ActionErrors :errors="statusForm.errors" />

        <p class="font-body-md text-body-md text-on-surface-variant">
            Las ubicaciones se listan por nombre y no se eliminan: se
            desactivan.
        </p>

        <DataTable
            fit
            :columns="columns"
            :rows="locations"
            row-key="id"
            empty-text="No hay ubicaciones de detalle registradas."
        >
            <template #cell-svg_layer="{ row }">
                {{ row.svg_layer ?? 'Sin capa' }}
            </template>
            <template #cell-status="{ row }">
                <StatusBadge
                    :category="row.status === 'active' ? 'done' : 'neutral'"
                    :label="row.status_label"
                />
            </template>
            <template #cell-actions="{ row }">
                <div class="flex flex-wrap items-center gap-space-sm">
                    <AppButton variant="outlined" @click="openEdit(row)">
                        Editar
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
                        @click="reactivateLocation(row)"
                    >
                        Reactivar
                    </AppButton>
                </div>
            </template>
        </DataTable>

        <ConfirmDialog
            v-model:open="confirmingDeactivate"
            title="Desactivar ubicación de detalle"
            message="La ubicación se desactivará. Puede reactivarla cuando quiera."
            confirm-label="Desactivar"
            :processing="statusForm.processing"
            @confirm="deactivateLocation"
        />
    </div>
</template>
