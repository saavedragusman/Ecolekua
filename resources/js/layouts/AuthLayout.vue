<script setup lang="ts">
import AppCard from '@/components/AppCard.vue';

// Shell for guest pages and the forced password change: a single centred card.
defineProps<{
    title?: string;
}>();
</script>

<template>
    <main
        class="flex min-h-dvh items-center justify-center bg-surface px-gutter-mobile py-space-lg md:px-gutter"
    >
        <AppCard class="w-full max-w-md">
            <p class="font-headline-sm text-headline-sm text-primary">
                Ecolekua
            </p>
            <h1
                v-if="title"
                class="font-headline-md text-headline-md text-primary"
            >
                {{ title }}
            </h1>
            <slot />
        </AppCard>
    </main>
</template>
