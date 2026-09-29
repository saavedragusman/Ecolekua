<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppButton from '@/components/AppButton.vue';
import AppCard from '@/components/AppCard.vue';
import AppCheckbox from '@/components/AppCheckbox.vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { usePermissions } from '@/composables/usePermissions';
import AppLayout from '@/layouts/AppLayout.vue';
import { destroy, edit, index } from '@/routes/roles';
import { update as updatePermissions } from '@/routes/roles/permissions';
import type { PermissionItem, RoleDetail } from '@/types/roles';

defineOptions({ layout: AppLayout });

const props = defineProps<{
    role: RoleDetail;
    // Permission catalog; empty unless the actor may manage roles.
    permissions: PermissionItem[];
}>();

const { can } = usePermissions();

const permissionsForm = useForm<{ permissions: number[] }>({
    permissions: props.role.permissions.map((permission) => permission.id),
});
const deleteForm = useForm({});

// Deleting a role is irreversible (physical deletion): ask first (design-system §7.10).
const confirmingDelete = ref(false);

function savePermissions(): void {
    permissionsForm.submit(updatePermissions(props.role.id));
}

function deleteRole(): void {
    // The dialog closes itself on confirm; backend rejections (E-22, E-23)
    // arrive as an error flash shown by the layout.
    deleteForm.submit(destroy(props.role.id));
}
</script>

<template>
    <Head title="Detalle de rol" />

    <div class="mx-auto flex w-full max-w-2xl flex-col gap-space-md">
        <AppCard>
            <div
                class="flex flex-col gap-space-sm md:flex-row md:items-center md:justify-between"
            >
                <h1 class="font-headline-md text-headline-md text-primary">
                    {{ role.name }}
                </h1>
                <StatusBadge
                    v-if="role.is_protected"
                    category="active"
                    label="Protegido"
                />
            </div>

            <dl class="flex flex-col gap-space-sm">
                <div class="flex flex-col">
                    <dt
                        class="font-label-md text-label-md text-on-surface-variant"
                    >
                        Descripción
                    </dt>
                    <dd class="font-body-md text-body-md text-on-surface">
                        {{ role.description || 'Sin descripción' }}
                    </dd>
                </div>
                <div class="flex flex-col">
                    <dt
                        class="font-label-md text-label-md text-on-surface-variant"
                    >
                        Usuarios con este rol
                    </dt>
                    <dd class="font-body-md text-body-md text-on-surface">
                        {{ role.users_count ?? 0 }}
                    </dd>
                </div>
            </dl>

            <p
                v-if="role.is_protected"
                class="font-body-md text-body-md text-on-surface-variant"
            >
                Este rol está protegido: no se puede cambiar su nombre ni
                eliminarlo. Sí se puede editar su descripción.
            </p>

            <div class="flex flex-col gap-space-sm md:flex-row">
                <AppButton v-if="can('roles.manage')" :href="edit(role.id).url">
                    Editar datos
                </AppButton>
                <AppButton variant="outlined" :href="index().url">
                    Volver a roles
                </AppButton>
            </div>
        </AppCard>

        <AppCard v-if="can('roles.manage')">
            <form
                class="flex flex-col gap-space-md"
                novalidate
                @submit.prevent="savePermissions"
            >
                <h2 class="font-headline-sm text-headline-sm text-primary">
                    Permisos
                </h2>
                <p
                    v-if="permissionsForm.errors.permissions"
                    class="rounded-lg bg-error-container p-space-md font-body-md text-body-md text-on-error-container"
                    role="alert"
                >
                    {{ permissionsForm.errors.permissions }}
                </p>
                <fieldset class="flex flex-col gap-space-xs">
                    <legend class="sr-only">Permisos del rol</legend>
                    <AppCheckbox
                        v-for="permission in permissions"
                        :key="permission.id"
                        v-model="permissionsForm.permissions"
                        :value="permission.id"
                        :label="permission.description"
                    />
                </fieldset>
                <AppButton type="submit" :disabled="permissionsForm.processing">
                    Guardar permisos
                </AppButton>
            </form>
        </AppCard>

        <AppCard v-else>
            <h2 class="font-headline-sm text-headline-sm text-primary">
                Permisos
            </h2>
            <ul
                v-if="role.permissions.length > 0"
                class="flex flex-col gap-space-xs"
            >
                <li
                    v-for="permission in role.permissions"
                    :key="permission.id"
                    class="font-body-md text-body-md text-on-surface"
                >
                    {{ permission.description }}
                </li>
            </ul>
            <p v-else class="font-body-md text-body-md text-on-surface-variant">
                Este rol no tiene permisos.
            </p>
        </AppCard>

        <AppCard v-if="can('roles.manage') && !role.is_protected">
            <form
                class="flex flex-col gap-space-md"
                @submit.prevent="confirmingDelete = true"
            >
                <h2 class="font-headline-sm text-headline-sm text-primary">
                    Eliminar rol
                </h2>
                <p class="font-body-md text-body-md text-on-surface-variant">
                    La eliminación es definitiva. Solo se puede eliminar un rol
                    que no tenga usuarios asignados.
                </p>
                <AppButton
                    type="submit"
                    variant="danger"
                    :disabled="deleteForm.processing"
                >
                    Eliminar rol
                </AppButton>
            </form>
        </AppCard>

        <ConfirmDialog
            v-model:open="confirmingDelete"
            title="Eliminar rol"
            message="El rol se eliminará de forma definitiva junto con sus permisos asignados. Esta acción no se puede deshacer."
            confirm-label="Eliminar"
            :processing="deleteForm.processing"
            @confirm="deleteRole"
        />
    </div>
</template>
