<script setup lang="ts">
import { computed, useId } from 'vue';

const props = withDefaults(
    defineProps<{
        label: string;
        // Server-provided message (backend is the validation authority).
        error?: string;
        hint?: string;
        maxlength?: number;
        rows?: number;
        placeholder?: string;
        required?: boolean;
        disabled?: boolean;
    }>(),
    {
        error: undefined,
        hint: undefined,
        maxlength: undefined,
        rows: 4,
        placeholder: undefined,
        required: false,
        disabled: false,
    },
);

const model = defineModel<string>({ default: '' });

const id = useId();
const messageId = computed(() =>
    props.error || props.hint ? `${id}-message` : undefined,
);
</script>

<template>
    <div class="flex flex-col gap-space-xs">
        <label
            :for="id"
            class="font-label-md text-label-md text-on-surface-variant"
            >{{ label }}</label
        >
        <textarea
            :id="id"
            v-model="model"
            :rows="rows"
            :maxlength="maxlength"
            :placeholder="placeholder"
            :required="required"
            :disabled="disabled"
            :aria-invalid="error ? 'true' : undefined"
            :aria-describedby="messageId"
            class="min-h-11 w-full rounded-lg border bg-surface-container-lowest px-space-md py-space-sm font-body-md text-body-md text-on-surface placeholder:text-on-surface-variant focus:border-primary focus:ring-2 focus:ring-primary/20 focus:outline-none disabled:opacity-50"
            :class="error ? 'border-error' : 'border-outline'"
        ></textarea>
        <p
            v-if="error"
            :id="messageId"
            class="font-body-sm text-body-sm text-error"
        >
            {{ error }}
        </p>
        <p
            v-else-if="hint"
            :id="messageId"
            class="font-body-sm text-body-sm text-on-surface-variant"
        >
            {{ hint }}
        </p>
    </div>
</template>
