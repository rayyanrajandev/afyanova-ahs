/**
 * DialogScrollContent — shadcn-vue component (Volume 1.2 §4.1, §10)
 * ===================================================================
 * Official CLI-generated shadcn-vue structure, patched to Afyanova tokens:
 *   - `lucide-vue-next` (project icon lib; CLI's `@lucide/vue` is not installed)
 *   - `bg-black/[var(--opacity-overlay)]` scrim (Volume 1.2 §10.5)
 *   - `bg-surface-raised` (Afyanova elevated surface) instead of `bg-background`
 *   - `sm:w-[var(--dialog-width-md)]` (Volume 1.2 §10.4) instead of `max-w-lg`
 *   - Close label via i18n (Volume 0.4 §3.3)
 *   - `shadow-elevation-lg` (Volume 0.2 §7.4 token) instead of raw `shadow-lg`
 *     (component-library audit, 2026-08-10 — see DialogContent.vue's note)
 */

<script setup lang="ts">
import { reactiveOmit } from "@vueuse/core"
import { X } from "lucide-vue-next"
import type { DialogContentEmits, DialogContentProps } from "reka-ui"
import {
  DialogClose,
  DialogContent,
  DialogOverlay,
  DialogPortal,
  useForwardPropsEmits,
} from "reka-ui"
import type { HTMLAttributes } from "vue"
import { useI18n } from "vue-i18n"
import { cn } from "@/lib/utils"

defineOptions({
  inheritAttrs: false,
})

const props = defineProps<DialogContentProps & { class?: HTMLAttributes["class"] }>()
const emits = defineEmits<DialogContentEmits>()

const delegatedProps = reactiveOmit(props, "class")

const forwarded = useForwardPropsEmits(delegatedProps, emits)

const { t } = useI18n()
</script>

<template>
  <DialogPortal>
    <DialogOverlay
      class="fixed inset-0 z-50 grid place-items-center overflow-y-auto bg-black/[var(--opacity-overlay)] data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0"
    >
      <DialogContent
        :class="
          cn(
            'relative z-50 grid w-full max-w-[calc(100%-2rem)] sm:w-[var(--dialog-width-md)] my-8 gap-4 border border-border bg-surface-raised p-6 shadow-elevation-lg duration-200 sm:rounded-lg',
            props.class,
          )
        "
        v-bind="{ ...$attrs, ...forwarded }"
        @pointer-down-outside="(event) => {
          const originalEvent = event.detail.originalEvent;
          const target = originalEvent.target as HTMLElement;
          if (originalEvent.offsetX > target.clientWidth || originalEvent.offsetY > target.clientHeight) {
            event.preventDefault();
          }
        }"
      >
        <slot />

        <DialogClose
          class="absolute top-4 right-4 p-0.5 transition-colors rounded-md hover:bg-secondary"
        >
          <X class="w-4 h-4" />
          <span class="sr-only">{{ t('common.close') }}</span>
        </DialogClose>
      </DialogContent>
    </DialogOverlay>
  </DialogPortal>
</template>