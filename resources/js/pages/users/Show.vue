<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppButton from '@/components/AppButton.vue';
import AppCard from '@/components/AppCard.vue';
import AppCheckbox from '@/components/AppCheckbox.vue';
import AppInput from '@/components/AppInput.vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { usePermissions } from '@/composables/usePermissions';
import AppLayout from '@/layouts/AppLayout.vue';
import { activate, deactivate, edit, index } from '@/routes/users';
import { reset } from '@/routes/users/password';
import { update as updateRoles } from '@/routes/users/roles';
import type { RoleOption, UserSummary } from '@/types/users';

defineOptions({ layout: AppLayout });

const props = defineProps<{
    user: UserSummary;
    // Role options; empty unless the actor may assign roles.
    roles: RoleOption[];
}>();

const { can } = usePermissions();

const rolesForm = useForm<{ roles: number[] }>({
    roles: props.user.roles.map((role) => role.id),
});
const passwordForm = useForm({ password: '' });
const statusForm = useForm({});

// Destructive actions submit only after the confirmation dialog (design-system §7.10).
const confirmingReset = ref(false);
const confirmingDeactivate = ref(false);

function saveRoles(): void {
    rolesForm.submit(updateRoles(props.user.id));
}

function resetPassword(): void {
    passwordForm.submit(reset(props.user.id), {
        onFinish: () => passwordForm.reset('password'),
    });
}

function changeStatus(): void {
    if (props.user.is_active) {
        // Deactivating closes the person's sessions: ask first.
        confirmingDeactivate.value = true;

        return;
    }

    // Activating is reversible and has no session impact: no confirmation.
    statusForm.submit(activate(props.user.id));
}

function deactivateUser(): void {
    statusForm.submit(deactivate(props.user.id));
}
</script>

<template>
    <Head title="Detalle de usuario" />

    <div class="mx-auto flex w-full max-w-2xl flex-col gap-space-md">
        <AppCard>
            <div
                class="flex flex-col gap-space-sm md:flex-row md:items-center md:justify-between"
            >
                <h1 class="font-headline-md text-headline-md text-primary">
                    {{ user.first_name }} {{ user.last_name }}
                </h1>
                <StatusBadge :is-active="user.is_active" />
            </div>

            <dl class="flex flex-col gap-space-sm">
                <div class="flex flex-col">
                    <dt
                        class="font-label-md text-label-md text-on-surface-variant"
                    >
                        Correo electrónico
                    </dt>
                    <dd class="font-body-md text-body-md text-on-surface">
                        {{ user.email }}
                    </dd>
                </div>
                <div class="flex flex-col">
                    <dt
                        class="font-label-md text-label-md text-on-surface-variant"
                    >
                        Cambio de contraseña pendiente
                    </dt>
                    <dd class="font-body-md text-body-md text-on-surface">
                        {{ user.must_change_password ? 'Sí' : 'No' }}
                    </dd>
                </div>
                <div class="flex flex-col">
                    <dt
                        class="font-label-md text-label-md text-on-surface-variant"
                    >
                        Roles
                    </dt>
                    <dd class="font-body-md text-body-md text-on-surface">
                        {{
                            user.roles.map((role) => role.name).join(', ') ||
                            'Sin roles'
                        }}
                    </dd>
                </div>
            </dl>

            <div class="flex flex-col gap-space-sm md:flex-row">
                <AppButton v-if="can('users.update')" :href="edit(user.id).url">
                    Editar datos
                </AppButton>
                <AppButton variant="outlined" :href="index().url">
                    Volver a usuarios
                </AppButton>
            </div>
        </AppCard>

        <AppCard v-if="can('users.assign_roles')">
            <form
                class="flex flex-col gap-space-md"
                novalidate
                @submit.prevent="saveRoles"
            >
                <h2 class="font-headline-sm text-headline-sm text-primary">
                    Roles
                </h2>
                <fieldset class="flex flex-col gap-space-xs">
                    <legend class="sr-only">Roles del usuario</legend>
                    <AppCheckbox
                        v-for="role in roles"
                        :key="role.id"
                        v-model="rolesForm.roles"
                        :value="role.id"
                        :label="role.name"
                    />
                    <p
                        v-if="rolesForm.errors.roles"
                        class="font-body-sm text-body-sm text-error"
                        role="alert"
                    >
                        {{ rolesForm.errors.roles }}
                    </p>
                </fieldset>
                <AppButton type="submit" :disabled="rolesForm.processing">
                    Guardar roles
                </AppButton>
            </form>
        </AppCard>

        <AppCard v-if="can('users.reset_password')">
            <form
                class="flex flex-col gap-space-md"
                novalidate
                @submit.prevent="confirmingReset = true"
            >
                <h2 class="font-headline-sm text-headline-sm text-primary">
                    Restablecer contraseña
                </h2>
                <AppInput
                    v-model="passwordForm.password"
                    label="Nueva contraseña temporal"
                    type="password"
                    autocomplete="new-password"
                    required
                    hint="Mínimo 10 caracteres. Se cerrarán las sesiones abiertas de la persona y deberá cambiarla al iniciar sesión."
                    :error="passwordForm.errors.password"
                />
                <AppButton
                    type="submit"
                    variant="danger"
                    :disabled="passwordForm.processing"
                >
                    Restablecer contraseña
                </AppButton>
            </form>
        </AppCard>

        <AppCard v-if="can('users.deactivate')">
            <form
                class="flex flex-col gap-space-md"
                @submit.prevent="changeStatus"
            >
                <h2 class="font-headline-sm text-headline-sm text-primary">
                    Estado de la cuenta
                </h2>
                <p class="font-body-md text-body-md text-on-surface-variant">
                    {{
                        user.is_active
                            ? 'Al desactivar la cuenta se cierran las sesiones de la persona y no podrá ingresar.'
                            : 'La cuenta está inactiva. Al activarla la persona podrá ingresar de nuevo.'
                    }}
                </p>
                <AppButton
                    type="submit"
                    :variant="user.is_active ? 'danger' : 'primary'"
                    :disabled="statusForm.processing"
                >
                    {{
                        user.is_active
                            ? 'Desactivar usuario'
                            : 'Activar usuario'
                    }}
                </AppButton>
            </form>
        </AppCard>

        <ConfirmDialog
            v-model:open="confirmingReset"
            title="Restablecer contraseña"
            message="Se cerrarán las sesiones abiertas de la persona y deberá cambiar la contraseña temporal en su próximo inicio de sesión."
            confirm-label="Restablecer"
            :processing="passwordForm.processing"
            @confirm="resetPassword"
        />
        <ConfirmDialog
            v-model:open="confirmingDeactivate"
            title="Desactivar usuario"
            message="Se cerrarán las sesiones abiertas de la persona y no podrá ingresar al sistema hasta que se active de nuevo."
            confirm-label="Desactivar"
            :processing="statusForm.processing"
            @confirm="deactivateUser"
        />
    </div>
</template>
