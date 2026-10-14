<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { ref, useTemplateRef } from 'vue';
import AppButton from '@/components/AppButton.vue';
import ActionErrors from '@/components/catalog/ActionErrors.vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import type { ImageUrls } from '@/types/products';
import type { RouteDefinition } from '@/wayfinder';

// Uploads, replaces and removes the image of a product, a value or a detail location (PRD-020).
// It holds no rule: the backend decodes the file, bounds its size and dimensions, stores the
// optimized variants and audits the change. The image is shown only through `<img src>` pointing at
// the authorized serving route. This component has no <form>, so it can sit inside another form.
const props = defineProps<{
    label: string;
    // Describes the owner for assistive technology, e.g. "Imagen de Camisa corporativa".
    alt: string;
    image: ImageUrls | null;
    uploadTo: RouteDefinition<'post'>;
    removeFrom: RouteDefinition<'delete'>;
}>();

const uploadForm = useForm<{ image: File | null }>({ image: null });
const removeForm = useForm({});
const fileInput = useTemplateRef<HTMLInputElement>('fileInput');
const confirmingRemove = ref(false);

function choose(): void {
    fileInput.value?.click();
}

// Choosing a file sends it at once as multipart data; the input is cleared afterwards so choosing
// the same file again still fires `change`.
function upload(event: Event): void {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];

    if (file === undefined) {
        return;
    }

    uploadForm.image = file;
    uploadForm.clearErrors();
    uploadForm.submit(props.uploadTo, {
        forceFormData: true,
        preserveScroll: true,
        onFinish: () => {
            uploadForm.image = null;
            input.value = '';
        },
    });
}

function remove(): void {
    removeForm.clearErrors();
    removeForm.submit(props.removeFrom, {
        preserveScroll: true,
        onFinish: () => {
            confirmingRemove.value = false;
        },
    });
}
</script>

<template>
    <div class="flex flex-col gap-space-sm">
        <span class="font-label-lg text-label-lg text-on-surface">
            {{ label }}
        </span>
        <img
            v-if="image"
            :src="image.thumb"
            :alt="alt"
            class="size-40 rounded-lg border border-outline bg-surface-container-low object-contain"
        />
        <p v-else class="font-body-md text-body-md text-on-surface-variant">
            Sin imagen.
        </p>
        <input
            ref="fileInput"
            type="file"
            accept="image/jpeg,image/png,image/webp"
            class="hidden"
            tabindex="-1"
            @change="upload"
        />
        <div class="flex flex-col gap-space-sm md:flex-row">
            <AppButton
                variant="outlined"
                :disabled="uploadForm.processing"
                @click="choose"
            >
                {{
                    uploadForm.processing
                        ? 'Subiendo…'
                        : image
                          ? 'Reemplazar imagen'
                          : 'Subir imagen'
                }}
            </AppButton>
            <AppButton
                v-if="image"
                variant="outlined"
                :disabled="removeForm.processing"
                @click="confirmingRemove = true"
            >
                Quitar imagen
            </AppButton>
        </div>
        <p class="font-body-sm text-body-sm text-on-surface-variant">
            JPG, PNG o WebP de hasta 2 MB y con el lado más largo de hasta 1920
            px.
        </p>
        <p
            v-if="uploadForm.errors.image"
            role="alert"
            class="font-body-sm text-body-sm text-error"
        >
            {{ uploadForm.errors.image }}
        </p>
        <ActionErrors :errors="removeForm.errors" />

        <ConfirmDialog
            v-model:open="confirmingRemove"
            title="Quitar imagen"
            message="La imagen se eliminará. Podrá subir otra cuando quiera."
            confirm-label="Quitar"
            :processing="removeForm.processing"
            @confirm="remove"
        />
    </div>
</template>
