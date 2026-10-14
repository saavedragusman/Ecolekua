<script setup lang="ts">
import { useId, useTemplateRef, watch, onMounted } from 'vue';
import AppButton from '@/components/AppButton.vue';

// Confirmation for destructive actions (design-system §7.10). Native modal
// <dialog>: showModal() provides the focus trap, Esc (= cancel) and focus
// restoration to the element that opened it. The page decides what happens on
// `confirm`; this component never sends requests.
const props = withDefaults(
    defineProps<{
        title: string;
        // Consequence of the action, in neutral Spanish.
        message: string;
        // Action verb of the confirm button (e.g. "Desactivar").
        confirmLabel: string;
        // Disables the buttons while the request is in flight.
        processing?: boolean;
    }>(),
    { processing: false },
);

const emit = defineEmits<{
    confirm: [];
    cancel: [];
}>();

const open = defineModel<boolean>('open', { default: false });

const id = useId();
const titleId = `${id}-title`;
const messageId = `${id}-message`;

const dialog = useTemplateRef<HTMLDialogElement>('dialog');

function sync(): void {
    const element = dialog.value;

    if (!element) {
        return;
    }

    if (open.value && !element.open) {
        element.showModal();
    } else if (!open.value && element.open) {
        element.close();
    }
}

function cancel(): void {
    if (props.processing) {
        return;
    }

    open.value = false;
    emit('cancel');
}

// The `close` event also fires after Esc: keep the model in sync (Esc = cancel).
function onClose(): void {
    if (open.value) {
        open.value = false;
        emit('cancel');
    }
}

// The content wrapper fills the dialog, so a click whose target is the dialog
// itself landed on the backdrop.
function onDialogClick(event: MouseEvent): void {
    if (event.target === dialog.value) {
        cancel();
    }
}

// Emit first, then close: pages whose `v-model:open` setter clears their target
// on close need that target while handling `confirm`.
function confirm(): void {
    emit('confirm');
    open.value = false;
}

watch(open, sync);
onMounted(sync);
</script>

<template>
    <dialog
        ref="dialog"
        :aria-labelledby="titleId"
        :aria-describedby="messageId"
        class="m-auto w-[calc(100%-2*var(--spacing-gutter-mobile))] max-w-md rounded-xl bg-surface-container-lowest p-0 text-on-surface shadow-xl backdrop:bg-on-surface/40 motion-safe:transition-opacity motion-safe:duration-200 motion-safe:starting:open:opacity-0"
        @click="onDialogClick"
        @close="onClose"
    >
        <div class="flex flex-col gap-space-md p-space-md md:p-space-lg">
            <h2
                :id="titleId"
                class="font-headline-sm text-headline-sm text-primary"
            >
                {{ title }}
            </h2>
            <p
                :id="messageId"
                class="font-body-md text-body-md text-on-surface-variant"
            >
                {{ message }}
            </p>
            <div class="flex flex-col gap-space-sm md:flex-row md:justify-end">
                <AppButton
                    variant="secondary"
                    autofocus
                    :disabled="processing"
                    @click="cancel"
                >
                    Cancelar
                </AppButton>
                <AppButton
                    variant="danger"
                    :disabled="processing"
                    @click="confirm"
                >
                    {{ confirmLabel }}
                </AppButton>
            </div>
        </div>
    </dialog>
</template>
