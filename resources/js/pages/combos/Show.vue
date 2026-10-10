<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppButton from '@/components/AppButton.vue';
import AppCard from '@/components/AppCard.vue';
import AppIcon from '@/components/AppIcon.vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import DataTable from '@/components/DataTable.vue';
import type { DataTableColumn } from '@/components/DataTable.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { FOCUS_RING } from '@/lib/ui';
import { activate, deactivate, destroy, edit, index } from '@/routes/combos';
import { show as showProduct } from '@/routes/products';
import type { ComboCan, ComboDetail } from '@/types/products';

defineOptions({ layout: AppLayout });

// Every value arrives resolved by the backend. The buttons only reflect the `can` flags; the
// backend authorizes every operation.
const props = defineProps<{
    combo: ComboDetail;
    can: ComboCan;
}>();

const statusForm = useForm({});
const deleteForm = useForm({});

// Destructive actions submit only after the confirmation dialog (design-system §7.10).
const confirmingDeactivate = ref(false);
const confirmingDelete = ref(false);

function deactivateCombo(): void {
    statusForm.submit(deactivate(props.combo.id));
}

function reactivateCombo(): void {
    // Reactivating is reversible and has no side effects: no confirmation.
    statusForm.submit(activate(props.combo.id));
}

// A rejection (the combo has history) comes back as an error flash on this page, where "Desactivar"
// is offered.
function deleteCombo(): void {
    deleteForm.submit(destroy(props.combo.id));
}

const columns = computed<DataTableColumn[]>(() => [
    { key: 'product', label: 'Producto' },
    { key: 'quantity', label: 'Cantidad' },
    { key: 'restrictions', label: 'Valores admitidos' },
]);

const LABEL = 'font-label-md text-label-md text-on-surface-variant';
const VALUE = 'font-body-md text-body-md text-on-surface';
const H2 = 'font-headline-sm text-headline-sm text-primary';
</script>

<template>
    <Head :title="combo.name" />

    <div class="mx-auto flex w-full max-w-4xl flex-col gap-space-md">
        <AppCard>
            <div
                class="flex flex-col gap-space-sm md:flex-row md:items-center md:justify-between"
            >
                <div class="flex items-center gap-space-sm">
                    <Link
                        :href="index().url"
                        aria-label="Volver a combos"
                        title="Volver a combos"
                        :class="[
                            'inline-flex size-11 shrink-0 items-center justify-center rounded-full text-primary transition-colors hover:bg-surface-container-low',
                            FOCUS_RING,
                        ]"
                    >
                        <AppIcon name="arrow_back" />
                    </Link>
                    <h1 class="font-headline-md text-headline-md text-primary">
                        {{ combo.name }}
                    </h1>
                </div>
                <StatusBadge
                    :category="combo.status === 'active' ? 'done' : 'neutral'"
                    :label="combo.status_label"
                />
            </div>
        </AppCard>

        <AppCard>
            <h2 :class="H2">Datos generales</h2>
            <dl class="grid grid-cols-1 gap-space-sm md:grid-cols-2">
                <div class="flex flex-col">
                    <dt :class="LABEL">Código</dt>
                    <dd :class="VALUE">{{ combo.code ?? 'No registrado' }}</dd>
                </div>
                <div class="flex flex-col">
                    <dt :class="LABEL">Visible en el portal</dt>
                    <dd :class="VALUE">
                        {{ combo.portal_visible ? 'Sí' : 'No' }}
                    </dd>
                </div>
            </dl>
        </AppCard>

        <AppCard>
            <h2 :class="H2">Componentes</h2>
            <DataTable
                :columns="columns"
                :rows="combo.components"
                row-key="id"
                empty-text="El combo no tiene componentes."
            >
                <template #cell-product="{ row }">
                    <Link
                        :href="showProduct(row.product_id).url"
                        :class="['text-primary underline', FOCUS_RING]"
                    >
                        {{ row.product }}
                    </Link>
                </template>
                <template #cell-restrictions="{ row }">
                    <span v-if="row.restrictions.length === 0">
                        Todos los valores
                    </span>
                    <span v-else class="flex flex-col">
                        <span
                            v-for="restriction in row.restrictions"
                            :key="restriction.attribute"
                        >
                            {{ restriction.attribute }}:
                            {{ restriction.values.join(', ') }}
                        </span>
                    </span>
                </template>
            </DataTable>
        </AppCard>

        <div class="flex flex-col gap-space-sm md:flex-row">
            <AppButton v-if="can.update" :href="edit(combo.id).url">
                Editar
            </AppButton>
            <AppButton
                v-if="can.deactivate && combo.status === 'active'"
                variant="outlined"
                :disabled="statusForm.processing"
                @click="confirmingDeactivate = true"
            >
                Desactivar
            </AppButton>
            <AppButton
                v-if="can.deactivate && combo.status === 'inactive'"
                variant="outlined"
                :disabled="statusForm.processing"
                @click="reactivateCombo"
            >
                Reactivar
            </AppButton>
            <AppButton
                v-if="can.delete"
                variant="danger"
                :disabled="deleteForm.processing"
                @click="confirmingDelete = true"
            >
                Eliminar
            </AppButton>
            <AppButton variant="outlined" :href="index().url">
                Volver a combos
            </AppButton>
        </div>

        <ConfirmDialog
            v-model:open="confirmingDeactivate"
            title="Desactivar combo"
            message="El combo sale de la oferta y no se acepta en cotizaciones ni pedidos nuevos. Lo que esté en curso continúa su flujo normal."
            confirm-label="Desactivar"
            :processing="statusForm.processing"
            @confirm="deactivateCombo"
        />
        <ConfirmDialog
            v-model:open="confirmingDelete"
            title="Eliminar combo"
            message="Se eliminarán el combo y sus componentes. Esta acción es irreversible. Si el combo tiene historial no se puede eliminar y deberá desactivarlo."
            confirm-label="Eliminar"
            :processing="deleteForm.processing"
            @confirm="deleteCombo"
        />
    </div>
</template>
