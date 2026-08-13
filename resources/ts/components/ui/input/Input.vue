/**
 * Input — shadcn-vue component (Volume 1.2 §4.1)
 * =================================================
 * Official CLI-generated shadcn-vue structure, patched to Afyanova tokens:
 *   - `h-[var(--size-control-md)]` (density-aware, Volume 0.2 §7.1)
 *     instead of the CLI's hardcoded `h-9`
 *   - `bg-surface` (flush with the card the input sits on) instead of
 *     `bg-transparent dark:bg-input/30` — changed from an earlier
 *     `bg-input-background` fill (2026-08-12, direct user feedback: "gray
 *     inputs still have a slightly old admin-dashboard appearance").
 *     `--input-background` (97% L) sitting on `--surface` (100% L, pure
 *     white in light mode) reads as a faint gray fill against white —
 *     exactly the tell. Definition now comes entirely from `border-input`
 *     plus the hover/focus states below, the same "flat field, crisp
 *     border" language Linear/Stripe-style inputs use, not the token
 *     itself (a global CSS var change would ripple into every OTHER
 *     `bg-input-background` consumer's own context, not just fields sitting
 *     directly on a white card).
 *   - 2px focus ring `--focus-ring-color`/`--focus-ring-offset` (Volume 1.6 §6)
 *     instead of the CLI's `ring-3`; offset tightened to 1px (2026-08-12,
 *     same feedback pass — "very clear but subtle") so the ring reads as
 *     attached to the field rather than floating a visible gap away from it.
 *   - `file:h-auto` (no hardcoded file-chip height; height is density-driven)
 */

<script setup lang="ts">
import { useVModel } from '@vueuse/core';
import type { HTMLAttributes } from 'vue';
import { cn } from '@/lib/utils';

const props = defineProps<{
  defaultValue?: string | number;
  modelValue?: string | number;
  class?: HTMLAttributes['class'];
}>();

const emits = defineEmits<{
  (e: 'update:modelValue', payload: string | number): void;
}>();

const modelValue = useVModel(props, 'modelValue', emits, {
  passive: true,
  defaultValue: props.defaultValue,
});
</script>

<template>
  <input
    v-model="modelValue"
    data-slot="input"
    :class="
      cn(
        'file:text-foreground placeholder:text-muted-foreground selection:bg-primary selection:text-primary-foreground border-input bg-surface h-[var(--size-control-md)] w-full min-w-0 rounded-md border px-3 py-1 text-base shadow-xs transition-[color,box-shadow,border-color] outline-none file:inline-flex file:h-auto file:border-0 file:bg-transparent file:text-sm file:font-medium disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50 md:text-sm',
        'hover:border-ring/50',
        'focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-1 focus-visible:ring-offset-background',
        'aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 aria-invalid:border-destructive',
        props.class,
      )
    "
  />
</template>