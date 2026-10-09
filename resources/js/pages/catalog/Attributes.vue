<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref, useTemplateRef } from 'vue';
import AppButton from '@/components/AppButton.vue';
import AppCard from '@/components/AppCard.vue';
import AppInput from '@/components/AppInput.vue';
import AppSelect from '@/components/AppSelect.vue';
import ActionErrors from '@/components/catalog/ActionErrors.vue';
import CatalogSections from '@/components/catalog/CatalogSections.vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import DataTable from '@/components/DataTable.vue';
import type { DataTableColumn } from '@/components/DataTable.vue';
import IconButton from '@/components/IconButton.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { useScrollToForm } from '@/composables/useScrollToForm';
import AppLayout from '@/layouts/AppLayout.vue';
import {
    activate,
    deactivate,
    move,
    show,
    store,
    update,
} from '@/routes/catalog/attributes';
import type {
    CatalogAttribute,
    CatalogAttributeOptions,
    CatalogCan,
} from '@/types/products';

defineOptions({ layout: AppLayout });

// Rows arrive in display order with their labels resolved (PRD-002). The controls only reflect
// `can`; the backend authorizes every write and enforces the presentation and special-use rules.
const props = defineProps<{
    attributes: CatalogAttribute[];
    options: CatalogAttributeOptions;
    can: CatalogCan;
}>();

const columns = computed<DataTableColumn[]>(() => [
    { key: 'name', label: 'Nombre' },
    { key: 'presentation', label: 'Presentación' },
    { key: 'special_use', label: 'Uso especial' },
    { key: 'values_count', label: 'Valores' },
    { key: 'status', label: 'Estado' },
    ...(props.can.manage ? [{ key: 'actions', label: 'Acciones' }] : []),
]);

// One form serves creation and editing; `editing` is the attribute being edited, null when creating.
// The edit sends the whole attribute: an empty special use clears it.
const formOpen = ref(false);
const editing = ref<CatalogAttribute | null>(null);
const form = useForm({ name: '', presentation: 'text', special_use: '' });
const formElement = useTemplateRef<HTMLFormElement>('formElement');
const { scrollToForm } = useScrollToForm(formElement);

const moveForm = useForm<{ direction: 'up' | 'down' }>({ direction: 'up' });
const statusForm = useForm({});

function openCreate(): void {
    editing.value = null;
    form.reset();
    form.clearErrors();
    formOpen.value = true;
}

function openEdit(attribute: CatalogAttribute): void {
    editing.value = attribute;
    form.name = attribute.name;
    form.presentation = attribute.presentation;
    form.special_use = attribute.special_use ?? '';
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

function submit(): void {
    const target = editing.value;

    form.submit(target === null ? store() : update(target.id), {
        preserveScroll: true,
        onSuccess: closeForm,
    });
}

function position(attribute: CatalogAttribute): number {
    return props.attributes.findIndex((row) => row.id === attribute.id);
}

function moveAttribute(
    attribute: CatalogAttribute,
    direction: 'up' | 'down',
): void {
    moveForm.direction = direction;
    moveForm.clearErrors();
    moveForm.submit(move(attribute.id), { preserveScroll: true });
}

// Deactivating asks for confirmation (design-system §7.10); reactivating is reversible and does not.
// A rejection closes the dialog so the backend message shows on the page.
const deactivating = ref<CatalogAttribute | null>(null);
const confirmingDeactivate = computed({
    get: () => deactivating.value !== null,
    set: (open: boolean) => {
        if (!open) {
            deactivating.value = null;
        }
    },
});

function deactivateAttribute(): void {
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

function reactivateAttribute(attribute: CatalogAttribute): void {
    statusForm.clearErrors();
    statusForm.submit(activate(attribute.id), { preserveScroll: true });
}
</script>

<template>
    <Head title="Atributos" />

    <div class="flex flex-col gap-space-md">
        <div
            class="flex flex-col gap-space-sm md:flex-row md:items-center md:justify-between"
        >
            <h1 class="font-headline-md text-headline-md text-primary">
                Atributos
            </h1>
            <AppButton v-if="can.manage && !formOpen" @click="openCreate">
                Nuevo atributo
            </AppButton>
        </div>

        <CatalogSections current="attributes" />

        <form
            v-if="can.manage && formOpen"
            ref="formElement"
            novalidate
            @submit.prevent="submit"
        >
            <AppCard>
                <h2 class="font-headline-sm text-headline-sm text-primary">
                    {{
                        editing === null ? 'Nuevo atributo' : 'Editar atributo'
                    }}
                </h2>
                <AppInput
                    v-model="form.name"
                    label="Nombre"
                    autocomplete="off"
                    :maxlength="60"
                    required
                    :error="form.errors.name"
                />
                <AppSelect
                    v-model="form.presentation"
                    label="Presentación"
                    :options="options.presentations"
                    required
                    :error="form.errors.presentation"
                />
                <AppSelect
                    v-model="form.special_use"
                    label="Uso especial (opcional)"
                    placeholder="Sin uso especial"
                    :options="options.special_uses"
                    :error="form.errors.special_use"
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

        <ActionErrors :errors="{ ...moveForm.errors, ...statusForm.errors }" />

        <p class="font-body-md text-body-md text-on-surface-variant">
            Cambie el orden con las flechas. Los atributos no se eliminan: se
            desactivan.
        </p>

        <DataTable
            :columns="columns"
            :rows="attributes"
            row-key="id"
            empty-text="No hay atributos registrados."
        >
            <template #cell-name="{ row }">
                <Link
                    :href="show(row.id).url"
                    class="font-label-lg text-label-lg text-primary underline"
                >
                    {{ row.name }}
                </Link>
            </template>
            <template #cell-presentation="{ row }">
                {{ row.presentation_label }}
            </template>
            <template #cell-special_use="{ row }">
                {{ row.special_use_label ?? 'Ninguno' }}
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
                        @click="moveAttribute(row, 'up')"
                    />
                    <IconButton
                        icon="arrow_downward"
                        variant="reorder"
                        :label="`Bajar ${row.name}`"
                        :disabled="
                            position(row) === attributes.length - 1 ||
                            moveForm.processing
                        "
                        @click="moveAttribute(row, 'down')"
                    />
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
                        @click="reactivateAttribute(row)"
                    >
                        Reactivar
                    </AppButton>
                </div>
            </template>
        </DataTable>

        <ConfirmDialog
            v-model:open="confirmingDeactivate"
            title="Desactivar atributo"
            message="El atributo se desactivará y no podrá declararse en productos nuevos. Puede reactivarlo cuando quiera."
            confirm-label="Desactivar"
            :processing="statusForm.processing"
            @confirm="deactivateAttribute"
        />
    </div>
</template>
