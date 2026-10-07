<script setup lang="ts">
import { computed, ref, useId } from 'vue';
import AppCheckbox from '@/components/AppCheckbox.vue';
import AppInput from '@/components/AppInput.vue';

export type CheckboxGroupOption = {
    value: string | number;
    label: string;
    disabled?: boolean;
};

// Labelled list of checkboxes sharing one array model (design-system §7.13). The optional filter only
// hides options: a hidden option that is checked stays in the model.
const props = withDefaults(
    defineProps<{
        // Visible group name (the fieldset legend).
        label: string;
        options: CheckboxGroupOption[];
        // Shows a text field that narrows the visible options.
        filterable?: boolean;
        // Server-provided message.
        error?: string;
        hint?: string;
        emptyText?: string;
    }>(),
    {
        filterable: false,
        error: undefined,
        hint: undefined,
        emptyText: 'No hay opciones disponibles.',
    },
);

const model = defineModel<Array<string | number>>({ default: () => [] });

const id = useId();
const messageId = computed(() =>
    props.error || props.hint ? `${id}-message` : undefined,
);

const term = ref('');

// Accent- and case-insensitive match, like the catalog search.
function fold(text: string): string {
    return text
        .normalize('NFD')
        .replace(/\p{M}/gu, '')
        .toLocaleLowerCase()
        .trim();
}

const visible = computed(() => {
    const needle = fold(term.value);

    return needle === ''
        ? props.options
        : props.options.filter((option) => fold(option.label).includes(needle));
});

const statusText = computed(() => {
    if (props.options.length === 0) {
        return props.emptyText;
    }

    return visible.value.length === 0
        ? 'Ninguna opción coincide con el filtro.'
        : null;
});
</script>

<template>
    <fieldset
        class="flex flex-col gap-space-xs"
        :aria-describedby="messageId"
        :aria-invalid="error ? 'true' : undefined"
    >
        <legend class="font-label-md text-label-md text-on-surface-variant">
            {{ label }}
        </legend>

        <AppInput
            v-if="filterable && options.length > 0"
            v-model="term"
            :label="`Filtrar: ${label}`"
            type="search"
            inputmode="search"
            icon="search"
            :maxlength="100"
        />

        <p
            v-if="statusText"
            class="font-body-md text-body-md text-on-surface-variant"
        >
            {{ statusText }}
        </p>
        <ul v-else class="flex flex-col">
            <li v-for="option in visible" :key="option.value">
                <AppCheckbox
                    v-model="model"
                    :value="option.value"
                    :label="option.label"
                    :disabled="option.disabled"
                />
            </li>
        </ul>

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
    </fieldset>
</template>
