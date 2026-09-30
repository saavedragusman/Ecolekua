<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import AppButton from '@/components/AppButton.vue';
import AppCard from '@/components/AppCard.vue';
import AppCheckbox from '@/components/AppCheckbox.vue';
import AppInput from '@/components/AppInput.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { index, store } from '@/routes/users';
import type { RoleOption } from '@/types/users';

defineOptions({ layout: AppLayout });

defineProps<{
    roles: RoleOption[];
}>();

const form = useForm<{
    first_name: string;
    last_name: string;
    email: string;
    password: string;
    roles: number[];
}>({
    first_name: '',
    last_name: '',
    email: '',
    password: '',
    roles: [],
});

function submit(): void {
    form.submit(store(), {
        onFinish: () => form.reset('password'),
    });
}
</script>

<template>
    <Head title="Nuevo usuario" />

    <AppCard class="mx-auto w-full max-w-2xl">
        <form
            class="flex flex-col gap-space-md"
            novalidate
            @submit.prevent="submit"
        >
            <h1 class="font-headline-md text-headline-md text-primary">
                Nuevo usuario
            </h1>

            <AppInput
                v-model="form.first_name"
                label="Nombre"
                autocomplete="off"
                required
                :error="form.errors.first_name"
            />
            <AppInput
                v-model="form.last_name"
                label="Apellido"
                autocomplete="off"
                required
                :error="form.errors.last_name"
            />
            <AppInput
                v-model="form.email"
                label="Correo electrónico"
                type="email"
                autocomplete="off"
                required
                :error="form.errors.email"
            />
            <AppInput
                v-model="form.password"
                label="Contraseña temporal"
                type="password"
                autocomplete="new-password"
                required
                hint="Mínimo 10 caracteres. La persona deberá cambiarla al iniciar sesión."
                :error="form.errors.password"
            />

            <fieldset class="flex flex-col gap-space-xs">
                <legend
                    class="font-label-md text-label-md text-on-surface-variant"
                >
                    Roles (al menos uno)
                </legend>
                <AppCheckbox
                    v-for="role in roles"
                    :key="role.id"
                    v-model="form.roles"
                    :value="role.id"
                    :label="role.name"
                />
                <p
                    v-if="form.errors.roles"
                    class="font-body-sm text-body-sm text-error"
                    role="alert"
                >
                    {{ form.errors.roles }}
                </p>
            </fieldset>

            <div class="flex flex-col gap-space-sm md:flex-row">
                <AppButton type="submit" :disabled="form.processing">
                    Crear usuario
                </AppButton>
                <AppButton variant="outlined" :href="index().url">
                    Cancelar
                </AppButton>
            </div>
        </form>
    </AppCard>
</template>
