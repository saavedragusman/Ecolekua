<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import AppIcon from '@/components/AppIcon.vue';
import logoUrl from '@/../images/logo-blanco.webp';
import { FOCUS_RING_ON_DARK } from '@/lib/ui';
import type { NavSection } from '@/types';

defineProps<{
    // Ungrouped section first (no heading), then one section per group.
    sections: NavSection[];
}>();
</script>

<template>
    <nav
        aria-label="Navegación principal"
        class="hidden w-64 shrink-0 flex-col gap-space-lg overflow-y-auto bg-primary-container p-space-md lg:fixed lg:inset-y-0 lg:left-0 lg:flex"
    >
        <!-- White logo: only valid on this surface, dark in both themes. -->
        <img
            :src="logoUrl"
            alt="Ecolekua"
            class="mx-space-sm h-10 w-auto self-start"
        />

        <div
            v-for="(section, index) in sections"
            :key="section.group ?? 'ungrouped'"
            class="flex flex-col gap-space-xs"
        >
            <h2
                v-if="section.group"
                :id="`sidenav-group-${index}`"
                class="px-space-md font-label-sm text-label-sm text-on-primary-container uppercase"
            >
                {{ section.group }}
            </h2>
            <ul
                class="flex flex-col gap-space-xs"
                :aria-labelledby="
                    section.group ? `sidenav-group-${index}` : undefined
                "
            >
                <li v-for="item in section.items" :key="item.key">
                    <Link
                        :href="item.href"
                        :aria-current="item.active ? 'page' : undefined"
                        class="flex min-h-11 items-center gap-space-sm rounded-lg px-space-md font-label-lg text-label-lg"
                        :class="[
                            item.active
                                ? 'bg-secondary-container text-primary'
                                : 'text-on-primary-container',
                            FOCUS_RING_ON_DARK,
                        ]"
                    >
                        <AppIcon :name="item.icon" />
                        <span>{{ item.label }}</span>
                    </Link>
                </li>
            </ul>
        </div>
    </nav>
</template>
