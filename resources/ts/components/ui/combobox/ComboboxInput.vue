<!--
  Patched from the official `shadcn-vue add combobox` output (2026-08-12):
  the generated version wrapped this in its own bordered/icon-prefixed
  "command palette" chrome (fixed h-9, border-bottom, leading search icon)
  and imported that icon from `@lucide/vue` — a second icon package the
  CLI install pulled in, when every other component in this app already
  uses `lucide-vue-next`. Reduced to the bare input: SearchableSelect.vue
  (the actual consumer) provides its own field chrome to match this app's
  Input/Select convention, so keeping this file's own wrapper+icon would
  have doubled up — two borders, a stray icon with no design-system
  counterpart on this app's other form fields. Same "CLI-generate, then
  patch to Afyanova conventions" process this design system's other
  components already document on themselves (see Input.vue).
-->
<script setup lang="ts">
import { reactiveOmit } from "@vueuse/core"
import type { ComboboxInputEmits, ComboboxInputProps } from "reka-ui"
import { ComboboxInput, useForwardPropsEmits } from "reka-ui"
import type { HTMLAttributes } from "vue"
import { cn } from "@/lib/utils"

defineOptions({
  inheritAttrs: false,
})

const props = defineProps<ComboboxInputProps & {
  class?: HTMLAttributes["class"]
}>()

const emits = defineEmits<ComboboxInputEmits>()

const delegatedProps = reactiveOmit(props, "class")

const forwarded = useForwardPropsEmits(delegatedProps, emits)
</script>

<template>
  <ComboboxInput
    data-slot="combobox-input"
    :class="cn(
      'placeholder:text-muted-foreground flex w-full bg-transparent text-sm outline-hidden disabled:cursor-not-allowed disabled:opacity-50',
      props.class,
    )"
    v-bind="{ ...$attrs, ...forwarded }"
  >
    <slot />
  </ComboboxInput>
</template>
