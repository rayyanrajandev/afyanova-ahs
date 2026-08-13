/**
 * AlertDialogContent — shadcn-vue component (Volume 1.2 §4.1, §10.3)
 * ====================================================================
 * Official CLI-generated shadcn-vue structure, patched to Afyanova tokens:
 *   - `bg-black/[var(--opacity-overlay)]` scrim (Volume 1.2 §10.5)
 *   - `bg-surface-raised` (Afyanova elevated surface) instead of `bg-background`
 *   - `sm:w-[var(--dialog-width-sm)]` (Volume 1.2 §10.4) — confirmation
 *     variant is 400px (§10.2)
 *   - `shadow-elevation-lg` (Volume 0.2 §7.4 token) instead of raw `shadow-lg`
 *     (component-library audit, 2026-08-10 — see DialogContent.vue's note)
 */

<script setup lang="ts">
import { reactiveOmit } from "@vueuse/core"
import type { AlertDialogContentEmits, AlertDialogContentProps } from "reka-ui"
import {
  AlertDialogContent,
  AlertDialogOverlay,
  AlertDialogPortal,
  useForwardPropsEmits,
} from "reka-ui"
import type { HTMLAttributes } from "vue"
import { cn } from "@/lib/utils"

defineOptions({
  inheritAttrs: false,
})

const props = defineProps<AlertDialogContentProps & { class?: HTMLAttributes["class"] }>()
const emits = defineEmits<AlertDialogContentEmits>()

const delegatedProps = reactiveOmit(props, "class")

const forwarded = useForwardPropsEmits(delegatedProps, emits)
</script>

<template>
  <AlertDialogPortal>
    <AlertDialogOverlay
      data-slot="alert-dialog-overlay"
      class="data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 fixed inset-0 z-50 bg-black/[var(--opacity-overlay)]"
    />
    <AlertDialogContent
      data-slot="alert-dialog-content"
      v-bind="{ ...$attrs, ...forwarded }"
      :class="
        cn(
          'bg-surface-raised data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 data-[state=closed]:zoom-out-95 data-[state=open]:zoom-in-95 fixed top-[50%] left-[50%] z-50 grid w-full max-w-[calc(100%-2rem)] sm:w-[var(--dialog-width-sm)] translate-x-[-50%] translate-y-[-50%] gap-4 rounded-lg border p-6 shadow-elevation-lg duration-200',
          props.class,
        )"
    >
      <slot />
    </AlertDialogContent>
  </AlertDialogPortal>
</template>