<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppButton from '@/components/AppButton.vue';
import AppCheckbox from '@/components/AppCheckbox.vue';
import AppInput from '@/components/AppInput.vue';
import AppPagination from '@/components/AppPagination.vue';
import DataTable from '@/components/DataTable.vue';
import type { DataTableColumn } from '@/components/DataTable.vue';
import SegmentedTabs from '@/components/SegmentedTabs.vue';
import type { SegmentedTab } from '@/components/SegmentedTabs.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { usePermissions } from '@/composables/usePermissions';
import AppLayout from '@/layouts/AppLayout.vue';
import { create, index, show } from '@/routes/customers';
import type {
    CustomerListPage,
    CustomerStatusCounts,
    CustomerStatusFilter,
} from '@/types/customers';

defineOptions({ layout: AppLayout });

const props = defineProps<{
    customers: CustomerListPage;
    status: CustomerStatusFilter;
    // Search term and "Mis clientes" as the backend applied them.
    q: string;
    mine: boolean;
    canFilterMine: boolean;
    counts: CustomerStatusCounts;
}>();

const { can } = usePermissions();

const term = ref(props.q);

type ListQuery = {
    status?: CustomerStatusFilter;
    q?: string;
    mine?: 1;
};

// Only non-default filters travel in the URL; the backend resolves the rest.
function query(status: CustomerStatusFilter, mine: boolean): ListQuery {
    return {
        ...(status !== 'active' ? { status } : {}),
        ...(props.q !== '' ? { q: props.q } : {}),
        ...(mine ? { mine: 1 as const } : {}),
    };
}

const tabs = computed<SegmentedTab[]>(() => [
    {
        key: 'active',
        label: 'Activos',
        count: props.counts.active,
        href: index({ query: query('active', props.mine) }).url,
    },
    {
        key: 'inactive',
        label: 'Inactivos',
        count: props.counts.inactive,
        href: index({ query: query('inactive', props.mine) }).url,
    },
    {
        key: 'all',
        label: 'Todos',
        count: props.counts.all,
        href: index({ query: query('all', props.mine) }).url,
    },
]);

const EMPTY_TEXT: Record<CustomerStatusFilter, string> = {
    active: 'No hay clientes activos.',
    inactive: 'No hay clientes inactivos.',
    all: 'No hay clientes registrados.',
};

const emptyText = computed(() =>
    props.q !== ''
        ? 'Ningún cliente coincide con la búsqueda.'
        : EMPTY_TEXT[props.status],
);

const columns: DataTableColumn[] = [
    { key: 'name', label: 'Nombre' },
    { key: 'type_label', label: 'Tipo' },
    { key: 'document', label: 'Documento' },
    { key: 'phone', label: 'Teléfono' },
    { key: 'advisor', label: 'Asesora' },
    { key: 'status', label: 'Estado' },
    { key: 'actions', label: 'Acciones' },
];

function visit(search: string, mine: boolean): void {
    const trimmed = search.trim();

    router.get(
        index().url,
        {
            ...(props.status !== 'active' ? { status: props.status } : {}),
            ...(trimmed !== '' ? { q: trimmed } : {}),
            ...(mine ? { mine: 1 } : {}),
        },
        { preserveScroll: true },
    );
}

function submitSearch(): void {
    visit(term.value, props.mine);
}

function toggleMine(value: boolean): void {
    visit(props.q, value);
}
</script>

<template>
    <Head title="Clientes" />

    <div class="flex flex-col gap-space-md">
        <div
            class="flex flex-col gap-space-sm md:flex-row md:items-center md:justify-between"
        >
            <h1 class="font-headline-md text-headline-md text-primary">
                Clientes
            </h1>
            <AppButton v-if="can('customers.create')" :href="create().url">
                Nuevo cliente
            </AppButton>
        </div>

        <form
            role="search"
            class="flex flex-col gap-space-sm md:flex-row md:items-end"
            @submit.prevent="submitSearch"
        >
            <div class="md:w-96">
                <AppInput
                    v-model="term"
                    label="Buscar cliente"
                    type="search"
                    inputmode="search"
                    icon="search"
                    :maxlength="100"
                    placeholder="Nombre, documento o teléfono"
                />
            </div>
            <AppButton type="submit" variant="secondary">Buscar</AppButton>
        </form>

        <AppCheckbox
            v-if="canFilterMine && can('customers.portfolio')"
            :model-value="mine"
            label="Mis clientes"
            @update:model-value="toggleMine(Boolean($event))"
        />

        <SegmentedTabs
            :tabs="tabs"
            :current="status"
            label="Filtrar clientes por estado"
        />

        <DataTable
            :columns="columns"
            :rows="customers.data"
            row-key="id"
            :empty-text="emptyText"
        >
            <template #cell-document="{ row }">
                {{ row.document ?? 'Sin documento' }}
            </template>
            <template #cell-advisor="{ row }">
                <span v-if="row.advisor === null">Sin asesora</span>
                <span v-else class="flex flex-wrap items-center gap-space-xs">
                    {{ row.advisor.name }}
                    <StatusBadge
                        v-if="!row.advisor.available"
                        category="pending"
                        label="Asesora no disponible"
                    />
                </span>
            </template>
            <template #cell-status="{ row }">
                <StatusBadge
                    :category="row.status === 'active' ? 'done' : 'neutral'"
                    :label="row.status_label"
                />
            </template>
            <template #cell-actions="{ row }">
                <AppButton variant="outlined" :href="show(row.id).url">
                    Ver detalle
                </AppButton>
            </template>
        </DataTable>

        <AppPagination
            v-if="customers.last_page > 1"
            :current-page="customers.current_page"
            :last-page="customers.last_page"
            :prev-url="customers.prev_page_url"
            :next-url="customers.next_page_url"
        />
    </div>
</template>
