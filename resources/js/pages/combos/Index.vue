<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppButton from '@/components/AppButton.vue';
import AppInput from '@/components/AppInput.vue';
import AppPagination from '@/components/AppPagination.vue';
import DataTable from '@/components/DataTable.vue';
import type { DataTableColumn } from '@/components/DataTable.vue';
import SegmentedTabs from '@/components/SegmentedTabs.vue';
import type { SegmentedTab } from '@/components/SegmentedTabs.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { create, index, show } from '@/routes/combos';
import type {
    ComboListPage,
    ProductStatusCounts,
    ProductStatusFilter,
} from '@/types/products';

defineOptions({ layout: AppLayout });

// Everything arrives resolved by the backend: the filters as it applied them, counts computed with
// the same search and labelled rows.
const props = defineProps<{
    combos: ComboListPage;
    status: ProductStatusFilter;
    q: string;
    counts: ProductStatusCounts;
    // Mirrors the policy; it only decides what to show.
    can: { create: boolean };
}>();

const term = ref(props.q);

type ListQuery = {
    status?: ProductStatusFilter;
    q?: string;
};

// Only non-default filters travel in the URL; the backend resolves the rest.
function query(
    overrides: Partial<{ status: ProductStatusFilter; q: string }> = {},
): ListQuery {
    const status = overrides.status ?? props.status;
    const q = overrides.q ?? props.q;

    return {
        ...(status !== 'active' ? { status } : {}),
        ...(q !== '' ? { q } : {}),
    };
}

const tabs = computed<SegmentedTab[]>(() => [
    {
        key: 'active',
        label: 'Activos',
        count: props.counts.active,
        href: index({ query: query({ status: 'active' }) }).url,
    },
    {
        key: 'inactive',
        label: 'Inactivos',
        count: props.counts.inactive,
        href: index({ query: query({ status: 'inactive' }) }).url,
    },
    {
        key: 'all',
        label: 'Todos',
        count: props.counts.all,
        href: index({ query: query({ status: 'all' }) }).url,
    },
]);

const EMPTY_TEXT: Record<ProductStatusFilter, string> = {
    active: 'No hay combos activos.',
    inactive: 'No hay combos inactivos.',
    all: 'No hay combos registrados.',
};

const emptyText = computed(() =>
    props.q !== ''
        ? 'Ningún combo coincide con la búsqueda.'
        : EMPTY_TEXT[props.status],
);

const columns: DataTableColumn[] = [
    { key: 'name', label: 'Combo' },
    { key: 'code', label: 'Código' },
    { key: 'components_count', label: 'Componentes' },
    { key: 'portal_visible', label: 'Portal' },
    { key: 'status', label: 'Estado' },
    { key: 'actions', label: 'Acciones' },
];

function submitSearch(): void {
    router.get(index().url, query({ q: term.value.trim() }), {
        preserveScroll: true,
    });
}
</script>

<template>
    <Head title="Combos" />

    <div class="flex flex-col gap-space-md">
        <div
            class="flex flex-col gap-space-sm md:flex-row md:items-center md:justify-between"
        >
            <h1 class="font-headline-md text-headline-md text-primary">
                Combos
            </h1>
            <AppButton v-if="can.create" :href="create().url">
                Nuevo combo
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
                    label="Buscar combo"
                    type="search"
                    inputmode="search"
                    icon="search"
                    :maxlength="100"
                    placeholder="Nombre o código"
                />
            </div>
            <AppButton type="submit" variant="secondary">Buscar</AppButton>
        </form>

        <SegmentedTabs
            :tabs="tabs"
            :current="status"
            label="Filtrar combos por estado"
        />

        <DataTable
            :columns="columns"
            :rows="combos.data"
            row-key="id"
            :empty-text="emptyText"
        >
            <template #cell-code="{ row }">
                {{ row.code ?? 'No registrado' }}
            </template>
            <template #cell-portal_visible="{ row }">
                {{ row.portal_visible ? 'Visible' : 'Oculto' }}
            </template>
            <template #cell-status="{ row }">
                <StatusBadge
                    :category="row.status === 'active' ? 'done' : 'neutral'"
                    :label="row.status_label"
                />
            </template>
            <template #cell-actions="{ row }">
                <AppButton
                    variant="outlined"
                    :href="show(row.id).url"
                    :aria-label="`Ver detalle de ${row.name}`"
                >
                    Detalle
                </AppButton>
            </template>
        </DataTable>

        <AppPagination
            v-if="combos.last_page > 1"
            :current-page="combos.current_page"
            :last-page="combos.last_page"
            :prev-url="combos.prev_page_url"
            :next-url="combos.next_page_url"
        />
    </div>
</template>
