<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import AppButton from '@/components/AppButton.vue';
import AppCard from '@/components/AppCard.vue';
import AppIcon from '@/components/AppIcon.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { home } from '@/routes';

defineOptions({ layout: AppLayout });
</script>

<template>
    <Head title="Acceso denegado" />

    <AppCard class="mx-auto max-w-md items-center text-center">
        <AppIcon name="lock" class="text-headline-xl text-primary" />
        <h1 class="font-headline-md text-headline-md text-primary">
            Acceso denegado
        </h1>
        <p class="font-body-md text-body-md text-on-surface-variant">
            No tienes permiso para realizar esta acción.
        </p>
        <AppButton :href="home.url()">Volver al inicio</AppButton>
    </AppCard>
</template>
