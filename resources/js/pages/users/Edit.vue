<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import AppButton from '@/components/AppButton.vue';
import AppCard from '@/components/AppCard.vue';
import AppInput from '@/components/AppInput.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { show, update } from '@/routes/users';
import type { UserSummary } from '@/types/users';

defineOptions({ layout: AppLayout });

const props = defineProps<{
    user: UserSummary;
}>();

const form = useForm({
    first_name: props.user.first_name,
    last_name: props.user.last_name,
    email: props.user.email,
});

function submit(): void {
    form.submit(update(props.user.id));
}
</script>

<template>
    <Head title="Editar usuario" />

    <AppCard class="mx-auto w-full max-w-2xl">
        <form
            class="flex flex-col gap-space-md"
            novalidate
            @submit.prevent="submit"
        >
            <h1 class="font-headline-md text-headline-md text-primary">
                Editar usuario
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

            <div class="flex flex-col gap-space-sm md:flex-row">
                <AppButton type="submit" :disabled="form.processing">
                    Guardar cambios
                </AppButton>
                <AppButton variant="outlined" :href="show(user.id).url">
                    Cancelar
                </AppButton>
            </div>
        </form>
    </AppCard>
</template>
