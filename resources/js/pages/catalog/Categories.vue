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
import IconButton from '@/components/IconButton.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { useScrollToForm } from '@/composables/useScrollToForm';
import AppLayout from '@/layouts/AppLayout.vue';
import {
    activate,
    deactivate,
    move,
    store,
    update,
} from '@/routes/catalog/categories';
import type { CatalogCan, CatalogCategory } from '@/types/products';

defineOptions({ layout: AppLayout });

// Rows arrive in display order with their labels resolved (PRD-001). The controls only reflect
// `can`; the backend authorizes every write.
const props = defineProps<{
    categories: CatalogCategory[];
    can: CatalogCan;
}>();

const columns = computed<DataTableColumn[]>(() => [
    { key: 'name', label: 'Nombre' },
    { key: 'status', label: 'Estado' },
    ...(props.can.manage ? [{ key: 'actions', label: 'Acciones' }] : []),
]);

// One form serves creation and editing; `editing` is the category being edited, null when creating.
const formOpen = ref(false);
const editing = ref<CatalogCategory | null>(null);
const form = useForm({ name: '' });
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

function openEdit(category: CatalogCategory): void {
    editing.value = category;
    form.name = category.name;
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

function position(category: CatalogCategory): number {
    return props.categories.findIndex((row) => row.id === category.id);
}

function moveCategory(
    category: CatalogCategory,
    direction: 'up' | 'down',
): void {
    moveForm.direction = direction;
    moveForm.clearErrors();
    moveForm.submit(move(category.id), { preserveScroll: true });
}

// Deactivating asks for confirmation (design-system §7.10); reactivating is reversible and does not.
// A rejection closes the dialog so the backend message shows on the page.
const deactivating = ref<CatalogCategory | null>(null);
const confirmingDeactivate = computed({
    get: () => deactivating.value !== null,
    set: (open: boolean) => {
        if (!open) {
            deactivating.value = null;
        }
    },
});

function deactivateCategory(): void {
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

function reactivateCategory(category: CatalogCategory): void {
    statusForm.clearErrors();
    statusForm.submit(activate(category.id), { preserveScroll: true });
}
</script>

<template>
    <Head title="Categorías" />

    <div class="flex flex-col gap-space-md">
        <div
            class="flex flex-col gap-space-sm md:flex-row md:items-center md:justify-between"
        >
            <h1 class="font-headline-md text-headline-md text-primary">
                Categorías
            </h1>
            <AppButton v-if="can.manage && !formOpen" @click="openCreate">
                Nueva categoría
            </AppButton>
        </div>

        <CatalogSections current="categories" />

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
                            ? 'Nueva categoría'
                            : 'Editar categoría'
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
            Cambie el orden con las flechas. Las categorías no se eliminan: se
            desactivan.
        </p>

        <DataTable
            :columns="columns"
            :rows="categories"
            row-key="id"
            empty-text="No hay categorías registradas."
        >
            <template #cell-status="{ row }">
                <StatusBadge
                    :category="row.status === 'active' ? 'done' : 'neutral'"
                    :label="row.status_label"
                />
            </template>
            <template #cell-actions="{ row }">
                <div class="flex flex-wrap items-center gap-space-sm">
                    <IconButton
                        icon="arrow_upward"
                        variant="tool"
                        :label="`Subir ${row.name}`"
                        :disabled="position(row) === 0 || moveForm.processing"
                        @click="moveCategory(row, 'up')"
                    />
                    <IconButton
                        icon="arrow_downward"
                        variant="tool"
                        :label="`Bajar ${row.name}`"
                        :disabled="
                            position(row) === categories.length - 1 ||
                            moveForm.processing
                        "
                        @click="moveCategory(row, 'down')"
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
                        @click="reactivateCategory(row)"
                    >
                        Reactivar
                    </AppButton>
                </div>
            </template>
        </DataTable>

        <ConfirmDialog
            v-model:open="confirmingDeactivate"
            title="Desactivar categoría"
            message="La categoría no se mostrará en el portal. Puede reactivarla cuando quiera."
            confirm-label="Desactivar"
            :processing="statusForm.processing"
            @confirm="deactivateCategory"
        />
    </div>
</template>
