<script setup lang="ts">
withDefaults(
    defineProps<{
        // Adds the hover elevation for cards that act as a target.
        interactive?: boolean;
    }>(),
    { interactive: false },
);
</script>

<template>
    <div
        class="flex flex-col gap-space-sm rounded-xl bg-surface-container-lowest p-space-md shadow-sm md:p-space-lg"
        :class="interactive && 'transition-shadow hover:shadow-xl'"
    >
        <slot />
    </div>
</template>
