<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import CustomerForm from '@/components/customers/CustomerForm.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { index, store } from '@/routes/customers';
import type { CustomerFormOptions } from '@/types/customers';

defineOptions({ layout: AppLayout });

defineProps<CustomerFormOptions>();
</script>

<template>
    <Head title="Nuevo cliente" />

    <CustomerForm
        title="Nuevo cliente"
        submit-label="Registrar cliente"
        :cancel-href="index().url"
        :action="store()"
        :options="{ customerTypes, documentTypes, states }"
    />
</template>
