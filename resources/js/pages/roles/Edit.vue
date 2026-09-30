<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import AppButton from '@/components/AppButton.vue';
import AppCard from '@/components/AppCard.vue';
import AppInput from '@/components/AppInput.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { show, update } from '@/routes/roles';
import type { RoleSummary } from '@/types/roles';

defineOptions({ layout: AppLayout });

const props = defineProps<{
    role: RoleSummary;
}>();

const form = useForm({
    name: props.role.name,
    description: props.role.description ?? '',
});

function submit(): void {
    form.submit(update(props.role.id));
}
</script>

<template>
    <Head title="Editar rol" />

    <AppCard class="mx-auto w-full max-w-2xl">
        <form
            class="flex flex-col gap-space-md"
            novalidate
            @submit.prevent="submit"
        >
            <h1 class="font-headline-md text-headline-md text-primary">
                Editar rol
            </h1>

            <!-- Convenience only: the backend rejects renaming the protected role (E-23). -->
            <AppInput
                v-model="form.name"
                label="Nombre"
                autocomplete="off"
                required
                :disabled="role.is_protected"
                :hint="
                    role.is_protected
                        ? 'Este rol está protegido: su nombre no se puede cambiar. Sí puede editar la descripción.'
                        : undefined
                "
                :error="form.errors.name"
            />
            <AppInput
                v-model="form.description"
                label="Descripción"
                autocomplete="off"
                :error="form.errors.description"
            />

            <div class="flex flex-col gap-space-sm md:flex-row">
                <AppButton type="submit" :disabled="form.processing">
                    Guardar cambios
                </AppButton>
                <AppButton variant="outlined" :href="show(role.id).url">
                    Cancelar
                </AppButton>
            </div>
        </form>
    </AppCard>
</template>
