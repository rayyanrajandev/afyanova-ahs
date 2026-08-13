<script setup lang="ts">
import CommandPalette from './CommandPalette.vue';
</script>

<template>
  <Story
    title="Shell / CommandPalette"
    group="shell"
    :layout="{ type: 'single', iframe: 500 }"
  >
    <Variant title="Open state">
      <CommandPalette />
    </Variant>
  </Story>
</template>