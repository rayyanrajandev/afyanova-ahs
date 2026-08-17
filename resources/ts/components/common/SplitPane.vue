/**
 * SplitPane — composite component (Volume 1.1 §4.2, §4)
 * =======================================================
 * Resizable panes with proportional scaling, minimum pixel protection,
 * keyboard accessibility, and sleek hover/drag handles.
 *
 * §4.2 Resizing & Ergonomics:
 *   - Proportional ratio model with minSize pixel clamping (280px default).
 *   - Auto-widening support (`applyAutoRatio`) when context updates.
 *   - Sleek 4px handle (`w-1`) with an expanded 16px interactive hitbox.
 *   - Centered pill indicator (`h-8 w-[3px]`) on hover/focus and drag.
 *   - Synced with topbar Display Menu "Reset panel widths" global event.
 *   - Double-click to reset to default ratio.
 *
 * §4.4 Keyboard Accessibility:
 *   - F6 cycles focus between panes.
 *   - Ctrl+1/2 jumps directly to pane 1 or 2.
 *   - Arrow keys resize by 1% (Shift+Arrow by 5%).
 */

<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useI18nSafe } from '@/composables/useI18nSafe';

const { t } = useI18nSafe();

export type SplitDirection = 'horizontal' | 'vertical';

const props = withDefaults(
    defineProps<{
        direction?: SplitDirection;
        initialRatio?: number;
        minSize?: number;
        persistKey?: string;
        collapsible?: boolean;
    }>(),
    {
        direction: 'horizontal',
        initialRatio: 0.3,
        minSize: 280,
        persistKey: undefined,
        collapsible: false,
    },
);

const emit = defineEmits<{
    ratioChange: [ratio: number];
}>();

const STORAGE_PREFIX = 'afyanova:splitpane:';

function loadStoredRatio(): number | null {
    if (!props.persistKey) return null;
    try {
        const raw = localStorage.getItem(`${STORAGE_PREFIX}${props.persistKey}`);
        return raw ? Number(raw) : null;
    } catch {
        return null;
    }
}

const storedRatio = loadStoredRatio();
const ratio = ref(storedRatio ?? props.initialRatio);
const hasUserResized = ref(storedRatio !== null);
const collapsed = ref(false);
const containerRef = ref<HTMLElement | null>(null);
const isDragging = ref(false);
const containerSize = ref(0);

watch(ratio, (r) => {
    if (props.persistKey) {
        try {
            localStorage.setItem(`${STORAGE_PREFIX}${props.persistKey}`, String(r));
        } catch {
            // ignore
        }
    }
    emit('ratioChange', r);
});

// Converts minSize prop (px) into a live ratio bound against current container size
function clampRatio(rawRatio: number): number {
    const total = containerSize.value;
    if (total <= 0) {
        return Math.min(0.8, Math.max(0.1, rawRatio));
    }
    const minRatio = Math.min(0.5, props.minSize / total);
    const maxRatio = 1 - minRatio;
    return Math.min(maxRatio, Math.max(minRatio, rawRatio));
}

const percentBounds = computed(() => {
    const total = containerSize.value;
    if (total <= 0) return { min: 10, max: 80 };
    const minRatio = Math.min(0.5, props.minSize / total);
    return { min: Math.round(minRatio * 100), max: Math.round((1 - minRatio) * 100) };
});

function onPointerDown(event: PointerEvent) {
    event.preventDefault();
    isDragging.value = true;
    document.body.style.cursor = props.direction === 'horizontal' ? 'col-resize' : 'row-resize';
    document.body.style.userSelect = 'none';
    window.addEventListener('pointermove', onPointerMove);
    window.addEventListener('pointerup', onPointerUp);
}

function onPointerMove(event: PointerEvent) {
    if (!isDragging.value || !containerRef.value) return;
    const rect = containerRef.value.getBoundingClientRect();
    const total = props.direction === 'horizontal' ? rect.width : rect.height;
    if (total <= 0) return;
    containerSize.value = total;

    const pos = props.direction === 'horizontal' ? event.clientX - rect.left : event.clientY - rect.top;
    ratio.value = clampRatio(pos / total);
    hasUserResized.value = true;
}

function onPointerUp() {
    isDragging.value = false;
    document.body.style.cursor = '';
    document.body.style.userSelect = '';
    window.removeEventListener('pointermove', onPointerMove);
    window.removeEventListener('pointerup', onPointerUp);
}

function onDoubleClick() {
    resetToDefault();
}

function resetToDefault() {
    ratio.value = clampRatio(props.initialRatio);
    hasUserResized.value = false;
    if (props.persistKey) {
        try {
            localStorage.removeItem(`${STORAGE_PREFIX}${props.persistKey}`);
        } catch {
            // ignore
        }
    }
}

function onHandleKeydown(event: KeyboardEvent) {
    const step = event.shiftKey ? 0.05 : 0.01;
    if (event.key === 'ArrowLeft' || event.key === 'ArrowUp') {
        event.preventDefault();
        ratio.value = clampRatio(ratio.value - step);
        hasUserResized.value = true;
    } else if (event.key === 'ArrowRight' || event.key === 'ArrowDown') {
        event.preventDefault();
        ratio.value = clampRatio(ratio.value + step);
        hasUserResized.value = true;
    } else if (event.key === 'Home') {
        event.preventDefault();
        ratio.value = clampRatio(0);
        hasUserResized.value = true;
    } else if (event.key === 'End') {
        event.preventDefault();
        ratio.value = clampRatio(1);
        hasUserResized.value = true;
    }
}

/**
 * Lets a consumer nudge the ratio toward a target without owning it
 */
function applyAutoRatio(target: number) {
    if (hasUserResized.value) return;
    ratio.value = clampRatio(target);
}

defineExpose({ applyAutoRatio, resetToDefault });

const paneRefs = ref<(HTMLElement | null)[]>([]);
let paneFocusIndex = 0;

function onContainerKeydown(event: KeyboardEvent) {
    if (event.key === 'F6') {
        event.preventDefault();
        paneFocusIndex = (paneFocusIndex + 1) % 2;
        paneRefs.value[paneFocusIndex]?.focus();
    } else if (event.ctrlKey && (event.key === '1' || event.key === '2')) {
        event.preventDefault();
        const idx = Number(event.key) - 1;
        paneRefs.value[idx]?.focus();
    }
}

let resizeObserver: ResizeObserver | null = null;

function measureContainer(): number {
    const rect = containerRef.value?.getBoundingClientRect();
    if (!rect) return 0;
    return props.direction === 'horizontal' ? rect.width : rect.height;
}

onMounted(() => {
    window.addEventListener('keydown', onContainerKeydown);
    window.addEventListener('afyanova:reset-split-panes', resetToDefault);
    window.addEventListener('afyanova:reset-layout', resetToDefault);

    if (containerRef.value) {
        containerSize.value = measureContainer();
        ratio.value = clampRatio(ratio.value);
        resizeObserver = new ResizeObserver(() => {
            containerSize.value = measureContainer();
            ratio.value = clampRatio(ratio.value);
        });
        resizeObserver.observe(containerRef.value);
    }
});

onBeforeUnmount(() => {
    window.removeEventListener('keydown', onContainerKeydown);
    window.removeEventListener('pointermove', onPointerMove);
    window.removeEventListener('pointerup', onPointerUp);
    window.removeEventListener('afyanova:reset-split-panes', resetToDefault);
    window.removeEventListener('afyanova:reset-layout', resetToDefault);
    resizeObserver?.disconnect();
});

const firstPaneStyle = computed(() => {
    if (collapsed.value) {
        return props.direction === 'horizontal' ? { width: '48px' } : { height: '48px' };
    }
    return props.direction === 'horizontal'
        ? { width: `${ratio.value * 100}%` }
        : { height: `${ratio.value * 100}%` };
});

const secondPaneStyle = computed(() => ({ flex: '1' }));
</script>

<template>
    <div
        ref="containerRef"
        class="flex h-full w-full overflow-hidden"
        :class="direction === 'horizontal' ? 'flex-row' : 'flex-col'"
    >
        <!-- First Pane (Left / Top) -->
        <div
            :ref="(el) => { paneRefs[0] = el as HTMLElement | null }"
            class="min-w-0 min-h-0 overflow-auto"
            :class="!isDragging && 'transition-[width] duration-[var(--motion-base)] ease-[var(--ease-standard)]'"
            :style="firstPaneStyle"
            tabindex="0"
            :aria-label="t('splitpane.pane_1')"
        >
            <slot name="start" />
        </div>

        <!-- Sleek Resize Handle Separator with shadcn-vue Tooltip -->
        <Tooltip>
            <TooltipTrigger as-child>
                <div
                    class="group relative flex shrink-0 items-center justify-center transition-colors focus-visible:outline-none"
                    :class="[
                        direction === 'horizontal'
                            ? 'w-1 cursor-col-resize hover:bg-primary/40 focus-visible:bg-primary/50'
                            : 'h-1 cursor-row-resize hover:bg-primary/40 focus-visible:bg-primary/50',
                        isDragging ? 'bg-primary' : 'bg-transparent',
                    ]"
                    role="separator"
                    :aria-orientation="direction === 'horizontal' ? 'vertical' : 'horizontal'"
                    :aria-label="t('splitpane.resize')"
                    :aria-valuenow="Math.round(ratio * 100)"
                    :aria-valuemin="percentBounds.min"
                    :aria-valuemax="percentBounds.max"
                    tabindex="0"
                    @pointerdown="onPointerDown"
                    @dblclick="onDoubleClick"
                    @keydown="onHandleKeydown"
                >
                    <!-- Expanded Hit Target (ensures effortless 16px mouse grabbing) -->
                    <span
                        aria-hidden="true"
                        class="absolute z-10"
                        :class="direction === 'horizontal' ? 'inset-y-0 -left-1.5 -right-1.5' : 'inset-x-0 -top-1.5 -bottom-1.5'"
                    />

                    <!-- Sleek Pill Indicator Handle -->
                    <span
                        aria-hidden="true"
                        class="absolute left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2 rounded-full bg-border-strong transition-all opacity-0 group-hover:opacity-100 group-focus-visible:opacity-100 pointer-events-none"
                        :class="[
                            direction === 'horizontal' ? 'h-8 w-[3px]' : 'w-8 h-[3px]',
                            isDragging ? 'opacity-100 bg-primary-foreground shadow-sm' : '',
                        ]"
                    />
                </div>
            </TooltipTrigger>
            <TooltipContent :side="direction === 'horizontal' ? 'top' : 'right'" :side-offset="8">
                {{ t('splitpane.resize_tooltip') }}
            </TooltipContent>
        </Tooltip>

        <!-- Second Pane (Right / Bottom) -->
        <div
            :ref="(el) => { paneRefs[1] = el as HTMLElement | null }"
            class="min-w-0 min-h-0 overflow-auto"
            :style="secondPaneStyle"
            tabindex="0"
            :aria-label="t('splitpane.pane_2')"
        >
            <slot name="end" />
        </div>
    </div>
</template>