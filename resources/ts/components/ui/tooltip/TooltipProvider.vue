/**
 * TooltipProvider — shadcn-vue component (Volume 1.2 §4.1)
 * ============================================================
 * Added 2026-08-10 (component-library audit) — no Tooltip primitive
 * existed at all despite being documented (Volume 1.2's component table:
 * "Tooltip | shadcn-vue Tooltip | Clinical abbreviation expansion (Volume
 * 0.4 §4.4)"). The collapsed nav rail fell back to the native browser
 * `title` attribute instead — functional, but unstyled, unanimated, and a
 * weaker accessibility contract than a real ARIA tooltip.
 *
 * One TooltipProvider wraps the app (AppShell) rather than one per usage
 * — reka-ui's own recommended pattern, and avoids every tooltip needing
 * its own provider instance.
 */

<script setup lang="ts">
import { TooltipProvider, type TooltipProviderProps } from 'reka-ui';

const props = defineProps<TooltipProviderProps>();
</script>

<template>
  <TooltipProvider v-bind="props">
    <slot />
  </TooltipProvider>
</template>
