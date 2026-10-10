<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppButton from '@/components/AppButton.vue';
import AppInput from '@/components/AppInput.vue';
import AppPagination from '@/components/AppPagination.vue';
import AppSelect from '@/components/AppSelect.vue';
import type { SelectOption } from '@/components/AppSelect.vue';
import DataTable from '@/components/DataTable.vue';
import type { DataTableColumn } from '@/components/DataTable.vue';
import SegmentedTabs from '@/components/SegmentedTabs.vue';
import type { SegmentedTab } from '@/components/SegmentedTabs.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { create, index, show } from '@/routes/products';
import type {
    ProductFilterOptions,
    ProductListPage,
    ProductStatusCounts,
    ProductStatusFilter,
} from '@/types/products';

defineOptions({ layout: AppLayout });

// Everything arrives resolved by the backend: filters as it applied them, counts computed with
// the same search and filters, labelled rows and the filter options.
const props = defineProps<{
    products: ProductListPage;
    status: ProductStatusFilter;
    q: string;
    category: number | null;
    line: string | null;
    mode: string | null;
    counts: ProductStatusCounts;
    filters: ProductFilterOptions;
    // Mirrors the policy; it only decides what to show.
    can: { create: boolean };
}>();

const term = ref(props.q);

type ListQuery = {
    status?: ProductStatusFilter;
    q?: string;
    category?: number;
    line?: string;
    mode?: string;
};

type Overrides = Partial<{
    status: ProductStatusFilter;
    q: string;
    category: number | null;
    line: string | null;
    mode: string | null;
}>;

// Only non-default filters travel in the URL; the backend resolves the rest.
function query(overrides: Overrides = {}): ListQuery {
    const status = overrides.status ?? props.status;
    const q = overrides.q ?? props.q;
    const category =
        overrides.category === undefined ? props.category : overrides.category;
    const line = overrides.line === undefined ? props.line : overrides.line;
    const mode = overrides.mode === undefined ? props.mode : overrides.mode;

    return {
        ...(status !== 'active' ? { status } : {}),
        ...(q !== '' ? { q } : {}),
        ...(category !== null ? { category } : {}),
        ...(line !== null ? { line } : {}),
        ...(mode !== null ? { mode } : {}),
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

const categoryOptions = computed<SelectOption[]>(() =>
    props.filters.categories.map((option) => ({
        value: option.value,
        label: option.label,
    })),
);

const EMPTY_TEXT: Record<ProductStatusFilter, string> = {
    active: 'No hay productos activos.',
    inactive: 'No hay productos inactivos.',
    all: 'No hay productos registrados.',
};

const hasFilters = computed(
    () =>
        props.q !== '' ||
        props.category !== null ||
        props.line !== null ||
        props.mode !== null,
);

const emptyText = computed(() =>
    hasFilters.value
        ? 'Ningún producto coincide con la búsqueda o los filtros.'
        : EMPTY_TEXT[props.status],
);

const columns: DataTableColumn[] = [
    { key: 'name', label: 'Producto' },
    { key: 'category', label: 'Categoría' },
    { key: 'business_line_label', label: 'Línea' },
    { key: 'supply_mode_label', label: 'Modo' },
    { key: 'combinations_count', label: 'Combinaciones' },
    { key: 'status', label: 'Estado' },
    { key: 'actions', label: 'Acciones' },
];

function visit(overrides: Overrides): void {
    router.get(index().url, query(overrides), { preserveScroll: true });
}

function submitSearch(): void {
    visit({ q: term.value.trim() });
}

// An empty select value means "all": the filter leaves the URL.
function changeCategory(value: string | number): void {
    visit({ category: value === '' ? null : Number(value) });
}

function changeLine(value: string | number): void {
    visit({ line: value === '' ? null : String(value) });
}

function changeMode(value: string | number): void {
    visit({ mode: value === '' ? null : String(value) });
}
</script>

<template>
    <Head title="Productos" />

    <div class="flex flex-col gap-space-md">
        <div
            class="flex flex-col gap-space-sm md:flex-row md:items-center md:justify-between"
        >
            <h1 class="font-headline-md text-headline-md text-primary">
                Productos
            </h1>
            <AppButton v-if="can.create" :href="create().url">
                Nuevo producto
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
                    label="Buscar producto"
                    type="search"
                    inputmode="search"
                    icon="search"
                    :maxlength="100"
                    placeholder="Nombre o código"
                />
            </div>
            <AppButton type="submit" variant="secondary">Buscar</AppButton>
        </form>

        <div class="grid grid-cols-1 gap-space-sm md:grid-cols-3">
            <AppSelect
                :model-value="category ?? ''"
                label="Categoría"
                placeholder="Todas"
                :options="categoryOptions"
                @update:model-value="changeCategory"
            />
            <AppSelect
                :model-value="line ?? ''"
                label="Línea"
                placeholder="Todas"
                :options="filters.lines"
                @update:model-value="changeLine"
            />
            <AppSelect
                :model-value="mode ?? ''"
                label="Modo de abastecimiento"
                placeholder="Todos"
                :options="filters.modes"
                @update:model-value="changeMode"
            />
        </div>

        <SegmentedTabs
            :tabs="tabs"
            :current="status"
            label="Filtrar productos por estado"
        />

        <DataTable
            :columns="columns"
            :rows="products.data"
            row-key="id"
            :empty-text="emptyText"
        >
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
            v-if="products.last_page > 1"
            :current-page="products.current_page"
            :last-page="products.last_page"
            :prev-url="products.prev_page_url"
            :next-url="products.next_page_url"
        />
    </div>
</template>
