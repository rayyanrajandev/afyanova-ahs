/**
 * DialogContent — shadcn-vue component (Volume 1.2 §4.1, §10)
 * =============================================================
 * Official CLI-generated shadcn-vue structure, patched to Afyanova tokens:
 *   - `lucide-vue-next` (project icon lib; CLI's `@lucide/vue` is not installed)
 *   - `w-[var(--dialog-width-md)]` (Volume 1.2 §10.4) instead of `sm:max-w-lg`
 *     so the width is token-driven (480px standard variant, §10.2)
 *   - `bg-surface-raised` (Afyanova elevated surface) instead of `bg-background`
 *   - Close label via i18n (Volume 0.4 §3.3 — no hardcoded strings)
 *   - `shadow-elevation-lg` (Volume 0.2 §7.4 token) instead of raw `shadow-lg`
 *     — found 2026-08-10 (component-library audit): every floating-layer
 *     primitive (this, AlertDialog, Select, Popover) had been left on
 *     Tailwind's un-tokenized default shadow scale despite this docblock's
 *     own "patched to Afyanova tokens" claim; CommandPalette/Drawer (built
 *     from scratch, not shadcn-generated) already used the real token.
 */

<script setup lang="ts">
import { reactiveOmit } from "@vueuse/core"
import { X } from "lucide-vue-next"
import type { DialogContentEmits, DialogContentProps } from "reka-ui"
import {
  DialogClose,
  DialogContent,
  DialogPortal,
  useForwardPropsEmits,
} from "reka-ui"
import type { HTMLAttributes } from "vue"
import { useI18n } from "vue-i18n"
import { cn } from "@/lib/utils"
import DialogOverlay from "./DialogOverlay.vue"

defineOptions({
  inheritAttrs: false,
})

const props = withDefaults(defineProps<DialogContentProps & { class?: HTMLAttributes["class"], showCloseButton?: boolean }>(), {
  showCloseButton: true,
})
const emits = defineEmits<DialogContentEmits>()

const delegatedProps = reactiveOmit(props, "class")

const forwarded = useForwardPropsEmits(delegatedProps, emits)

const { t } = useI18n()
</script>

<template>
  <DialogPortal>
    <DialogOverlay />
    <DialogContent
      data-slot="dialog-content"
      v-bind="{ ...$attrs, ...forwarded }"
      :class="
        cn(
          'bg-surface-raised data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 data-[state=closed]:zoom-out-95 data-[state=open]:zoom-in-95 fixed top-[50%] left-[50%] z-50 grid w-full max-w-[calc(100%-2rem)] sm:max-w-[var(--dialog-width-md)] translate-x-[-50%] translate-y-[-50%] gap-4 rounded-lg border p-6 shadow-elevation-lg duration-200',
          props.class,
        )"
    >
      <slot />

      <DialogClose
        v-if="showCloseButton"
        data-slot="dialog-close"
        class="ring-offset-background focus:ring-ring data-[state=open]:bg-accent data-[state=open]:text-muted-foreground absolute top-4 right-4 rounded-xs opacity-70 transition-opacity hover:opacity-100 focus:ring-2 focus:ring-offset-2 focus:outline-hidden disabled:pointer-events-none [&_svg]:pointer-events-none [&_svg]:shrink-0 [&_svg:not([class*='size-'])]:size-4"
      >
        <X />
        <span class="sr-only">{{ t('common.close') }}</span>
      </DialogClose>
    </DialogContent>
  </DialogPortal>
</template>