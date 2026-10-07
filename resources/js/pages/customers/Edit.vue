<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import CustomerForm from '@/components/customers/CustomerForm.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { show, update } from '@/routes/customers';
import type { CustomerEditable, CustomerFormOptions } from '@/types/customers';

defineOptions({ layout: AppLayout });

// The form always sends the complete state (contact and address included): `PUT` replaces them,
// so omitting one would delete it.
const props = defineProps<
    CustomerFormOptions & {
        customer: CustomerEditable;
    }
>();
</script>

<template>
    <Head title="Editar cliente" />

    <CustomerForm
        title="Editar cliente"
        submit-label="Guardar cambios"
        :cancel-href="show(props.customer.id).url"
        :action="update(props.customer.id)"
        :options="{
            customerTypes: props.customerTypes,
            documentTypes: props.documentTypes,
            states: props.states,
        }"
        :customer="props.customer"
    />
</template>
