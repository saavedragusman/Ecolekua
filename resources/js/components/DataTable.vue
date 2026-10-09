<script setup lang="ts" generic="T extends Record<string, unknown>">
import AppCard from '@/components/AppCard.vue';

export type DataTableColumn = {
    key: string;
    label: string;
};

defineProps<{
    columns: DataTableColumn[];
    rows: T[];
    // Property of each row that uniquely identifies it.
    rowKey: keyof T & string;
    emptyText?: string;
}>();

defineSlots<{
    // One optional slot per column: `cell-<column key>`.
    [name: `cell-${string}`]: (props: { row: T }) => unknown;
    empty?: () => unknown;
}>();
</script>

<template>
    <div>
        <div
            v-if="rows.length === 0"
            class="rounded-xl bg-surface-container-lowest p-space-lg text-center font-body-md text-body-md text-on-surface-variant shadow-sm"
        >
            <slot name="empty">{{ emptyText ?? 'Sin registros.' }}</slot>
        </div>

        <template v-else>
            <!-- md and up: table (tablets are touch devices, but fit a table) -->
            <div
                class="hidden overflow-x-auto rounded-xl bg-surface-container-lowest shadow-sm md:block"
            >
                <table class="w-full text-left">
                    <thead
                        class="bg-surface-container-low font-label-md text-label-md text-on-surface-variant"
                    >
                        <tr>
                            <th
                                v-for="column in columns"
                                :key="column.key"
                                scope="col"
                                class="px-space-md py-space-sm"
                            >
                                {{ column.label }}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="row in rows"
                            :key="String(row[rowKey])"
                            class="border-t border-outline-variant"
                        >
                            <td
                                v-for="column in columns"
                                :key="column.key"
                                class="px-space-md py-space-sm font-body-sm text-body-sm text-on-surface"
                            >
                                <slot :name="`cell-${column.key}`" :row="row">
                                    {{ row[column.key] }}
                                </slot>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- below md: one card per record -->
            <ul class="flex flex-col gap-space-sm md:hidden">
                <li v-for="row in rows" :key="String(row[rowKey])">
                    <AppCard>
                        <!-- label left, value right; the label column is as wide as the longest label -->
                        <dl
                            class="grid grid-cols-[auto_1fr] gap-x-space-md gap-y-space-md"
                        >
                            <div
                                v-for="column in columns"
                                :key="column.key"
                                class="col-span-2"
                                :class="
                                    column.key === 'actions'
                                        ? 'flex flex-col gap-space-xs'
                                        : 'grid grid-cols-subgrid items-baseline'
                                "
                            >
                                <dt
                                    class="font-label-md text-label-md text-on-surface-variant"
                                >
                                    {{ column.label }}
                                </dt>
                                <dd
                                    class="min-w-0 font-body-md text-body-md text-on-surface"
                                    :class="
                                        column.key === 'actions'
                                            ? ''
                                            : 'text-right break-words'
                                    "
                                >
                                    <slot
                                        :name="`cell-${column.key}`"
                                        :row="row"
                                    >
                                        {{ row[column.key] }}
                                    </slot>
                                </dd>
                            </div>
                        </dl>
                    </AppCard>
                </li>
            </ul>
        </template>
    </div>
</template>
