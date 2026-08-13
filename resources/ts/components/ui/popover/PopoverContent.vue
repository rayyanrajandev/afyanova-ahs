<script setup lang="ts">
/**
 * PopoverContent — shadcn-vue component
 * =======================================
 * `shadow-elevation-md` (Volume 0.2 §7.4 token), not raw `shadow-md` —
 * component-library audit, 2026-08-10 (see DialogContent.vue's note; same
 * fix applied across Dialog/AlertDialog/Select/Popover). Also: this file
 * had a stray leading "U" character before `<script setup>` (harmless —
 * Vue's SFC compiler ignores content outside recognized blocks, and this
 * component was already in live use — but real, pre-existing corruption,
 * removed while already editing this exact line for the shadow fix.
 *
 * `PopoverPortal` added 2026-08-11 (found building DatePicker.vue, live-
 * measured not guessed): unlike SelectContent.vue (already wraps in
 * SelectPortal), this had no portal — content stayed nested wherever it
 * was opened in the DOM instead of escaping to document.body. Usually
 * harmless, but inside a form nested two `<main>` landmarks deep (Reception's
 * own resizable pane + this workspace's page main), floating-ui's
 * boundary/clipping-ancestor detection computed `left`/`top` relative to
 * the inner landmark's own box while still applying `position: fixed`
 * (viewport-relative) — confirmed via getBoundingClientRect() and the
 * computed `--reka-popper-available-width` custom property matching that
 * inner element's width, not the viewport's: the popup rendered pinned to
 * the browser's top-left corner, nowhere near its trigger. Portaling to
 * document.body removes the ambiguous nesting entirely — same fix
 * SelectContent already relies on, now applied consistently.
 */
import { PopoverContent, PopoverPortal, type PopoverContentEmits, type PopoverContentProps } from 'reka-ui';
import type { HTMLAttributes } from 'vue';
import { cn } from '@/lib/utils';

interface PopoverContentPropsWithClass extends PopoverContentProps {
    class?: HTMLAttributes['class'];
}

const props = withDefaults(defineProps<PopoverContentPropsWithClass>(), {
    align: 'center',
    sideOffset: 4,
});

const emits = defineEmits<PopoverContentEmits>();
</script>

<template>
    <PopoverPortal>
        <PopoverContent
            v-bind="props"
            :class="cn(
                'z-50 w-72 rounded-md border border-border bg-popover p-4 text-popover-foreground shadow-elevation-md outline-none',
                'data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 data-[state=closed]:zoom-out-95 data-[state=open]:zoom-in-95',
                'data-[side=bottom]:slide-in-from-top-2 data-[side=left]:slide-in-from-right-2 data-[side=right]:slide-in-from-left-2 data-[side=top]:slide-in-from-bottom-2',
                props.class,
            )"
            @open-auto-focus="emits('openAutoFocus', $event)"
            @close-auto-focus="emits('closeAutoFocus', $event)"
            @escape-key-down="emits('escapeKeyDown', $event)"
            @pointer-down-outside="emits('pointerDownOutside', $event)"
            @focus-outside="emits('focusOutside', $event)"
            @interact-outside="emits('interactOutside', $event)"
        >
            <slot />
        </PopoverContent>
    </PopoverPortal>
</template>