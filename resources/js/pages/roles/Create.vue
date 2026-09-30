<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import AppButton from '@/components/AppButton.vue';
import AppCard from '@/components/AppCard.vue';
import AppInput from '@/components/AppInput.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { index, store } from '@/routes/roles';

defineOptions({ layout: AppLayout });

const form = useForm({
    name: '',
    description: '',
});

function submit(): void {
    form.submit(store());
}
</script>

<template>
    <Head title="Nuevo rol" />

    <AppCard class="mx-auto w-full max-w-2xl">
        <form
            class="flex flex-col gap-space-md"
            novalidate
            @submit.prevent="submit"
        >
            <h1 class="font-headline-md text-headline-md text-primary">
                Nuevo rol
            </h1>

            <AppInput
                v-model="form.name"
                label="Nombre"
                autocomplete="off"
                required
                :error="form.errors.name"
            />
            <AppInput
                v-model="form.description"
                label="Descripción"
                autocomplete="off"
                hint="Opcional. El rol se crea sin permisos; se asignan desde su detalle."
                :error="form.errors.description"
            />

            <div class="flex flex-col gap-space-sm md:flex-row">
                <AppButton type="submit" :disabled="form.processing">
                    Crear rol
                </AppButton>
                <AppButton variant="outlined" :href="index().url">
                    Cancelar
                </AppButton>
            </div>
        </form>
    </AppCard>
</template>
