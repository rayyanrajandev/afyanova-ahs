/**
 * Queue — composite component (Volume 1.2 §4.2, §9)
 * ===================================================
 * Prioritized worklist — patients waiting, lab samples pending, etc.
 * Built on top of shadcn-vue primitives (Volume 1.2 §2.1).
 *
 * Anatomy (Volume 1.2 §9.2, redesigned 2026-08-11 — see note below):
 *   [ All 32 ] [ *Critical 2 ] [ Urgent 5 ] [ Normal 25 ]   [ ⚙ 2 ]
 *   ----------------------------------------------------
 *   ~  [JM] John Mwangi                   Pending  >
 *        Outpatient   10 min
 *   ~  [SJ] Sarah Joseph                  Pending  >
 *        Outpatient   25 min
 *   ~  [AH] Ali Hassan                     Pending  >
 *        Outpatient   45 min
 *   ----------------------------------------------------
 *   [ Queue Footer (actions) ]
 *
 *  * = live critical pulse (animated, on the Critical chip's own dot)
 *  ~ = drag handle (GripVertical, revealed on hover/focus)
 *  [JM] = avatar initials with priority-colored ring + status dot
 *  ⚙ 2 = icon-only Sort/Status/Category trigger, badge = active count
 *
 * Header: one toolbar row — priority chips (left) + the Sort/Status/
 *   Category trigger (right). No separate title or "N patients" line.
 *
 * Filters popover redesigned 2026-08-11 (direct user feedback: the
 *   original — three stacked vertical radio-button lists under plain
 *   text labels — read as a bare HTML settings form, not a 2027 SaaS
 *   filter). Sort is now a 3-way segmented control (matches the visual
 *   language of the priority chips below it); Status/Category are now
 *   `Select` dropdowns (single-line trigers showing the current value
 *   directly, colored status dots reusing StatusBadge's own palette,
 *   built-in checkmark on the selected item) instead of a 5-row radio
 *   list each — collapses what used to be ~11 stacked rows into 3
 *   compact controls. "Clear" moved into the popover's own header row
 *   next to its title, a common modern-dropdown placement, instead of
 *   a full-width button stacked below everything else. Sort still
 *   lives inside the popover rather than as its own separate trigger —
 *   this pane can be as narrow as 280px, and a second always-visible
 *   trigger next to the priority chips risked the exact overflow bug
 *   already fixed once this session. Sort's segments have `truncate` +
 *   `min-w-0` (not just `flex-1`) — found live in Swahili: "Muda wa
 *   kusubiri" (Wait time) wrapped to 2 lines inside its segment,
 *   making that one button taller than its siblings and visibly
 *   breaking the segmented control's shape. Same fix as the Tabs
 *   overflow work: shrink and ellipsize, never wrap.
 *
 * Header collapsed from 3 rows to 1 (same 2026-08-11 pass, direct user
 *   feedback: "why chips, title, more filters — is this modern 2027?").
 *   The bold "Waiting Queue" title and the "N patients" count line
 *   both sat directly under a tab that already says "Queue"/"Foleni" —
 *   pure restatement. Worse, nursing reuses this exact component for
 *   its Tasks tab: those two lines rendered as "Waiting Queue" / "N
 *   patients" *inside a task list*, which isn't redundant, it's wrong.
 *   Removed both; the "All" chip already carries the same total count.
 *   The live critical-pulse moved onto the Critical chip's own dot —
 *   the alert now lives on the exact control it's about. The Sort/
 *   Status/Category trigger dropped its "More Filters" text label for
 *   an icon-only button (`SlidersHorizontal`, `aria-label` carries the
 *   accessible name) now that it shares a row with the chips instead
 *   of sitting alone next to a title.
 *
 * Row layout: drag handle | avatar | name+secondary | StatusBadge | chevron
 *   - Avatar: initials, priority-colored ring, live status dot
 *   - Secondary line: category (if any) + wait time (Clock icon, threshold-colored)
 *   - StatusBadge: workflow status (pending/in_progress/complete/cancelled)
 *   - Chevron: open affordance (revealed on hover/focus)
 *
 * States: loaded (rows), loading (skeleton), empty (icon + hint + clear button)
 *
 * Features (Volume 1.2 §9.3): priority, drag-to-reorder (persisted),
 *   auto-updating wait time, StatusBadge per item, filter (priority/status/category),
 *   sort (priority/wait/name), real-time aria-live, keyboard nav + Ctrl+Up/Down reorder,
 *   virtualization for > 50 items.
 *
 * Clinical rules (§9.4): critical items always on top with critical border;
 *   wait-time threshold coloring (amber -> red); reordering emits `reorder`
 *   (parent logs to audit trail).
 */

<script setup lang="ts">
import { useVirtualizer, type VirtualItem } from '@tanstack/vue-virtual';
import {
    ArrowUpDown,
    ChevronRight,
    Clock,
    GripVertical,
    SlidersHorizontal,
} from 'lucide-vue-next';
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import EmptyState from '@/components/common/EmptyState.vue';
import StatusBadge from '@/components/common/StatusBadge.vue';
import type { StatusType } from '@/components/common/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useI18nSafe } from '@/composables/useI18nSafe';

export type QueuePriority = 'critical' | 'urgent' | 'normal';
// 'incoming' is deliberately excluded from `sortOptions` below (like
// 'manual') — it's a *default* a consumer opts into via `defaultSort`, not
// a user-facing radio choice. It means "trust the order `items` arrived
// in" (e.g. a backend that already applies its own ordering rule the
// generic priority/wait/name sorts can't express — Volume 2.1 §10.2's
// emergency > scheduled > walk-in tiering is the motivating case).
export type QueueSort = 'priority' | 'wait' | 'name' | 'manual' | 'incoming';

export interface QueueItem {
    id: string;
    name: string;
    priority: QueuePriority;
    waitTime: string;
    waitMinutes: number;
    status?: StatusType; // explicit status; falls back to wait-based
    category?: string;   // optional category for filtering
}

interface DisplayRow {
    item: QueueItem;
    index: number; // position in sortedItems
    virtual?: VirtualItem; // present when virtualizing
}

const props = withDefaults(
    defineProps<{
        items: QueueItem[];
        filter?: QueuePriority | 'all';
        loading?: boolean; // shows skeleton rows instead of an empty flash
        defaultSort?: QueueSort; // initial `activeSort`; still user-overridable via the Filters popover
        // Section-headers-by-category (Volume 3.7 audit, 2026-08-10) — opt-in,
        // additive, off by default so every existing consumer (clinician,
        // nursing) is byte-identical unless they ask for this. Motivated by
        // reception's arrival-mode tiering (§10.2): grouping makes the tier
        // a patient is in something you *see*, not a small text label you
        // have to read on every row. Not virtualization-aware yet (see
        // `displayOrderItems` below) — falls back to the flat view past the
        // 50-item virtualization threshold rather than half-implementing
        // windowed group headers.
        groupByCategory?: boolean;
        // Priority chips opt-out (Reception audit, 2026-08-12) — off by
        // default so nursing/clinician (post-triage acuity is meaningful
        // for them) stay byte-identical. Reception passes this true: its
        // queue is `stage=waiting_triage` (pre-triage — no real acuity
        // exists yet), and `priority` here is a wait-time bucket
        // (queueStore.ts's `toTask()`), not clinical severity. That made
        // the chips actively misleading — a just-arrived Emergency patient
        // (0 min wait) showed "Normal", while an hour-long-waiting
        // Scheduled patient showed "Critical". Reception's real urgency
        // signal is arrival-mode tiering (already the section-header
        // grouping/sort order above), already reachable as a Category
        // filter inside the Filters popover this prop leaves untouched.
        hidePriorityChips?: boolean;
    }>(),
    {
        filter: 'all',
        loading: false,
        defaultSort: 'priority',
        groupByCategory: false,
        hidePriorityChips: false,
    },
);

const emit = defineEmits<{
    open: [item: QueueItem];
    reorder: [ordered: QueueItem[]]; // audit trail — parent logs (Volume 1.5)
}>();

const { t } = useI18nSafe();

// ---- Local mutable copy (auto-updating wait time, drag-reorder) ----
const itemsReactive = ref<QueueItem[]>(props.items.map((i) => ({ ...i })));
watch(
    () => props.items,
    (items) => {
        // Only reset if the data actually changed (avoid clobbering local reorders)
        const sameOrder =
            itemsReactive.value.length === items.length &&
            itemsReactive.value.every((i, idx) => i.id === items[idx]?.id);
        if (!sameOrder) {
            itemsReactive.value = items.map((i) => ({ ...i }));
        }
    },
);

// ---- Filter (priority + status + category — one popover, §9.3) ----
const activeFilter = ref<QueuePriority | 'all'>(props.filter);
const filters: (QueuePriority | 'all')[] = ['all', 'critical', 'urgent', 'normal'];

const activeStatus = ref<StatusType | 'all'>('all');
const statusFilters: (StatusType | 'all')[] = [
    'all',
    'pending',
    'in_progress',
    'complete',
    'cancelled',
];

const activeCategory = ref<string>('all');
const categories = computed(() => {
    const cats = new Set<string>();
    itemsReactive.value.forEach((i) => {
        if (i.category) cats.add(i.category);
    });
    return ['all', ...cats];
});

// Counts per priority chip (component-library audit, 2026-08-10) — a chip
// that just says "Critical" gives no sense of scale; "Critical 2" does.
// Computed against status/category already applied but *not* priority
// itself (standard faceted-filter behavior: a facet's own counts reflect
// its sibling facets, never its own current selection, or picking any
// option would make every other option's count collapse to 0).
const priorityCounts = computed<Record<QueuePriority | 'all', number>>(() => {
    const base = itemsReactive.value.filter((i) => {
        if (activeStatus.value !== 'all' && (i.status ?? 'pending') !== activeStatus.value) return false;
        if (activeCategory.value !== 'all' && i.category !== activeCategory.value) return false;
        return true;
    });
    const counts: Record<QueuePriority | 'all', number> = { all: base.length, critical: 0, urgent: 0, normal: 0 };
    for (const item of base) counts[item.priority]++;
    return counts;
});

// Badge count on the Filters button — priority is already visible as chips,
// so only status/category (the facets hidden inside the popover) count here.
const activeFilterCount = computed(() => {
    let n = 0;
    if (activeStatus.value !== 'all') n += 1;
    if (activeCategory.value !== 'all') n += 1;
    return n;
});

// Reset all filters (used by the empty-state "Clear filters" action).
function resetFilters(): void {
    activeFilter.value = 'all';
    activeStatus.value = 'all';
    activeCategory.value = 'all';
}

// ---- Sort — lives inside the Filters popover (no separate button/row) ----
const activeSort = ref<QueueSort>(props.defaultSort);
const sortOptions: QueueSort[] = ['priority', 'wait', 'name'];

// ---- Priority presentation (dot + avatar ring instead of emoji) ----
const priorityDotClass: Record<QueuePriority, string> = {
    critical: 'bg-critical',
    urgent: 'bg-warning',
    normal: 'bg-muted-foreground/50',
};

const avatarRingClass: Record<QueuePriority, string> = {
    critical: 'bg-critical/10 text-critical ring-2 ring-critical/50',
    urgent: 'bg-warning/10 text-warning ring-2 ring-warning/40',
    normal: 'bg-muted text-muted-foreground ring-1 ring-border',
};

// Row boundary (component-library audit, direct user feedback): every row
// used to carry a plain `border-b` divider AND critical rows additionally
// got a `border-l` accent — two different border treatments doing two
// different jobs on the same row read as a spreadsheet grid, not a
// worklist. One mechanism now carries both meanings: a left border colored
// by the row's own priority (transparent, not just absent, for normal — so
// a mixed-priority list doesn't visibly shift width row to row) replaces
// the bottom divider entirely.
const priorityBorderClass: Record<QueuePriority, string> = {
    critical: 'border-l-2 border-critical',
    urgent: 'border-l-2 border-warning',
    normal: 'border-l-2 border-transparent',
};

const priorityLabelKey: Record<QueuePriority, string> = {
    critical: 'status.critical',
    urgent: 'status.urgent',
    normal: 'status.normal',
};

const priorityRank: Record<QueuePriority, number> = { critical: 0, urgent: 1, normal: 2 };

// Status dot colors for the redesigned Status select (filter-popover
// redesign, 2026-08-11) — same status-to-color mapping StatusBadge.vue
// itself uses, so a "Pending" row in the dropdown reads with the same
// color a Pending badge does anywhere else in the app.
const statusDotClass: Record<StatusType, string> = {
    critical: 'bg-critical',
    warning: 'bg-warning',
    success: 'bg-success',
    info: 'bg-info',
    pending: 'bg-warning',
    in_progress: 'bg-info',
    complete: 'bg-success',
    cancelled: 'bg-muted-foreground',
};

// Avatar initials — first letter of first + last name (or first 2 if single word).
function initials(name: string): string {
    const parts = name.trim().split(/\s+/).filter(Boolean);
    if (parts.length === 0) return '?';
    if (parts.length === 1) return parts[0]!.slice(0, 2).toUpperCase();
    return (parts[0]![0] + parts[parts.length - 1]![0]).toUpperCase();
}

// Live urgency signal next to the header title.
const criticalWaitingCount = computed(
    () =>
        itemsReactive.value.filter(
            (i) =>
                i.priority === 'critical' &&
                (i.status ?? 'pending') !== 'complete' &&
                (i.status ?? 'pending') !== 'cancelled',
        ).length,
);

// ---- Wait-time threshold coloring (§9.4) ----
function waitStatus(minutes: number, explicit?: StatusType): StatusType {
    if (explicit) return explicit;
    if (minutes >= 60) return 'critical';
    if (minutes >= 30) return 'warning';
    return 'info';
}

// ---- Auto-updating wait time (§9.3) ----
function formatWait(minutes: number): string {
    if (minutes < 60) return `${minutes} min`;
    const h = Math.floor(minutes / 60);
    const m = minutes % 60;
    return m ? `${h}h ${m}m` : `${h}h`;
}

let timer: ReturnType<typeof setInterval> | undefined;
onMounted(() => {
    timer = setInterval(() => {
        itemsReactive.value = itemsReactive.value.map((i) => {
            const minutes = i.waitMinutes + 1;
            return { ...i, waitMinutes: minutes, waitTime: formatWait(minutes) };
        });
    }, 60000); // tick every 60s
});
onUnmounted(() => {
    if (timer) clearInterval(timer);
});

// ---- Sorted + filtered items ----
const sortedItems = computed(() => {
    let filtered = itemsReactive.value;
    if (activeFilter.value !== 'all') {
        filtered = filtered.filter((i) => i.priority === activeFilter.value);
    }
    if (activeStatus.value !== 'all') {
        filtered = filtered.filter((i) => (i.status ?? 'pending') === activeStatus.value);
    }
    if (activeCategory.value !== 'all') {
        filtered = filtered.filter((i) => i.category === activeCategory.value);
    }

    // Manual order (internal, after drag/Ctrl+Up/Down): preserve the user's
    // relative order, but enforce §9.4 — critical items always stay on top.
    if (activeSort.value === 'manual') {
        const critical = filtered.filter((i) => i.priority === 'critical');
        const rest = filtered.filter((i) => i.priority !== 'critical');
        return [...critical, ...rest];
    }

    // Incoming order (see `defaultSort` doc comment above `QueueSort`): no
    // client-side reordering at all, not even critical-pinned-top — the
    // whole point is that `priority` (this component's generic wait-based
    // urgency) and whatever rule the consumer's backend already applied
    // (e.g. arrival-mode tiering) are different axes, and re-pinning here
    // would silently override the one the consumer actually asked for.
    if (activeSort.value === 'incoming') {
        return filtered;
    }

    return [...filtered].sort((a, b) => {
        switch (activeSort.value) {
            case 'wait':
                return a.waitMinutes - b.waitMinutes;
            case 'name':
                return a.name.localeCompare(b.name);
            case 'priority':
            default:
                // Clinical rule (§9.4): critical always on top
                return priorityRank[a.priority] - priorityRank[b.priority] || a.waitMinutes - b.waitMinutes;
        }
    });
});

// ---- Empty state (§14) — "nothing here at all" and "filtered down to
// nothing" are different situations and read differently: the first is
// normal/expected (nobody's waiting), the second means the data exists but
// the user's own filter selection is hiding it. Conflating them under one
// "try adjusting your filters" message is misleading when there was
// nothing to filter in the first place. Keyed off the raw (pre-filter)
// item count, not `sortedItems`, so this can't itself depend on the
// filters it's describing.
const emptyStateContent = computed(() => {
    if (itemsReactive.value.length === 0) {
        return {
            illustration: 'users' as const,
            title: t('queue.empty_no_patients_title'),
            description: t('queue.empty_no_patients_hint'),
            actionLabel: undefined,
        };
    }
    return {
        illustration: 'search' as const,
        title: t('queue.empty_filtered_title'),
        description: undefined,
        actionLabel: t('queue.clear_filters'),
    };
});

// ---- Virtualization (§9.3 — required for > 50 items) ----
// Based on the raw filtered/sorted count, not `displayOrderItems` below —
// grouping only ever reorders items into buckets, it never changes how
// many there are, so this can't circularly depend on whether grouping is
// active.
const ROW_HEIGHT = 56;
const SKELETON_ROWS = 6;
const scrollRef = ref<HTMLElement | null>(null);
const shouldVirtualize = computed(() => sortedItems.value.length > 50);

// ---- Grouping by category (opt-in — see `groupByCategory` prop doc) ----
interface CategoryGroup {
    key: string;
    label: string;
    items: QueueItem[];
}

// Buckets `sortedItems` by category, preserving each category's first
// appearance order and each item's relative order within its own bucket.
// Correct regardless of `activeSort` — even when the active sort doesn't
// already put same-category items next to each other (e.g. Name), this
// still produces clean, non-interleaved groups; it just means the groups
// themselves may not appear in a meaningful order in that case.
const categoryGroups = computed<CategoryGroup[]>(() => {
    const buckets = new Map<string, QueueItem[]>();
    const order: string[] = [];
    for (const item of sortedItems.value) {
        const key = item.category || t('queue.group_other');
        if (!buckets.has(key)) {
            buckets.set(key, []);
            order.push(key);
        }
        buckets.get(key)!.push(item);
    }
    return order.map((key) => ({ key, label: key, items: buckets.get(key)! }));
});

// The order items are actually rendered/navigated in. Identical to
// `sortedItems` unless grouping is both requested AND not virtualizing —
// every index-based behavior below (keyboard nav, drag-drop, Ctrl+Up/Down)
// reads this instead of `sortedItems` directly so it stays correct in
// both modes without needing two parallel implementations.
const displayOrderItems = computed<QueueItem[]>(() => {
    if (!props.groupByCategory || shouldVirtualize.value) return sortedItems.value;
    return categoryGroups.value.flatMap((g) => g.items);
});

const virtualizer = useVirtualizer({
    count: displayOrderItems.value.length,
    getScrollElement: () => scrollRef.value,
    estimateSize: () => ROW_HEIGHT,
    overscan: 5,
});

// Unified display rows: virtualized (when > 50) or plain
const displayRows = computed<DisplayRow[]>(() => {
    if (!shouldVirtualize.value) {
        return displayOrderItems.value.map((item, index) => ({ item, index }));
    }
    return virtualizer.value.getVirtualItems().map((virtual) => ({
        item: displayOrderItems.value[virtual.index]!,
        index: virtual.index,
        virtual,
    }));
});

// Template-facing rows, interleaving group headers when grouping is
// active. Header rows are `role="presentation"` in the template — visible,
// readable text, but deliberately not part of the listbox's option/keyboard
// sequence (a section label isn't a selectable queue item).
interface RenderRow {
    type: 'header' | 'item';
    key: string;
    label?: string;
    count?: number;
    row?: DisplayRow;
}

const renderRows = computed<RenderRow[]>(() => {
    if (!props.groupByCategory || shouldVirtualize.value) {
        return displayRows.value.map((row) => ({ type: 'item' as const, key: row.item.id, row }));
    }
    const rows: RenderRow[] = [];
    let index = 0;
    for (const group of categoryGroups.value) {
        rows.push({ type: 'header', key: `group-${group.key}`, label: group.label, count: group.items.length });
        for (const item of group.items) {
            rows.push({ type: 'item', key: item.id, row: { item, index } });
            index++;
        }
    }
    return rows;
});

function rowStyle(row: DisplayRow): Record<string, string> {
    if (!row.virtual) return {};
    return {
        position: 'absolute',
        top: '0',
        left: '0',
        right: '0',
        height: `${row.virtual.size}px`,
        transform: `translateY(${row.virtual.start}px)`,
    };
}

// ---- Drag-to-reorder (§9.3, persisted via emit) ----
const draggingId = ref<string | null>(null);
const suppressClick = ref(false);

function onDragStart(event: DragEvent, item: QueueItem): void {
    draggingId.value = item.id;
    suppressClick.value = true;
    if (event.dataTransfer) {
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/plain', item.id);
    }
}
function onDragOver(event: DragEvent): void {
    event.preventDefault(); // allow drop
    if (event.dataTransfer) {
        event.dataTransfer.dropEffect = 'move';
    }
}
function onDrop(event: DragEvent, target: QueueItem): void {
    event.preventDefault();
    const draggedId = draggingId.value ?? event.dataTransfer?.getData('text/plain');
    draggingId.value = null;
    if (!draggedId || draggedId === target.id) return;

    // Reorder against the *visible* (rendered) order so it matches what the
    // user sees — the grouped order when grouping is active, same as
    // `sortedItems` otherwise.
    const from = displayOrderItems.value.findIndex((i) => i.id === draggedId);
    const to = displayOrderItems.value.findIndex((i) => i.id === target.id);
    if (from === -1 || to === -1 || from === to) return;

    const visible = [...displayOrderItems.value];
    const [moved] = visible.splice(from, 1);
    visible.splice(to, 0, moved);

    // Reconcile back into the full source list, preserving non-visible items
    const movedIds = new Set(visible.map((i) => i.id));
    const reordered = [
        ...visible,
        ...itemsReactive.value.filter((i) => !movedIds.has(i.id)),
    ];
    itemsReactive.value = reordered;
    // Switch to manual order so the sort doesn't immediately undo the drag
    activeSort.value = 'manual';
    emit('reorder', reordered); // audit trail — parent logs (Volume 1.5)
}
function onDragEnd(): void {
    draggingId.value = null;
    // Suppress the click that fires right after a drag
    setTimeout(() => {
        suppressClick.value = false;
    }, 0);
}
function onClick(item: QueueItem): void {
    if (suppressClick.value) return;
    emit('open', item);
}

// ---- Keyboard navigation + Ctrl+Up/Down reorder (§9.3) ----
const activeIndex = ref(0);
const rowRefs = ref<(HTMLElement | null)[]>([]);

// Reset active index when filter/sort changes
watch([activeFilter, activeStatus, activeCategory, activeSort], () => {
    activeIndex.value = 0;
});

watch(displayOrderItems, () => {
    if (activeIndex.value >= displayOrderItems.value.length) {
        activeIndex.value = Math.max(0, displayOrderItems.value.length - 1);
    }
});

function moveItem(index: number, delta: number): void {
    const to = index + delta;
    if (to < 0 || to >= displayOrderItems.value.length) return;
    const arr = [...displayOrderItems.value];
    const [moved] = arr.splice(index, 1);
    arr.splice(to, 0, moved);
    // Reflect the reorder in the source list
    const ids = new Set(arr.map((i) => i.id));
    const reordered = [
        ...arr,
        ...itemsReactive.value.filter((i) => !ids.has(i.id)),
    ];
    itemsReactive.value = reordered;
    activeIndex.value = to;
    // Switch to manual order so the sort doesn't immediately undo the move
    activeSort.value = 'manual';
    emit('reorder', reordered); // audit trail
}

function focusRow(index: number): void {
    if (shouldVirtualize.value) {
        virtualizer.value.scrollToIndex(index);
    }
    nextTick(() => {
        rowRefs.value[index]?.focus();
    });
}

function onKeydown(event: KeyboardEvent): void {
    if (event.ctrlKey && event.key === 'ArrowUp') {
        event.preventDefault();
        moveItem(activeIndex.value, -1);
        return;
    }
    if (event.ctrlKey && event.key === 'ArrowDown') {
        event.preventDefault();
        moveItem(activeIndex.value, 1);
        return;
    }
    if (event.key === 'ArrowDown') {
        event.preventDefault();
        activeIndex.value = Math.min(activeIndex.value + 1, displayOrderItems.value.length - 1);
        focusRow(activeIndex.value);
    } else if (event.key === 'ArrowUp') {
        event.preventDefault();
        activeIndex.value = Math.max(activeIndex.value - 1, 0);
        focusRow(activeIndex.value);
    } else if (event.key === 'Enter' && displayOrderItems.value[activeIndex.value]) {
        emit('open', displayOrderItems.value[activeIndex.value]);
    }
}
</script>

<template>
    <!-- Plain container — the parent pane (aside) provides the border/background -->
    <div class="flex h-full flex-col overflow-hidden">
        <!-- Header -->
        <div class="border-b border-border px-4 py-3">
            <!-- Redesigned 2026-08-11 (direct user feedback: "why chips,
                 title, more filters — is this modern 2027?"). Collapsed
                 from 3 rows (bold "Waiting Queue" title, a "N patients"
                 count line, then the chips) to one toolbar row. Both
                 removed lines were genuinely redundant with the tab
                 above (already says "Queue"/"Foleni") — and for nursing,
                 which reuses this exact component for its Tasks tab,
                 they weren't just redundant, they were wrong: a
                 "Waiting Queue" / "N patients" heading rendering inside
                 a Tasks panel. The live critical-pulse moved onto the
                 Critical chip's own dot instead of sitting next to a
                 title that no longer exists — the alert now lives on
                 the exact control it's about, not a separate decoration
                 next to a section label. The count itself isn't lost:
                 the "All" chip already shows the same total. -->
            <!-- `items-start`, not `items-center`: the chips wrap to a
                 2nd line on narrower panes (`flex-wrap` below), and
                 centering the icon button against the *combined* height
                 of both wrapped lines left it floating oddly between
                 them instead of sitting level with the first line —
                 caught live, not obvious from the code alone. -->
            <div :class="['flex items-start gap-2', hidePriorityChips ? 'justify-end' : 'justify-between']">
                <div v-if="!hidePriorityChips" class="flex flex-1 flex-wrap gap-1.5">
                    <Button
                        v-for="f in filters"
                        :key="f"
                        variant="outline"
                        size="sm"
                        :aria-pressed="activeFilter === f"
                        class="h-8 gap-1.5 rounded-full px-3 text-sm font-medium"
                        :class="
                            activeFilter === f
                                ? 'border-primary bg-primary text-primary-foreground hover:bg-primary/90'
                                : 'text-muted-foreground hover:text-foreground'
                        "
                        @click="activeFilter = f"
                    >
                        <span
                            v-if="f !== 'all'"
                            class="relative flex h-2 w-2 shrink-0"
                            :role="f === 'critical' && criticalWaitingCount > 0 ? 'img' : undefined"
                            :aria-label="f === 'critical' && criticalWaitingCount > 0 ? t('queue.critical_waiting', { count: criticalWaitingCount }) : undefined"
                        >
                            <span
                                v-if="f === 'critical' && criticalWaitingCount > 0"
                                class="absolute inline-flex h-full w-full animate-ping rounded-full bg-critical opacity-75 motion-reduce:animate-none"
                                aria-hidden="true"
                            />
                            <span
                                class="relative inline-flex h-2 w-2 rounded-full"
                                :class="[
                                    priorityDotClass[f],
                                    activeFilter === f ? 'ring-1 ring-inset ring-primary-foreground/50' : '',
                                ]"
                                aria-hidden="true"
                            />
                        </span>
                        {{ f === 'all' ? t('common.all') : t(priorityLabelKey[f]) }}
                        <span
                            class="tabular-nums"
                            :class="activeFilter === f ? 'text-primary-foreground/80' : 'text-muted-foreground/70'"
                        >
                            {{ priorityCounts[f] }}
                        </span>
                    </Button>
                </div>

                <!-- Sort/Status/Category — icon-only now that this row
                     also carries the priority chips; a text label
                     ("More Filters") would crowd the row into wrapping
                     on the narrowest panes. `aria-label` carries the
                     accessible name that used to be the button's own
                     visible text.
                     No Tooltip here (tried both nesting orders, workspace
                     tooltip audit, 2026-08-11): `Tooltip > TooltipTrigger
                     (as-child) > PopoverTrigger(as-child)` opens correctly
                     but PopoverContent renders `position: static` at
                     `y: -510` — off-screen — live-measured, not a visual
                     guess; the reverse order (`PopoverTrigger` outer)
                     opens nothing at all, `data-state` never reaches
                     "open". Both real, reproducible breaks in how these
                     two primitives' `as-child` ref-forwarding compose in
                     this codebase's current reka-ui version, not a
                     styling choice — shipping either would trade a
                     missing tooltip for a broken filters button.
                     `aria-label` + the icon + the active-count badge
                     already showing on this exact button cover it. -->
                <Popover>
                    <PopoverTrigger as-child>
                        <Button
                            variant="outline"
                            size="icon"
                            class="relative h-8 w-8 shrink-0 rounded-full"
                            :aria-label="t('queue.more_filters')"
                        >
                            <SlidersHorizontal class="h-3.5 w-3.5" aria-hidden="true" />
                            <span
                                v-if="activeFilterCount > 0"
                                class="absolute -top-1 -right-1 flex h-4 min-w-4 items-center justify-center rounded-full bg-primary px-1 text-xs font-medium text-primary-foreground"
                                aria-hidden="true"
                            >
                                {{ activeFilterCount }}
                            </span>
                        </Button>
                    </PopoverTrigger>

                    <PopoverContent class="w-72 p-0" align="end">
                        <!-- Header row: title + Clear, not a full-width button
                             stacked below everything (redesign, 2026-08-11). -->
                        <div class="flex items-center justify-between border-b border-border px-3 py-2.5">
                            <span class="text-sm font-semibold text-foreground">{{ t('queue.more_filters') }}</span>
                            <Button
                                v-if="activeFilterCount > 0"
                                variant="ghost"
                                size="sm"
                                class="h-6 px-2 text-xs text-muted-foreground hover:text-foreground"
                                @click="activeStatus = 'all'; activeCategory = 'all'"
                            >
                                {{ t('queue.clear_filters') }}
                            </Button>
                        </div>

                        <div class="space-y-3.5 p-3">
                            <!-- Sort — 3-way segmented control, same visual
                                 language as the priority chips below.
                                 Active segment's `shadow-xs` removed
                                 (workspace consistency audit, 2026-08-11):
                                 a raised segment flush inside its own
                                 track isn't a floating layer (Volume 0.2
                                 §7.4) — the same rule already applied to
                                 TabsTrigger's active state and, same
                                 pass, to ScheduleView.vue's Day/Week
                                 toggle once it was unified with this
                                 exact control's construction. -->
                            <div>
                                <Label class="mb-1.5 flex items-center gap-1.5 text-xs font-medium text-muted-foreground">
                                    <ArrowUpDown class="h-3 w-3" aria-hidden="true" />
                                    {{ t('queue.sort') }}
                                </Label>
                                <div class="inline-flex w-full rounded-md border border-border bg-muted p-0.5" role="radiogroup" :aria-label="t('queue.sort')">
                                    <button
                                        v-for="option in sortOptions"
                                        :key="option"
                                        type="button"
                                        role="radio"
                                        :aria-checked="activeSort === option"
                                        class="min-w-0 flex-1 truncate rounded-sm px-2 py-1 text-xs font-medium transition-colors"
                                        :class="
                                            activeSort === option
                                                ? 'bg-surface text-foreground'
                                                : 'text-muted-foreground hover:text-foreground'
                                        "
                                        @click="activeSort = option"
                                    >
                                        {{ t(`queue.sort_${option}`) }}
                                    </button>
                                </div>
                            </div>

                            <!-- Status — Select (current value in the trigger,
                                 checkmark on the selected row, colored dot
                                 reusing StatusBadge's own palette). -->
                            <div>
                                <Label class="mb-1.5 block text-xs font-medium text-muted-foreground">
                                    {{ t('queue.status_filter') }}
                                </Label>
                                <Select v-model="activeStatus">
                                    <SelectTrigger size="sm" class="w-full">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem v-for="s in statusFilters" :key="s" :value="s">
                                            <span
                                                v-if="s !== 'all'"
                                                class="h-2 w-2 shrink-0 rounded-full"
                                                :class="statusDotClass[s]"
                                                aria-hidden="true"
                                            />
                                            {{ s === 'all' ? t('common.all') : t(`status.${s}`) }}
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>

                            <!-- Category — Select, only when there's more than
                                 one to choose between (unchanged condition). -->
                            <div v-if="categories.length > 1">
                                <Label class="mb-1.5 block text-xs font-medium text-muted-foreground">
                                    {{ t('queue.category_filter') }}
                                </Label>
                                <Select v-model="activeCategory">
                                    <SelectTrigger size="sm" class="w-full">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem v-for="cat in categories" :key="cat" :value="cat">
                                            {{ cat === 'all' ? t('common.all') : cat }}
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>
                    </PopoverContent>
                </Popover>
            </div>

        </div>

        <!-- Queue — scroll container -->
        <div
            ref="scrollRef"
            class="relative flex-1 overflow-auto"
            @keydown="onKeydown"
        >
            <!-- Loaded state -->
            <ul
                v-if="!loading && sortedItems.length > 0"
                class="relative"
                role="listbox"
                :aria-label="t('queue.label')"
                :style="shouldVirtualize ? { height: `${sortedItems.length * ROW_HEIGHT}px` } : undefined"
            >
                <template v-for="renderRow in renderRows" :key="renderRow.key">
                    <!-- Group header (Volume 3.7 audit, 2026-08-10) — visible,
                         readable text, but `role="presentation"` deliberately
                         takes it out of the listbox's option/keyboard sequence:
                         a section label isn't a selectable queue item. -->
                    <li
                        v-if="renderRow.type === 'header'"
                        role="presentation"
                        class="sticky top-0 z-10 flex items-baseline gap-1.5 bg-surface px-4 pb-1.5 pt-3 text-xs font-semibold uppercase tracking-wide text-muted-foreground first:pt-2"
                    >
                        {{ renderRow.label }}
                        <span class="font-normal normal-case text-muted-foreground/70">({{ renderRow.count }})</span>
                    </li>
                    <li
                        v-else-if="renderRow.row"
                        :ref="(el) => { rowRefs[renderRow.row!.index] = el as HTMLElement | null }"
                        draggable="true"
                        role="option"
                        tabindex="0"
                        :aria-selected="renderRow.row.index === activeIndex"
                        :style="rowStyle(renderRow.row)"
                        :class="[
                            'group flex cursor-pointer items-center gap-3 px-4 py-3 transition-colors',
                            priorityBorderClass[renderRow.row.item.priority],
                            renderRow.row.index === activeIndex
                                ? 'bg-muted'
                                : 'hover:bg-muted/50',
                            draggingId === renderRow.row.item.id ? 'opacity-50' : '',
                            renderRow.row.virtual ? 'motion-safe:transition-transform motion-safe:duration-200 motion-safe:ease-out' : '',
                        ]"
                        @click="onClick(renderRow.row.item)"
                        @focus="activeIndex = renderRow.row.index"
                        @mouseenter="activeIndex = renderRow.row.index"
                        @dragstart="onDragStart($event, renderRow.row.item)"
                        @dragover="onDragOver"
                        @drop="onDrop($event, renderRow.row.item)"
                        @dragend="onDragEnd"
                    >
                    <!-- Drag handle -->
                    <span
                        class="flex shrink-0 cursor-grab items-center text-muted-foreground/0 transition-colors group-hover:text-muted-foreground/70 group-focus:text-muted-foreground/70 active:cursor-grabbing"
                        aria-hidden="true"
                    >
                        <GripVertical class="h-3.5 w-3.5" />
                    </span>

                    <!-- Avatar (initials, priority ring + live dot) -->
                    <div class="relative shrink-0">
                        <div
                            class="flex h-8 w-8 items-center justify-center rounded-full text-xs font-semibold"
                            :class="avatarRingClass[renderRow.row.item.priority]"
                        >
                            {{ initials(renderRow.row.item.name) }}
                        </div>
                        <span
                            class="absolute -bottom-0.5 -right-0.5 block h-2.5 w-2.5 rounded-full ring-2 ring-surface"
                            :class="[
                                priorityDotClass[renderRow.row.item.priority],
                                renderRow.row.item.priority === 'critical' ? 'motion-safe:animate-pulse' : '',
                            ]"
                            aria-hidden="true"
                        />
                    </div>

                    <!-- Name + secondary line (category, wait time) — kept on their
                         own lines rather than sharing the row with badges, so the
                         name always gets full width instead of being squeezed
                         down to a couple of characters on narrow panes. -->
                    <div class="min-w-0 flex-1">
                        <div class="truncate text-sm font-medium text-foreground">
                            {{ renderRow.row.item.name }}
                            <span class="sr-only">{{ t(priorityLabelKey[renderRow.row.item.priority]) }}</span>
                        </div>
                        <div class="mt-0.5 flex items-center gap-2 text-xs text-muted-foreground">
                            <!-- `min-w-0` is required, not decorative: a flex item's
                                 default `min-width: auto` means `truncate`'s
                                 `overflow-hidden` never actually engages (the item
                                 won't shrink below its own text width) — found
                                 live-testing 2026-08-10 (Volume 3.7 T5.1) once a
                                 longer category value (arrival-mode tier label)
                                 made a short department string's silent version of
                                 this bug visible for the first time. Only shown
                                 when NOT grouping — grouping already says this via
                                 the section header, so repeating it per-row would
                                 be redundant now that there's a header saying it. -->
                            <span
                                v-if="renderRow.row.item.category && !groupByCategory"
                                class="min-w-0 truncate"
                            >
                                {{ renderRow.row.item.category }}
                            </span>
                            <!-- Wait time (§9.4 — threshold coloring) -->
                            <span
                                class="flex shrink-0 items-center gap-1 tabular-nums"
                                :class="{
                                    'text-critical': waitStatus(renderRow.row.item.waitMinutes, renderRow.row.item.status) === 'critical',
                                    'text-warning': waitStatus(renderRow.row.item.waitMinutes, renderRow.row.item.status) === 'warning',
                                }"
                            >
                                <Clock class="h-3 w-3" aria-hidden="true" />
                                {{ renderRow.row.item.waitTime }}
                            </span>
                        </div>
                    </div>

                    <!-- StatusBadge (§9.3 — workflow status per item, Volume 2.1 §10.2) -->
                    <StatusBadge :status="renderRow.row.item.status ?? 'pending'" class="shrink-0" />

                    <!-- Row actions — optional, per-consumer (Volume 1.2 §2.4: composable
                         slots, not a hardcoded action set baked into the shared component).
                         Revealed on hover/focus like the drag handle and chevron. Stops
                         propagation so clicking an action doesn't also fire row `open`. -->
                    <div
                        v-if="$slots['row-actions']"
                        class="flex shrink-0 items-center gap-1 opacity-0 transition-opacity group-hover:opacity-100 group-focus-within:opacity-100"
                        @click.stop
                    >
                        <slot name="row-actions" :item="renderRow.row.item" />
                    </div>

                    <!-- Open affordance -->
                    <ChevronRight
                        class="h-3.5 w-3.5 shrink-0 text-muted-foreground/0 transition-colors group-hover:text-muted-foreground/60"
                        aria-hidden="true"
                    />
                    </li>
                </template>
            </ul>

            <!-- Loading state — skeleton rows instead of a blank flash -->
            <div v-else-if="loading" class="divide-y divide-border" aria-hidden="true">
                <div
                    v-for="n in SKELETON_ROWS"
                    :key="n"
                    class="flex items-center gap-3 px-4 py-3"
                >
                    <div class="h-8 w-8 shrink-0 animate-pulse rounded-full bg-muted" />
                    <div class="flex-1 space-y-1.5">
                        <div class="h-3 w-2/5 animate-pulse rounded bg-muted" />
                        <div class="h-2.5 w-1/5 animate-pulse rounded bg-muted" />
                    </div>
                    <div class="h-3 w-10 animate-pulse rounded bg-muted" />
                    <div class="h-5 w-14 animate-pulse rounded-full bg-muted" />
                </div>
            </div>

            <!-- Empty state (Volume 1.2 §14) — shared EmptyState.vue
                 (component-library audit, 2026-08-11); was a hand-rolled
                 version of the same anatomy DataTable and ScheduleView
                 each rebuilt slightly differently. Content varies by
                 genuinely-empty vs filtered-to-empty — see
                 `emptyStateContent` above. -->
            <EmptyState
                v-else
                :illustration="emptyStateContent.illustration"
                :title="emptyStateContent.title"
                :description="emptyStateContent.description"
                :action-label="emptyStateContent.actionLabel"
                @action="resetFilters"
            />
        </div>

        <!-- Queue Footer (§9.2 — actions) -->
        <div
            v-if="$slots.footer"
            class="border-t border-border px-4 py-2"
        >
            <slot name="footer" />
        </div>
    </div>
</template>