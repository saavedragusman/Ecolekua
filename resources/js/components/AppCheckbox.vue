<script setup lang="ts">
import { computed, useId } from 'vue';

const props = withDefaults(
    defineProps<{
        label: string;
        // Needed when several checkboxes share one array model.
        value?: string | number;
        error?: string;
        disabled?: boolean;
    }>(),
    {
        value: undefined,
        error: undefined,
        disabled: false,
    },
);

// boolean for a single checkbox, array of values for a group.
const model = defineModel<boolean | Array<string | number>>({
    default: false,
});

const id = useId();
const messageId = computed(() => (props.error ? `${id}-message` : undefined));
</script>

<template>
    <div class="flex flex-col gap-space-xs">
        <label
            :for="id"
            class="flex min-h-11 cursor-pointer items-center gap-space-sm font-body-md text-body-md text-on-surface"
        >
            <input
                :id="id"
                v-model="model"
                type="checkbox"
                :value="value"
                :disabled="disabled"
                :aria-invalid="error ? 'true' : undefined"
                :aria-describedby="messageId"
                class="size-5 shrink-0 rounded accent-primary focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2 focus-visible:ring-offset-surface focus-visible:outline-none"
            />
            <span>{{ label }}</span>
        </label>
        <p
            v-if="error"
            :id="messageId"
            class="font-body-sm text-body-sm text-error"
        >
            {{ error }}
        </p>
    </div>
</template>
