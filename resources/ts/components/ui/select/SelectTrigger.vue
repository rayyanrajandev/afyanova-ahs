/**
 * SelectTrigger — shadcn-vue component (Volume 1.2 §4.1)
 * ========================================================
 * Official CLI-generated shadcn-vue structure, patched to Afyanova tokens:
 *   - `lucide-vue-next` (project icon lib; CLI's `@lucide/vue` is not installed)
 *   - density-aware `--size-control-*` height (Volume 0.2 §7.1) instead of
 *     the CLI's hardcoded `h-9`/`h-8`
 *   - `bg-surface` (flush with the card, not a gray fill — see Input.vue's
 *     own docblock for the full "admin-dashboard" reasoning, 2026-08-12)
 *     instead of `bg-transparent dark:bg-input/30`
 *   - 2px focus ring (Volume 1.6 §6), 1px offset (2026-08-12, tightened
 *     to match Input.vue) instead of the CLI's `ring-3`
 */

<script setup lang="ts">
import { reactiveOmit } from '@vueuse/core';
import { ChevronDown } from 'lucide-vue-next';
import type { SelectTriggerProps } from 'reka-ui';
import { SelectIcon, SelectTrigger, useForwardProps } from 'reka-ui';
import type { HTMLAttributes } from 'vue';
import { cn } from '@/lib/utils';

const props = withDefaults(
  defineProps<SelectTriggerProps & { class?: HTMLAttributes['class']; size?: 'sm' | 'default' }>(),
  { size: 'default' },
);

const delegatedProps = reactiveOmit(props, 'class', 'size');
const forwardedProps = useForwardProps(delegatedProps);
</script>

<template>
  <SelectTrigger
    data-slot="select-trigger"
    :data-size="size"
    v-bind="forwardedProps"
    :class="
      cn(
        'border-input data-[placeholder]:text-muted-foreground [&_svg:not([class*=\'text-\'])]:text-muted-foreground hover:border-ring/50 focus-visible:border-ring focus-visible:ring-ring aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 aria-invalid:border-destructive bg-surface flex w-fit items-center justify-between gap-2 rounded-md border px-3 py-2 text-sm whitespace-nowrap shadow-xs transition-[color,box-shadow,border-color] outline-none focus-visible:ring-2 focus-visible:ring-offset-1 focus-visible:ring-offset-background disabled:cursor-not-allowed disabled:opacity-50 data-[size=default]:h-[var(--size-control-md)] data-[size=sm]:h-[var(--size-control-sm)] *:data-[slot=select-value]:line-clamp-1 *:data-[slot=select-value]:flex *:data-[slot=select-value]:items-center *:data-[slot=select-value]:gap-2 [&_svg]:pointer-events-none [&_svg]:shrink-0 [&_svg:not([class*=\'size-\'])]:size-4',
        props.class,
      )
    "
  >
    <slot />
    <SelectIcon as-child>
      <ChevronDown class="size-4 opacity-50" />
    </SelectIcon>
  </SelectTrigger>
</template>