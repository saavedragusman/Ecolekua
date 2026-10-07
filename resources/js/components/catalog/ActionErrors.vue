<script setup lang="ts">
import { computed } from 'vue';

// Messages the backend returned for a request that has no field to show them on (move, activate and
// deactivate buttons): without this, a rejected action would fail silently. The keys are ignored;
// the backend words the message.
const props = defineProps<{
    errors: Partial<Record<string, string>>;
}>();

const messages = computed(() =>
    Object.values(props.errors).filter(
        (message): message is string => typeof message === 'string',
    ),
);
</script>

<template>
    <div
        v-if="messages.length > 0"
        role="alert"
        class="flex flex-col gap-space-xs rounded-lg bg-error-container p-space-md font-body-md text-body-md text-on-error-container"
    >
        <p v-for="(message, index) in messages" :key="index">
            {{ message }}
        </p>
    </div>
</template>
