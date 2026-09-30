<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted, useTemplateRef, watch } from 'vue';
import AppIcon from '@/components/AppIcon.vue';
import IconButton from '@/components/IconButton.vue';
import { FOCUS_RING } from '@/lib/ui';
import type { NavSection } from '@/types';

// Bottom sheet with the destinations that do not fit in the bottom bar. It is
// a native modal <dialog>: showModal() provides the focus trap, Esc to close
// and focus restoration to the trigger. It is not a hamburger menu (design-system §6.2).
const props = defineProps<{
    open: boolean;
    sections: NavSection[];
}>();

const emit = defineEmits<{
    'update:open': [value: boolean];
}>();

const TITLE_ID = 'more-sheet-title';

const dialog = useTemplateRef<HTMLDialogElement>('dialog');
let stopNavigate: VoidFunction | undefined;

function sync(): void {
    const element = dialog.value;

    if (!element) {
        return;
    }

    if (props.open && !element.open) {
        element.showModal();
    } else if (!props.open && element.open) {
        element.close();
    }
}

function close(): void {
    emit('update:open', false);
}

// The `close` event also fires after Esc, so the model never goes stale.
function onClose(): void {
    if (props.open) {
        close();
    }
}

// The content wrapper fills the dialog, so a click whose target is the dialog
// itself landed on the backdrop.
function onDialogClick(event: MouseEvent): void {
    if (event.target === dialog.value) {
        close();
    }
}

watch(() => props.open, sync);

onMounted(() => {
    sync();
    // The layout persists across visits, so close explicitly on navigation.
    stopNavigate = router.on('navigate', close);
});

onBeforeUnmount(() => {
    stopNavigate?.();
});
</script>

<template>
    <dialog
        ref="dialog"
        :aria-labelledby="TITLE_ID"
        class="fixed inset-x-0 top-auto bottom-0 m-0 max-h-[85dvh] w-full max-w-none translate-y-0 overflow-y-auto rounded-t-xl bg-surface-container-lowest p-0 text-on-surface backdrop:bg-on-surface/40 motion-safe:transition-transform motion-safe:duration-200 lg:hidden motion-safe:starting:open:translate-y-full"
        @click="onDialogClick"
        @close="onClose"
    >
        <div class="flex flex-col gap-space-md p-space-md pb-safe">
            <div class="flex items-center justify-between gap-space-sm">
                <h2
                    :id="TITLE_ID"
                    class="font-headline-sm text-headline-sm text-primary"
                >
                    Más opciones
                </h2>
                <IconButton
                    variant="secondary"
                    icon="close"
                    label="Cerrar"
                    @click="close"
                />
            </div>

            <div
                v-for="(section, index) in sections"
                :key="section.group ?? 'ungrouped'"
                class="flex flex-col gap-space-xs"
            >
                <h3
                    v-if="section.group"
                    :id="`more-sheet-group-${index}`"
                    class="px-space-md font-label-sm text-label-sm text-on-surface-variant uppercase"
                >
                    {{ section.group }}
                </h3>
                <ul
                    class="flex flex-col gap-space-xs"
                    :aria-labelledby="
                        section.group ? `more-sheet-group-${index}` : undefined
                    "
                >
                    <li v-for="item in section.items" :key="item.key">
                        <Link
                            :href="item.href"
                            :aria-current="item.active ? 'page' : undefined"
                            class="flex min-h-12 items-center gap-space-sm rounded-lg px-space-md font-label-lg text-label-lg"
                            :class="[
                                item.active
                                    ? 'bg-primary text-on-primary'
                                    : 'text-on-surface',
                                FOCUS_RING,
                            ]"
                        >
                            <AppIcon :name="item.icon" />
                            <span>{{ item.label }}</span>
                        </Link>
                    </li>
                </ul>
            </div>
        </div>
    </dialog>
</template>
