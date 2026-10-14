<script setup lang="ts">
import { computed, useId } from 'vue';

// Reference tone picker (design-system §7.12; spec 003 §8). A native color input over a swatch plus
// a hex text field. The picker emits `#RRGGBB`; the text field emits what is typed, so a malformed
// tone reaches the backend, which is the validation authority (E-36), and comes back as `error`.
const props = withDefaults(
    defineProps<{
        label: string;
        // Server-provided message.
        error?: string;
        hint?: string;
        disabled?: boolean;
    }>(),
    {
        error: undefined,
        hint: undefined,
        disabled: false,
    },
);

// '' means "no tone".
const model = defineModel<string>({ default: '' });

const HEX_TONE = /^#[0-9A-Fa-f]{6}$/;

const id = useId();
const messageId = computed(() =>
    props.error || props.hint ? `${id}-message` : undefined,
);

const isValid = computed(() => HEX_TONE.test(model.value));

// A color input only accepts `#rrggbb`; with anything else the browser falls back to its own default.
const pickerValue = computed(() =>
    isValid.value ? model.value.toLowerCase() : undefined,
);

function pick(event: Event): void {
    model.value = (event.target as HTMLInputElement).value.toUpperCase();
}
</script>

<template>
    <div class="flex flex-col gap-space-xs">
        <label
            :for="id"
            class="font-label-md text-label-md text-on-surface-variant"
            >{{ label }}</label
        >
        <div class="flex items-center gap-space-sm">
            <span
                class="relative size-11 shrink-0 overflow-hidden rounded-lg border focus-within:ring-2 focus-within:ring-primary focus-within:ring-offset-2 focus-within:ring-offset-surface"
                :class="[
                    error ? 'border-error' : 'border-outline',
                    !isValid && 'bg-surface-container-low',
                    disabled && 'opacity-50',
                ]"
                :style="isValid ? { backgroundColor: model } : undefined"
            >
                <input
                    type="color"
                    :value="pickerValue"
                    :disabled="disabled"
                    :aria-label="`Elegir color: ${label}`"
                    class="absolute inset-0 size-full cursor-pointer opacity-0 disabled:cursor-not-allowed"
                    @input="pick"
                />
            </span>
            <input
                :id="id"
                v-model="model"
                type="text"
                inputmode="text"
                autocomplete="off"
                spellcheck="false"
                :maxlength="7"
                :disabled="disabled"
                :aria-invalid="error ? 'true' : undefined"
                :aria-describedby="messageId"
                class="min-h-11 w-full rounded-lg border bg-surface-container-lowest px-space-md font-body-md text-body-md text-on-surface uppercase placeholder:text-on-surface-variant focus:border-primary focus:ring-2 focus:ring-primary/20 focus:outline-none disabled:opacity-50"
                :class="error ? 'border-error' : 'border-outline'"
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
