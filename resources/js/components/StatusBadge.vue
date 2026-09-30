<script setup lang="ts">
import { computed } from 'vue';
import AppIcon from '@/components/AppIcon.vue';

export type BadgeCategory =
    | 'pending'
    | 'active'
    | 'done'
    | 'critical'
    | 'neutral';

const props = withDefaults(
    defineProps<{
        // Visual category (design-system §7.5). Business states and their
        // category are defined by each spec, not here.
        category?: BadgeCategory;
        label?: string;
        // Shortcut for user status: true -> `active`, false -> `neutral`.
        isActive?: boolean;
        icon?: string;
    }>(),
    {
        category: 'neutral',
        label: undefined,
        isActive: undefined,
        icon: undefined,
    },
);

const CATEGORIES: Record<BadgeCategory, string> = {
    pending: 'bg-warning-container text-on-warning-container',
    active: 'bg-primary-fixed text-on-primary-fixed',
    done: 'bg-success-container text-on-success-container',
    critical: 'bg-error-container text-on-error-container',
    neutral: 'bg-surface-container-high text-on-surface-variant',
};

const resolvedCategory = computed<BadgeCategory>(() =>
    props.isActive === undefined
        ? props.category
        : props.isActive
          ? 'active'
          : 'neutral',
);

// A badge always shows text, never color or icon alone.
const text = computed(() => {
    if (props.label !== undefined) {
        return props.label;
    }

    return props.isActive === undefined
        ? ''
        : props.isActive
          ? 'Activo'
          : 'Inactivo';
});
</script>

<template>
    <span
        class="inline-flex items-center gap-space-xs rounded-full px-space-sm py-1 font-label-md text-label-md whitespace-nowrap"
        :class="CATEGORIES[resolvedCategory]"
    >
        <AppIcon v-if="icon" :name="icon" />
        {{ text }}
    </span>
</template>
