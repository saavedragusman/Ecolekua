<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import AppButton from '@/components/AppButton.vue';
import AppCard from '@/components/AppCard.vue';
import AppInput from '@/components/AppInput.vue';
import AppTextarea from '@/components/AppTextarea.vue';
import ColorPicker from '@/components/ColorPicker.vue';
import { update } from '@/routes/catalog/values';
import { store } from '@/routes/catalog/attributes/values';
import type {
    CatalogAttributeValue,
    CatalogPresentation,
} from '@/types/products';

// Creates a value of an attribute or edits one (PRD-002). It holds no business rule: the tone only
// shows for the color presentation, and the backend validates the name, the tone and the layer.
const props = defineProps<{
    attributeId: number;
    presentation: CatalogPresentation;
    // The value being edited; null creates a new one.
    value: CatalogAttributeValue | null;
}>();

const emit = defineEmits<{
    done: [];
    cancel: [];
}>();

const form = useForm({
    name: props.value?.name ?? '',
    description: props.value?.description ?? '',
    tone: props.value?.tone ?? '',
    svg_layer: props.value?.svg_layer ?? '',
});

// Empty optional fields are sent as empty strings; the backend turns them into "none" (null). A
// non-color attribute never sends a tone, which the backend prohibits.
form.transform((data) => {
    const { tone, ...rest } = data;

    return props.presentation === 'color' ? { ...rest, tone } : rest;
});

function submit(): void {
    const target = props.value;

    form.submit(
        target === null ? store(props.attributeId) : update(target.id),
        {
            preserveScroll: true,
            onSuccess: () => emit('done'),
        },
    );
}
</script>

<template>
    <form novalidate @submit.prevent="submit">
        <AppCard>
            <h2 class="font-headline-sm text-headline-sm text-primary">
                {{ value === null ? 'Nuevo valor' : 'Editar valor' }}
            </h2>
            <AppInput
                v-model="form.name"
                label="Nombre"
                autocomplete="off"
                :maxlength="100"
                required
                :error="form.errors.name"
            />
            <AppTextarea
                v-model="form.description"
                label="Descripción (opcional)"
                :maxlength="255"
                :rows="3"
                :error="form.errors.description"
            />
            <ColorPicker
                v-if="presentation === 'color'"
                v-model="form.tone"
                label="Tono de referencia"
                hint="Formato #RRGGBB, por ejemplo #1F3A5F."
                :error="form.errors.tone"
            />
            <AppInput
                v-model="form.svg_layer"
                label="Capa del SVG (opcional)"
                autocomplete="off"
                :maxlength="64"
                hint="Minúsculas y guiones, por ejemplo azul-marino. No puede ser cuerpo ni sombras."
                :error="form.errors.svg_layer"
            />
            <!-- Reserved for the value image uploader (Phase 21). -->
            <slot name="image" />
            <div class="flex flex-col gap-space-sm md:flex-row">
                <AppButton type="submit" :disabled="form.processing">
                    Guardar
                </AppButton>
                <AppButton variant="secondary" @click="emit('cancel')">
                    Cancelar
                </AppButton>
            </div>
        </AppCard>
    </form>
</template>
