/**
 * Checkbox — shadcn-vue component (Volume 1.2 §4.1)
 * ===================================================
 * Official CLI-generated shadcn-vue structure, patched to Afyanova tokens:
 *   - `lucide-vue-next` (project icon lib; CLI's `@lucide/vue` is not installed)
 *   - Standard 16px checkbox (`size-4`). Per Volume 0.2 §7.1 the density-aware
 *     `--size-control-*` tokens are for full-height controls (buttons, inputs);
 *     a checkbox is a small toggle that uses the plain spacing scale, matching
 *     shadcn-vue's stock `size-4`.
 *   - `rounded-sm` (--radius-sm) instead of hardcoded 4px
 *
 * v-model target is `modelValue` (`update:modelValue`) — reka-ui's
 * CheckboxRoot, not a `checked` prop. Every call site used to write
 * `v-model:checked` / `:checked` + `@update:checked`, which silently did
 * nothing: the checkbox still toggled its own visual state (reka-ui's
 * internal uncontrolled fallback when it never receives a real bound
 * value), but the click never reached the app's own state at all. Found
 * & fixed across all 4 call sites (Login remember-me, Reception's
 * Schedule filter, DataTable's column/select-all/row checkboxes) during
 * the Reception workspace design-audit fixes, 2026-08-11. Use `v-model`
 * (bare) for a plain boolean, or `:model-value` / `@update:model-value`
 * when the consumer needs custom read/write logic — same as any other
 * reka-ui primitive in this codebase, e.g. Tabs' `v-model` (not
 * `v-model:value`). Indeterminate is not a separate prop either — set
 * `model-value` itself to the string `'indeterminate'`.
 */

<script setup lang="ts">
import { reactiveOmit } from '@vueuse/core';
import { Check } from 'lucide-vue-next';
import type { CheckboxRootEmits, CheckboxRootProps } from 'reka-ui';
import { CheckboxIndicator, CheckboxRoot, useForwardPropsEmits } from 'reka-ui';
import type { HTMLAttributes } from 'vue';
import { cn } from '@/lib/utils';

const props = defineProps<CheckboxRootProps & { class?: HTMLAttributes['class'] }>();
const emits = defineEmits<CheckboxRootEmits>();

const delegatedProps = reactiveOmit(props, 'class');

const forwarded = useForwardPropsEmits(delegatedProps, emits);
</script>

<template>
  <CheckboxRoot
    v-slot="slotProps"
    data-slot="checkbox"
    v-bind="forwarded"
    :class="
      cn(
        'peer border-input data-[state=checked]:bg-primary data-[state=checked]:text-primary-foreground data-[state=checked]:border-primary focus-visible:border-ring focus-visible:ring-ring/50 aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 aria-invalid:border-destructive size-4 shrink-0 rounded-sm border shadow-xs transition-shadow outline-none focus-visible:ring-2 disabled:cursor-not-allowed disabled:opacity-50',
        props.class,
      )
    "
  >
    <CheckboxIndicator
      data-slot="checkbox-indicator"
      class="grid place-content-center text-current transition-none"
    >
      <slot v-bind="slotProps">
        <Check class="size-3.5" />
      </slot>
    </CheckboxIndicator>
  </CheckboxRoot>
</template>