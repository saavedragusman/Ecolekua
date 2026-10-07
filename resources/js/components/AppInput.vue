<script setup lang="ts">
import { computed, useId } from 'vue';
import AppIcon from '@/components/AppIcon.vue';

const props = withDefaults(
    defineProps<{
        label: string;
        type?: string;
        // Server-provided message (backend is the validation authority).
        error?: string;
        hint?: string;
        autocomplete?: string;
        // Virtual keyboard hint (e.g. `tel`, `search`); attributes do not fall through to the <input>.
        inputmode?:
            | 'none'
            | 'text'
            | 'tel'
            | 'url'
            | 'email'
            | 'numeric'
            | 'decimal'
            | 'search';
        maxlength?: number;
        // Material Symbols name rendered as a leading icon (design-system §7.4, e.g. search).
        icon?: string;
        placeholder?: string;
        required?: boolean;
        disabled?: boolean;
    }>(),
    {
        type: 'text',
        error: undefined,
        hint: undefined,
        autocomplete: undefined,
        inputmode: undefined,
        maxlength: undefined,
        icon: undefined,
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
        <div class="relative">
            <AppIcon
                v-if="icon"
                :name="icon"
                class="pointer-events-none absolute top-1/2 left-space-md -translate-y-1/2 text-on-surface-variant"
            />
            <input
                :id="id"
                v-model="model"
                :type="type"
                :autocomplete="autocomplete"
                :inputmode="inputmode"
                :maxlength="maxlength"
                :placeholder="placeholder"
                :required="required"
                :disabled="disabled"
                :aria-invalid="error ? 'true' : undefined"
                :aria-describedby="messageId"
                class="min-h-11 w-full rounded-lg border bg-surface-container-lowest px-space-md font-body-md text-body-md text-on-surface placeholder:text-on-surface-variant focus:border-primary focus:ring-2 focus:ring-primary/20 focus:outline-none disabled:opacity-50"
                :class="[
                    error ? 'border-error' : 'border-outline',
                    icon && 'pl-11',
                ]"
            />
        </div>
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
