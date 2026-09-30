<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { FOCUS_RING } from '@/lib/ui';

// Server-computed paging data: the frontend only renders it.
defineProps<{
    currentPage: number;
    lastPage: number;
    prevUrl: string | null;
    nextUrl: string | null;
}>();

const BASE =
    'inline-flex min-h-11 items-center justify-center rounded-lg px-space-lg font-label-lg text-label-lg';
const ENABLED = `bg-surface-container-high text-on-surface-variant hover:bg-surface-container-highest ${FOCUS_RING}`;
const DISABLED = 'bg-surface-container-high text-on-surface-variant opacity-50';
</script>

<template>
    <nav
        aria-label="Paginación"
        class="flex items-center justify-between gap-space-sm"
    >
        <Link
            v-if="prevUrl"
            :href="prevUrl"
            preserve-scroll
            :class="[BASE, ENABLED]"
        >
            Anterior
        </Link>
        <span v-else aria-disabled="true" :class="[BASE, DISABLED]">
            Anterior
        </span>

        <span class="font-body-sm text-body-sm text-on-surface-variant">
            Página {{ currentPage }} de {{ lastPage }}
        </span>

        <Link
            v-if="nextUrl"
            :href="nextUrl"
            preserve-scroll
            :class="[BASE, ENABLED]"
        >
            Siguiente
        </Link>
        <span v-else aria-disabled="true" :class="[BASE, DISABLED]">
            Siguiente
        </span>
    </nav>
</template>
