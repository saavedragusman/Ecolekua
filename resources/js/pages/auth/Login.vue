<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import AppButton from '@/components/AppButton.vue';
import AppInput from '@/components/AppInput.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { store } from '@/routes/login';

defineOptions({ layout: AuthLayout });

// No remember-me: the login form has no checkbox (design Decision 6).
const form = useForm({
    email: '',
    password: '',
});

function submit(): void {
    form.submit(store(), {
        onFinish: () => form.reset('password'),
    });
}
</script>

<template>
    <Head title="Iniciar sesión" />

    <form
        class="flex flex-col gap-space-md"
        novalidate
        @submit.prevent="submit"
    >
        <h1 class="font-headline-md text-headline-md text-primary">
            Iniciar sesión
        </h1>

        <AppInput
            v-model="form.email"
            label="Correo electrónico"
            type="email"
            autocomplete="username"
            required
            :error="form.errors.email"
        />
        <AppInput
            v-model="form.password"
            label="Contraseña"
            type="password"
            autocomplete="current-password"
            required
            :error="form.errors.password"
        />

        <AppButton type="submit" :disabled="form.processing">
            Ingresar
        </AppButton>
    </form>
</template>
