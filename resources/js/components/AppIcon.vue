<script setup lang="ts">
// Material Symbols Outlined glyph (design-system §5). Decorative by default:
// interactive parents provide the accessible name (aria-label / visible text).
defineProps<{
    name: string;
}>();
</script>

<template>
    <span class="material-symbols-outlined" aria-hidden="true">{{ name }}</span>
</template>
