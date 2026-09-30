<script setup lang="ts">
import { computed, useId } from 'vue';

export type SelectOption = {
    value: string | number;
    label: string;
};

const props = withDefaults(
    defineProps<{
        label: string;
        options: SelectOption[];
        error?: string;
        // Optional empty first option (e.g. "Todos").
        placeholder?: string;
        required?: boolean;
        disabled?: boolean;
    }>(),
    {
        error: undefined,
        placeholder: undefined,
        required: false,
        disabled: false,
    },
);

const model = defineModel<string | number>({ default: '' });

const id = useId();
const messageId = computed(() => (props.error ? `${id}-message` : undefined));
</script>

<template>
    <div class="flex flex-col gap-space-xs">
        <label
            :for="id"
            class="font-label-md text-label-md text-on-surface-variant"
            >{{ label }}</label
        >
        <select
            :id="id"
            v-model="model"
            :required="required"
            :disabled="disabled"
            :aria-invalid="error ? 'true' : undefined"
            :aria-describedby="messageId"
            class="min-h-11 w-full rounded-lg border bg-surface-container-lowest px-space-md font-body-md text-body-md text-on-surface focus:border-primary focus:ring-2 focus:ring-primary/20 focus:outline-none disabled:opacity-50"
            :class="error ? 'border-error' : 'border-outline'"
        >
            <option v-if="placeholder !== undefined" value="">
                {{ placeholder }}
            </option>
            <option
                v-for="option in options"
                :key="option.value"
                :value="option.value"
            >
                {{ option.label }}
            </option>
        </select>
        <p
            v-if="error"
            :id="messageId"
            class="font-body-sm text-body-sm text-error"
        >
            {{ error }}
        </p>
    </div>
</template>
