<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, ref, useTemplateRef } from 'vue';
import AppButton from '@/components/AppButton.vue';
import AppSelect from '@/components/AppSelect.vue';
import ActionErrors from '@/components/catalog/ActionErrors.vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import { destroy, store } from '@/routes/products/templates';
import type { ProductTemplateRow } from '@/types/products';

// Uploads, replaces and removes the SVG templates of a product (PRD-020, E-37..E-41). It holds no
// rule: the backend sanitizes the file, detects the layers, checks the required ones and the gender,
// and audits the change. The template is shown only through `<img src>` pointing at the authorized
// serving route (scripts never run in an image context); the layers listed are the ones the backend
// detected on the sanitized file. This component has no <form>, so it can sit inside another form.
const props = defineProps<{
    productId: number;
    productName: string;
    templates: ProductTemplateRow[];
    // Genders the product admits; empty when it has no gender attribute (only a general template).
    variants: { value: number; label: string }[];
}>();

const uploadForm = useForm<{
    template: File | null;
    variant_value_id: number | string;
}>({ template: null, variant_value_id: '' });
const removeForm = useForm({});
const fileInput = useTemplateRef<HTMLInputElement>('fileInput');
const removing = ref<ProductTemplateRow | null>(null);

const variantOptions = computed(() => props.variants);

function choose(): void {
    fileInput.value?.click();
}

// Choosing a file sends it at once as multipart data with the chosen gender (empty = general); the
// input is cleared afterwards so choosing the same file again still fires `change`.
function upload(event: Event): void {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];

    if (file === undefined) {
        return;
    }

    uploadForm.template = file;
    uploadForm.clearErrors();
    uploadForm
        .transform((data) => ({
            template: data.template,
            variant_value_id:
                data.variant_value_id === '' ? null : data.variant_value_id,
        }))
        .submit(store(props.productId), {
            forceFormData: true,
            preserveScroll: true,
            onFinish: () => {
                uploadForm.template = null;
                input.value = '';
            },
        });
}

function remove(): void {
    if (removing.value === null) {
        return;
    }

    removeForm.clearErrors();
    removeForm.submit(
        destroy({ product: props.productId, template: removing.value.id }),
        {
            preserveScroll: true,
            onFinish: () => {
                removing.value = null;
            },
        },
    );
}
</script>

<template>
    <div class="flex flex-col gap-space-sm">
        <span class="font-label-lg text-label-lg text-on-surface">
            Plantillas de la prenda
        </span>
        <p
            v-if="templates.length === 0"
            class="font-body-md text-body-md text-on-surface-variant"
        >
            Sin plantillas.
        </p>
        <ul v-else class="flex flex-col gap-space-md">
            <li
                v-for="template in templates"
                :key="template.id"
                class="flex flex-col gap-space-sm md:flex-row"
            >
                <img
                    :src="template.url"
                    :alt="`Plantilla ${template.variant ?? 'general'} de ${productName}`"
                    class="size-40 rounded-lg border border-outline bg-surface-container-low object-contain"
                />
                <div class="flex flex-col gap-space-xs">
                    <span class="font-label-md text-label-md text-on-surface">
                        {{
                            template.variant === null
                                ? 'Plantilla general'
                                : `Plantilla para ${template.variant}`
                        }}
                    </span>
                    <span
                        class="font-body-sm text-body-sm text-on-surface-variant"
                    >
                        {{ template.original_name }}
                    </span>
                    <span
                        class="font-body-sm text-body-sm text-on-surface-variant"
                    >
                        Capas detectadas: {{ template.layers.join(', ') }}
                    </span>
                    <div>
                        <AppButton
                            variant="outlined"
                            :disabled="removeForm.processing"
                            @click="removing = template"
                        >
                            Quitar plantilla
                        </AppButton>
                    </div>
                </div>
            </li>
        </ul>

        <AppSelect
            v-if="variantOptions.length > 0"
            v-model="uploadForm.variant_value_id"
            label="Género de la plantilla"
            placeholder="Plantilla general"
            :options="variantOptions"
            :error="uploadForm.errors.variant_value_id"
        />
        <input
            ref="fileInput"
            type="file"
            accept="image/svg+xml,.svg"
            class="hidden"
            tabindex="-1"
            @change="upload"
        />
        <div>
            <AppButton
                variant="outlined"
                :disabled="uploadForm.processing"
                @click="choose"
            >
                {{
                    uploadForm.processing ? 'Subiendo…' : 'Subir plantilla SVG'
                }}
            </AppButton>
        </div>
        <p class="font-body-sm text-body-sm text-on-surface-variant">
            SVG de hasta 2 MB con las capas «cuerpo» y las de cada valor y
            ubicación de detalle del producto. Subir otra para el mismo género
            reemplaza la anterior.
        </p>
        <p
            v-if="uploadForm.errors.template"
            role="alert"
            class="font-body-sm text-body-sm text-error"
        >
            {{ uploadForm.errors.template }}
        </p>
        <ActionErrors :errors="removeForm.errors" />

        <ConfirmDialog
            :open="removing !== null"
            title="Quitar plantilla"
            message="La plantilla se eliminará y la vista previa no la usará. Podrá subir otra cuando quiera."
            confirm-label="Quitar"
            :processing="removeForm.processing"
            @update:open="(value: boolean) => !value && (removing = null)"
            @confirm="remove"
        />
    </div>
</template>
