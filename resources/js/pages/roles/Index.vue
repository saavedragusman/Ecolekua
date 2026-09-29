<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import AppButton from '@/components/AppButton.vue';
import DataTable from '@/components/DataTable.vue';
import type { DataTableColumn } from '@/components/DataTable.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { usePermissions } from '@/composables/usePermissions';
import AppLayout from '@/layouts/AppLayout.vue';
import { create, show } from '@/routes/roles';
import type { RoleSummary } from '@/types/roles';

defineOptions({ layout: AppLayout });

defineProps<{
    roles: RoleSummary[];
}>();

const { can } = usePermissions();

const columns: DataTableColumn[] = [
    { key: 'name', label: 'Rol' },
    { key: 'description', label: 'Descripción' },
    { key: 'users_count', label: 'Usuarios' },
    { key: 'permissions_count', label: 'Permisos' },
    { key: 'actions', label: 'Acciones' },
];
</script>

<template>
    <Head title="Roles" />

    <div class="flex flex-col gap-space-md">
        <div
            class="flex flex-col gap-space-sm md:flex-row md:items-center md:justify-between"
        >
            <h1 class="font-headline-md text-headline-md text-primary">
                Roles
            </h1>
            <AppButton v-if="can('roles.manage')" :href="create().url">
                Nuevo rol
            </AppButton>
        </div>

        <DataTable
            :columns="columns"
            :rows="roles"
            row-key="id"
            empty-text="No hay roles registrados."
        >
            <template #cell-name="{ row }">
                <span class="inline-flex flex-wrap items-center gap-space-xs">
                    {{ row.name }}
                    <StatusBadge
                        v-if="row.is_protected"
                        category="active"
                        label="Protegido"
                    />
                </span>
            </template>
            <template #cell-description="{ row }">
                {{ row.description || 'Sin descripción' }}
            </template>
            <template #cell-actions="{ row }">
                <AppButton variant="outlined" :href="show(row.id).url">
                    Ver detalle
                </AppButton>
            </template>
        </DataTable>
    </div>
</template>
