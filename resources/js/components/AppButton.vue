<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { FOCUS_RING } from '@/lib/ui';

type Variant =
    | 'primary'
    | 'cta'
    | 'secondary'
    | 'inverted'
    | 'outlined'
    | 'danger';

const props = withDefaults(
    defineProps<{
        variant?: Variant;
        // UI-02: rounded-lg in the ERP, rounded-full only in the portal.
        shape?: 'lg' | 'full';
        type?: 'button' | 'submit' | 'reset';
        disabled?: boolean;
        // When set, renders an Inertia link styled as a button.
        href?: string;
    }>(),
    {
        variant: 'primary',
        shape: 'lg',
        type: 'button',
        disabled: false,
        href: undefined,
    },
);

const VARIANTS: Record<Variant, string> = {
    primary: 'bg-primary text-on-primary hover:bg-primary/90',
    cta: 'bg-secondary-container text-primary shadow-glow hover:bg-secondary-fixed-dim',
    secondary:
        'bg-surface-container-high text-on-surface-variant hover:bg-surface-container-highest',
    inverted:
        'bg-inverse-surface text-inverse-on-surface hover:bg-tertiary-container',
    outlined:
        'border border-outline bg-transparent text-on-surface-variant hover:bg-surface-container-low',
    danger: 'bg-error text-on-error hover:bg-on-error-container',
};

const classes = computed(() => [
    'inline-flex min-h-11 w-full items-center justify-center gap-space-xs px-space-lg py-space-sm font-label-lg text-label-lg transition-colors md:w-auto disabled:pointer-events-none disabled:opacity-50',
    props.shape === 'full' ? 'rounded-full' : 'rounded-lg',
    VARIANTS[props.variant],
    FOCUS_RING,
]);
</script>

<template>
    <Link v-if="href" :href="href" :class="classes">
        <slot />
    </Link>
    <button v-else :type="type" :disabled="disabled" :class="classes">
        <slot />
    </button>
</template>
