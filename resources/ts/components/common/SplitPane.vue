/**
 * SplitPane — composite component (Volume 1.1 §4.2, §4)
 * =======================================================
 * Resizable panes. The shell provides the handle; workspaces do not.
 *
 * §4.2 Resizing:
 *   - Panes are resizable via drag handles (the shell provides the handle).
 *   - Minimum pane width: 280px (compact) / 320px (comfortable) / 360px (spacious).
 *   - Pane ratios are persisted per workspace per user (P4).
 *   - Double-click a handle to reset to default ratio.
 *
 * §4.3 Collapse & expand:
 *   - Context and detail panes collapse to a rail (icon strip) or fully hide.
 *   - Collapsed state is persisted.
 *   - A collapsed pane expands on hover (peek) or click (pin).
 *
 * §4.4 Keyboard:
 *   - F6 cycles focus between panes.
 *   - Ctrl+1/2/3 jumps to pane 1/2/3 directly.
 *   - Pane resize via keyboard: focus the handle, use Arrow keys (10px per press, Shift+Arrow for 50px).
 *
 * §4.2 minSize enforcement fixed (Reception workspace design audit, 2026-08-11):
 *   The `minSize` prop existed but was never used — drag, keyboard resize,
 *   and the double-click reset all clamped to a flat 10%-80% ratio with
 *   no idea how many actual pixels that was. On a narrow container that
 *   let a pane get dragged well under its documented 280px floor (measured
 *   as low as ~218px), which is exactly what broke the Tabs bar inside it
 *   (see TabsList/TabsTrigger fixes, same audit). `clampRatio()` now
 *   converts `minSize` into a real, live pixel-aware bound against the
 *   container's current size, applied on drag, keyboard step,
 *   double-click reset, mount, and container resize (ResizeObserver) —
 *   so a ratio persisted from a wider window can't leave a pane too
 *   narrow after a reload on a smaller one either.
 *
 * §4.2 Handle affordance (same audit, then reverted): the grip icon was
 *   `opacity-0`, invisible until the user happened to hover this
 *   6px-wide bar. Tried making it visible at rest — in practice that
 *   read as a distracting bar down the middle of the screen, so it's
 *   back to hover/focus-only (2026-08-11, direct user feedback) — but
 *   the icon is no longer horizontal-only (a vertical split showed
 *   nothing at all before, visible or not), and `shrink-0` now stops
 *   the icon getting squashed to the bar's own 6px width when it *is*
 *   showing. aria-valuemin/aria-valuemax now report the real
 *   minSize-derived bound instead of a hardcoded 10/80 that had nothing
 *   to do with it.
 */

<script setup lang="ts">
import { GripHorizontal, GripVertical } from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
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

function loadRatio(): number {
    if (!props.persistKey) return props.initialRatio;
    try {
        const raw = localStorage.getItem(`${STORAGE_PREFIX}${props.persistKey}`);
        return raw ? Number(raw) : props.initialRatio;
    } catch {
        return props.initialRatio;
    }
}

const ratio = ref(loadRatio());
const collapsed = ref(false);
const containerRef = ref<HTMLElement | null>(null);
const isDragging = ref(false);
// Tracked separately (not read fresh via getBoundingClientRect() outside
// of drag) so the aria-valuemin/aria-valuemax bounds below can be a
// reactive computed instead of going stale between resizes.
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

// Converts the minSize prop (px) into a live ratio bound against the
// container's current size, so every place that sets `ratio` clamps to
// real pixels instead of a blind 10%-80% guess. Falls back to that
// blind guess only if the container isn't measurable yet (e.g. before
// mount). Symmetric: the second pane gets the same px floor, capped at
// 50/50 so a container smaller than 2x minSize still degrades cleanly
// instead of inverting the bounds.
function clampRatio(rawRatio: number): number {
    const total = containerSize.value;
    if (total <= 0) {
        return Math.min(0.8, Math.max(0.1, rawRatio));
    }
    const minRatio = Math.min(0.5, props.minSize / total);
    const maxRatio = 1 - minRatio;
    return Math.min(maxRatio, Math.max(minRatio, rawRatio));
}

// Real bounds for the aria-valuemin/aria-valuemax on the separator —
// these used to be a hardcoded 10/80 that had nothing to do with the
// actual minSize floor above.
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
}

function onPointerUp() {
    isDragging.value = false;
    document.body.style.cursor = '';
    document.body.style.userSelect = '';
    window.removeEventListener('pointermove', onPointerMove);
    window.removeEventListener('pointerup', onPointerUp);
}

function onDoubleClick() {
    ratio.value = clampRatio(props.initialRatio);
}

function toggleCollapse() {
    collapsed.value = !collapsed.value;
}

function onHandleKeydown(event: KeyboardEvent) {
    const step = event.shiftKey ? 0.05 : 0.01;
    if (event.key === 'ArrowLeft' || event.key === 'ArrowUp') {
        event.preventDefault();
        ratio.value = clampRatio(ratio.value - step);
    } else if (event.key === 'ArrowRight' || event.key === 'ArrowDown') {
        event.preventDefault();
        ratio.value = clampRatio(ratio.value + step);
    }
}

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

    if (containerRef.value) {
        // A ratio persisted from a wider window could leave a pane
        // under minSize on this one — re-clamp once real dimensions
        // are available, then keep re-clamping as the container itself
        // resizes (sidebar collapse, window resize, etc.).
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
        <div
            :ref="(el) => { paneRefs[0] = el as HTMLElement | null }"
            class="min-w-0 min-h-0 overflow-auto"
            :style="firstPaneStyle"
            tabindex="0"
            :aria-label="t('splitpane.pane_1')"
        >
            <slot name="start" />
        </div>

        <div
            class="group relative flex shrink-0 items-center justify-center bg-transparent transition-colors hover:bg-primary/30 focus-visible:bg-primary/30"
            :class="direction === 'horizontal' ? 'w-1.5 cursor-col-resize' : 'h-1.5 cursor-row-resize'"
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
            <!-- Reverted to hover/focus-only (2026-08-11, direct user
                 feedback): a permanently-visible bar + icon down the
                 middle of the screen read as visual clutter in practice,
                 even though it fixed a real discoverability gap on
                 paper. The handle is invisible at rest now — the cursor
                 still changes to a resize cursor on hover, and drag/
                 keyboard-resize both still work — and only paints in on
                 hover/focus, same as before this file's earlier audit
                 pass. `shrink-0` is kept on the icon regardless: this
                 bar is only 6px wide (`w-1.5`), and without it
                 flexbox's default shrink squashed the 16px icon down to
                 the bar's own width — a real, separate bug unrelated to
                 visibility timing. -->
            <GripVertical
                v-if="direction === 'horizontal'"
                class="h-4 w-4 shrink-0 text-muted-foreground opacity-0 transition-opacity group-hover:opacity-100 group-focus:opacity-100"
                aria-hidden="true"
            />
            <GripHorizontal
                v-else
                class="h-4 w-4 shrink-0 text-muted-foreground opacity-0 transition-opacity group-hover:opacity-100 group-focus:opacity-100"
                aria-hidden="true"
            />
        </div>

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