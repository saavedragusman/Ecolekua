<script setup lang="ts">
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppButton from '@/components/AppButton.vue';
import AppCard from '@/components/AppCard.vue';
import AppInput from '@/components/AppInput.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { update } from '@/routes/password';

const page = usePage();

// A pending forced change (FND-014) uses the bare auth shell: nothing else is reachable.
// Otherwise this is the regular own-change page (FND-015) inside the ERP layout.
const forced = computed(
    () => page.props.auth?.user?.must_change_password === true,
);

const form = useForm({
    current_password: '',
    password: '',
    password_confirmation: '',
});

function submit(): void {
    form.submit(update(), {
        onFinish: () =>
            form.reset('current_password', 'password', 'password_confirmation'),
    });
}
</script>

<template>
    <Head title="Cambiar contraseña" />

    <component
        :is="forced ? AuthLayout : AppLayout"
        v-bind="forced ? { title: 'Cambiar contraseña' } : {}"
    >
        <component
            :is="forced ? 'div' : AppCard"
            class="mx-auto w-full max-w-md"
        >
            <form
                class="flex flex-col gap-space-md"
                novalidate
                @submit.prevent="submit"
            >
                <h1
                    v-if="!forced"
                    class="font-headline-md text-headline-md text-primary"
                >
                    Cambiar contraseña
                </h1>

                <AppInput
                    v-model="form.current_password"
                    label="Contraseña actual"
                    type="password"
                    autocomplete="current-password"
                    required
                    :error="form.errors.current_password"
                />
                <AppInput
                    v-model="form.password"
                    label="Nueva contraseña"
                    type="password"
                    autocomplete="new-password"
                    required
                    hint="Mínimo 10 caracteres."
                    :error="form.errors.password"
                />
                <AppInput
                    v-model="form.password_confirmation"
                    label="Confirmar nueva contraseña"
                    type="password"
                    autocomplete="new-password"
                    required
                    :error="form.errors.password_confirmation"
                />

                <AppButton type="submit" :disabled="form.processing">
                    Guardar contraseña
                </AppButton>
            </form>
        </component>
    </component>
</template>
