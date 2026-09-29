<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import AppIcon from '@/components/AppIcon.vue';
import IconButton from '@/components/IconButton.vue';

type FlashType = 'success' | 'error' | 'info';

const props = withDefaults(
    defineProps<{
        // Message text supplied by the backend; nothing is rendered when empty.
        message?: string | null;
        type?: FlashType;
    }>(),
    { message: null, type: 'info' },
);

const TYPES: Record<FlashType, { classes: string; icon: string }> = {
    success: {
        classes: 'bg-success-container text-on-success-container',
        icon: 'check_circle',
    },
    error: {
        classes: 'bg-error-container text-on-error-container',
        icon: 'error',
    },
    info: {
        classes: 'bg-primary-fixed text-on-primary-fixed',
        icon: 'info',
    },
};

const dismissed = ref(false);

// A new message re-opens the banner.
watch(
    () => props.message,
    () => {
        dismissed.value = false;
    },
);

const visible = computed(() => Boolean(props.message) && !dismissed.value);
</script>

<template>
    <div
        v-if="visible"
        :role="type === 'error' ? 'alert' : 'status'"
        class="flex items-center gap-space-sm rounded-lg p-space-sm font-body-md text-body-md"
        :class="TYPES[type].classes"
    >
        <AppIcon :name="TYPES[type].icon" />
        <p class="flex-1">{{ message }}</p>
        <IconButton
            label="Cerrar mensaje"
            icon="close"
            variant="tertiary"
            @click="dismissed = true"
        />
    </div>
</template>
