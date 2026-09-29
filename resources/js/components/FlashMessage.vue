<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import AppIcon from '@/components/AppIcon.vue';
import IconButton from '@/components/IconButton.vue';

// Reads the native Inertia v3 flash data ({ type, message }) set by the
// backend with Inertia::flash(); there is no custom shared prop.
const page = usePage();

const TYPES = {
    success: {
        classes: 'bg-success-container text-on-success-container',
        icon: 'check_circle',
    },
    error: {
        classes: 'bg-error-container text-on-error-container',
        icon: 'error',
    },
} as const;

const dismissed = ref(false);

// A new flash re-opens the banner.
watch(
    () => page.flash,
    () => {
        dismissed.value = false;
    },
);

const message = computed(() => page.flash?.message ?? null);
const type = computed(() =>
    page.flash?.type === 'error' ? 'error' : 'success',
);
const visible = computed(() => Boolean(message.value) && !dismissed.value);
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
