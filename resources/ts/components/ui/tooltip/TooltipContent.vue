/**
 * TooltipContent — shadcn-vue component (Volume 1.2 §4.1)
 * ===========================================================
 * Official shadcn-vue structure, patched to Afyanova tokens:
 *   - `bg-primary`/`text-primary-foreground` (already-bridged Afyanova
 *     tokens — no change needed there, shadcn's own defaults resolve
 *     correctly through the Tailwind theme bridge)
 *   - `shadow-elevation-md` (Volume 0.2 §7.4 token) added — a tooltip is a
 *     genuine floating/portalled layer ("shadows are reserved for
 *     floating layers"), so it gets one, unlike Card's non-floating
 *     default variant (component-library audit, 2026-08-10 — same pass
 *     that fixed Dialog/AlertDialog/Select/Popover's un-tokenized shadows)
 */

<script setup lang="ts">
import { reactiveOmit } from '@vueuse/core';
import { TooltipContent, TooltipPortal, type TooltipContentEmits, type TooltipContentProps, useForwardPropsEmits } from 'reka-ui';
import type { HTMLAttributes } from 'vue';
import { cn } from '@/lib/utils';

const props = withDefaults(defineProps<TooltipContentProps & { class?: HTMLAttributes['class'] }>(), {
  sideOffset: 4,
});
const emits = defineEmits<TooltipContentEmits>();

const delegatedProps = reactiveOmit(props, 'class');
const forwarded = useForwardPropsEmits(delegatedProps, emits);
</script>

<template>
  <TooltipPortal>
    <TooltipContent
      data-slot="tooltip-content"
      v-bind="forwarded"
      :class="
        cn(
          'bg-primary text-primary-foreground animate-in fade-in-0 zoom-in-95 data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=closed]:zoom-out-95 data-[side=bottom]:slide-in-from-top-2 data-[side=left]:slide-in-from-right-2 data-[side=right]:slide-in-from-left-2 data-[side=top]:slide-in-from-bottom-2 z-50 w-fit rounded-md px-3 py-1.5 text-xs text-balance shadow-elevation-md',
          props.class,
        )
      "
    >
      <slot />
    </TooltipContent>
  </TooltipPortal>
</template>
