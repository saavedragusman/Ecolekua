<script setup lang="ts">
import { computed } from 'vue';
import BottomNav from '@/components/BottomNav.vue';
import FlashMessage from '@/components/FlashMessage.vue';
import IconButton from '@/components/IconButton.vue';
import SideNav from '@/components/SideNav.vue';
import UserMenu from '@/components/UserMenu.vue';
import { useAppearance } from '@/composables/useAppearance';
import { useNavigation } from '@/composables/useNavigation';

const { appearance, toggleAppearance } = useAppearance();
const { primary, overflow, grouped } = useNavigation();

const isDark = computed(() => appearance.value === 'dark');
</script>

<template>
    <div class="min-h-dvh bg-surface text-on-surface">
        <SideNav :sections="grouped" />

        <div class="lg:pl-64">
            <header
                class="flex items-center justify-end gap-space-sm px-gutter-mobile pt-space-md md:px-gutter"
            >
                <IconButton
                    variant="secondary"
                    :icon="isDark ? 'light_mode' : 'dark_mode'"
                    :label="
                        isDark ? 'Activar modo claro' : 'Activar modo oscuro'
                    "
                    @click="toggleAppearance"
                />
                <UserMenu />
            </header>

            <main
                class="px-gutter-mobile pt-space-md pb-28 md:px-gutter lg:pb-space-xl"
            >
                <FlashMessage class="mb-space-md" />
                <slot />
            </main>
        </div>

        <BottomNav :items="primary" :overflow="overflow" />
    </div>
</template>
