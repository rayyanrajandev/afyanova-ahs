/**
 * Textarea — shadcn-vue component (Volume 1.2 §4.1)
 * =====================================================
 * Added 2026-08-10 (component-library audit) — no Textarea primitive
 * existed at all; `clinician/Index.vue` and `nursing/Index.vue` used raw,
 * unstyled HTML textarea elements instead (no focus ring, no
 * token-driven border/radius, nothing matching the rest of the form
 * system). Patched to Afyanova tokens the same way Input.vue already is,
 * so the two share one visual language:
 *   - `bg-surface` (flush with the card — see Input.vue's own docblock,
 *     2026-08-12) instead of `bg-transparent dark:bg-input/30`
 *   - 2px focus ring `--focus-ring-color`/`--focus-ring-offset`
 *     (Volume 1.6 §6), 1px offset (2026-08-12, matches Input.vue) instead
 *     of the CLI's `ring-3`
 *   - `min-h-[var(--size-control-lg)]` (density-aware, Volume 0.2 §7.1)
 *     instead of a hardcoded `min-h-16`, so a 3-density system doesn't
 *     ship a textarea whose minimum height ignores density like the rest
 *     of the control system does
 */

<script setup lang="ts">
import { useVModel } from '@vueuse/core';
import type { HTMLAttributes } from 'vue';
import { cn } from '@/lib/utils';

const props = defineProps<{
  defaultValue?: string;
  modelValue?: string;
  class?: HTMLAttributes['class'];
}>();

const emits = defineEmits<{
  (e: 'update:modelValue', payload: string): void;
}>();

const modelValue = useVModel(props, 'modelValue', emits, {
  passive: true,
  defaultValue: props.defaultValue,
});
</script>

<template>
  <textarea
    v-model="modelValue"
    data-slot="textarea"
    :class="
      cn(
        'placeholder:text-muted-foreground border-input bg-surface field-sizing-content min-h-[var(--size-control-lg)] w-full min-w-0 rounded-md border px-3 py-2 text-base shadow-xs transition-[color,box-shadow,border-color] outline-none disabled:cursor-not-allowed disabled:opacity-50 md:text-sm',
        'hover:border-ring/50',
        'focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-1 focus-visible:ring-offset-background',
        'aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 aria-invalid:border-destructive',
        props.class,
      )
    "
  ></textarea>
</template>
