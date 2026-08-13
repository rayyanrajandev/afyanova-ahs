/**
 * Drawer — composite component (Volume 1.1 §4.2, §5)
 * ====================================================
 * Overlay panels that slide in from an edge. Used for transient, focused tasks.
 *
 * §5.1 Drawer tokens:
 *   --drawer-width-sm: 320px | md: 480px | lg: 640px | xl: 800px
 *
 * §5.2 Behavior:
 *   - Drawers slide from the inline-end edge (right in LTR) by default.
 *   - Scrim opacity: --opacity-overlay (0.5).
 *   - Focus is trapped in the drawer while open (Volume 0.3 §6.3).
 *   - Esc closes; focus returns to the trigger (P4).
 *   - Multiple drawers can stack; Esc closes the topmost.
 *   - Drawers use --motion-enter / --motion-exit (Volume 0.2 §8.3).
 *
 * §5.3 Drawer vs pane:
 *   Use a drawer when content is a transient task the user completes and dismisses.
 */

<script setup lang="ts">
import { X } from 'lucide-vue-next';
import { DrawerContent, DrawerOverlay, DrawerRoot, DrawerTitle } from 'vaul-vue';
import { computed } from 'vue';
import { useI18nSafe } from '@/composables/useI18nSafe';

export type DrawerSize = 'sm' | 'md' | 'lg' | 'xl';

const props = withDefaults(
    defineProps<{
        open: boolean;
        title?: string;
        size?: DrawerSize;
        side?: 'end' | 'start';
    }>(),
    {
        title: undefined,
        size: 'md',
        side: 'end',
    },
);

const emit = defineEmits<{
    'update:open': [open: boolean];
    close: [];
}>();

const { t } = useI18nSafe();

const sizeClass: Record<DrawerSize, string> = {
    sm: 'w-[320px]',
    md: 'w-[480px]',
    lg: 'w-[640px]',
    xl: 'w-[800px]',
};

// §5.2 — drawers slide from the inline-end edge (right in LTR) by default;
// start-edge is used for context panels.
const edgeClasses = computed(() =>
    props.side === 'start'
        ? { direction: 'left' as const, positionClass: 'left-0 border-r', handleClass: '' }
        : { direction: 'right' as const, positionClass: 'right-0 border-l', handleClass: '' },
);

function onOpenChange(open: boolean) {
    emit('update:open', open);
    if (!open) emit('close');
}
</script>

<template>
    <DrawerRoot
        :open="open"
        :direction="edgeClasses.direction"
        @update:open="onOpenChange"
    >
        <DrawerOverlay class="fixed inset-0 z-[var(--z-drawer)] bg-black/50" />
        <DrawerContent
            class="fixed inset-y-0 z-[var(--z-drawer)] flex flex-col border-border bg-surface shadow-elevation-lg"
            :class="[sizeClass[size], edgeClasses.positionClass]"
        >
            <div class="flex items-center justify-between border-b border-border px-4 py-3">
                <DrawerTitle class="text-sm font-semibold text-foreground">
                    {{ title }}
                </DrawerTitle>
                <button
                    type="button"
                    class="focus-ring rounded-md p-1.5 text-muted-foreground hover:bg-muted hover:text-foreground"
                    :aria-label="t('common.close')"
                    @click="onOpenChange(false)"
                >
                    <X class="h-4 w-4" aria-hidden="true" />
                </button>
            </div>

            <div class="flex-1 overflow-auto p-4">
                <slot />
            </div>

            <div v-if="$slots.footer" class="border-t border-border px-4 py-3">
                <slot name="footer" />
            </div>
        </DrawerContent>
    </DrawerRoot>
</template>