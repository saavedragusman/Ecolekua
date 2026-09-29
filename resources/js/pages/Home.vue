<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppCard from '@/components/AppCard.vue';
import AppLayout from '@/layouts/AppLayout.vue';

defineOptions({ layout: AppLayout });

const page = usePage();
const firstName = computed(() => page.props.auth?.user?.first_name ?? '');
</script>

<template>
    <Head title="Inicio" />

    <AppCard>
        <h1 class="font-headline-md text-headline-md text-primary">
            Hola, {{ firstName }}
        </h1>
        <p class="font-body-md text-body-md text-on-surface-variant">
            Use el menú para acceder a las opciones disponibles para su usuario.
        </p>
    </AppCard>
</template>
