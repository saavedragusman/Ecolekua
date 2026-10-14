<script setup lang="ts">
import { computed } from 'vue';
import AppIcon from '@/components/AppIcon.vue';
import { FOCUS_RING } from '@/lib/ui';

// `reorder` is the discreet circular up/down arrow of sortable lists: a 32px
// visual circle inside a 44px hit area.
type Variant = 'primary' | 'secondary' | 'tertiary' | 'danger' | 'reorder';

const props = withDefaults(
    defineProps<{
        // Spanish accessible name; mandatory for icon-only buttons (§8.2).
        label: string;
        icon: string;
        variant?: Variant;
        type?: 'button' | 'submit' | 'reset';
        disabled?: boolean;
    }>(),
    {
        variant: 'primary',
        type: 'button',
        disabled: false,
    },
);

const VARIANTS: Record<Variant, string> = {
    primary: 'rounded-full bg-primary text-on-primary',
    secondary: 'rounded-full bg-secondary text-on-secondary',
    tertiary: 'rounded-full bg-tertiary text-on-tertiary',
    danger: 'rounded-full bg-error text-on-error',
    reorder: 'group rounded-full text-on-surface-variant',
};

const classes = computed(() => [
    'inline-flex size-11 shrink-0 items-center justify-center transition-colors disabled:pointer-events-none disabled:opacity-50',
    VARIANTS[props.variant],
    FOCUS_RING,
]);
</script>

<template>
    <button
        :type="type"
        :disabled="disabled"
        :aria-label="label"
        :title="label"
        :class="classes"
    >
        <span
            v-if="variant === 'reorder'"
            class="inline-flex size-8 items-center justify-center rounded-full bg-surface-container-high transition-colors group-hover:bg-surface-container-highest"
        >
            <!-- `!` wins over the unlayered font-size of the Material Symbols stylesheet. -->
            <AppIcon :name="icon" class="text-lg!" />
        </span>
        <AppIcon v-else :name="icon" />
    </button>
</template>
