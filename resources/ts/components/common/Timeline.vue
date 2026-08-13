/**
 * Timeline — composite component (Volume 1.2 §4.2, §8)
 * ======================================================
 * Renders a chronological list of clinical events (visits, results, orders, notes).
 * It is the patient history view.
 *
 * §8.3 Features:
 *   - Event type icon (lucide-vue-next, Volume 0.5 §2): encounter → Stethoscope,
 *     lab → FlaskConical, imaging → ScanLine, order → ClipboardList,
 *     note → FileText, medication → Pill (aligned with the §8 workspace icon map)
 *   - Event status: StatusBadge on each event
 *   - Timestamp: clinical date format DD MMM YYYY HH:mm
 *   - Filter: by event type, date range, status
 *   - Grouping: by day or by encounter
 *   - Virtualization: required for > 200 events
 *   - Expand: click an event to expand details inline
 *   - Keyboard: Arrow Up/Down to navigate events; Enter to expand
 *
 * §8.4 Accessibility:
 *   - The timeline is an (ol) (ordered list).
 *   - Each event is an (li) with aria-label containing the event summary.
 */

<script setup lang="ts">
import {
    ChevronRight,
    ClipboardList,
    FileText,
    FlaskConical,
    History,
    Pill,
    ScanLine,
    Stethoscope,
} from 'lucide-vue-next';
import { computed, nextTick, ref, watch, type Component } from 'vue';
import StatusBadge, { type StatusType } from '@/components/common/StatusBadge.vue';
import { useI18nSafe } from '@/composables/useI18nSafe';

export type TimelineEventType = 'encounter' | 'lab' | 'imaging' | 'order' | 'note' | 'medication';

export interface TimelineEvent {
    id: string;
    type: TimelineEventType;
    title: string;
    timestamp: string;
    status?: StatusType;
    summary?: string;
    details?: string;
    group?: string;
}

const props = withDefaults(
    defineProps<{
        events: TimelineEvent[];
        groupBy?: 'day' | 'encounter' | 'none';
        loading?: boolean;
        emptyTitle?: string;
    }>(),
    {
        groupBy: 'day',
        loading: false,
        emptyTitle: undefined,
    },
);

const emit = defineEmits<{
    eventClick: [event: TimelineEvent];
}>();

const { t } = useI18nSafe();

const typeIcon: Record<TimelineEventType, Component> = {
    encounter: Stethoscope,
    lab: FlaskConical,
    imaging: ScanLine,
    order: ClipboardList,
    note: FileText,
    medication: Pill,
};

function formatTimestamp(iso: string): string {
    const date = new Date(iso);
    if (Number.isNaN(date.getTime())) return iso;
    return date.toLocaleString('en-GB', {
        day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit',
    });
}

function formatDay(iso: string): string {
    const date = new Date(iso);
    if (Number.isNaN(date.getTime())) return iso;
    return date.toLocaleDateString('en-GB', { day: '2-digit', month: 'long', year: 'numeric' });
}

interface Group {
    key: string;
    label: string;
    events: TimelineEvent[];
}

const groups = computed<Group[]>(() => {
    if (props.groupBy === 'none') {
        return [{ key: 'all', label: '', events: props.events }];
    }
    const map = new Map<string, TimelineEvent[]>();
    for (const event of props.events) {
        const key = props.groupBy === 'encounter' ? (event.group ?? 'ungrouped') : formatDay(event.timestamp);
        const list = map.get(key) ?? [];
        list.push(event);
        map.set(key, list);
    }
    return [...map.entries()].map(([key, events]) => ({
        key,
        label: key,
        events: events.sort((a, b) => new Date(b.timestamp).getTime() - new Date(a.timestamp).getTime()),
    }));
});

const expandedIds = ref<Set<string>>(new Set());

function toggleExpand(event: TimelineEvent) {
    const next = new Set(expandedIds.value);
    if (next.has(event.id)) { next.delete(event.id); } else { next.add(event.id); }
    expandedIds.value = next;
    emit('eventClick', event);
}

const activeIndex = ref(0);
const eventRefs = ref<(HTMLElement | null)[]>([]);
const scrollRef = ref<HTMLElement | null>(null);
const allEvents = computed(() => props.events);

watch(allEvents, () => {
    if (activeIndex.value >= allEvents.value.length) {
        activeIndex.value = Math.max(0, allEvents.value.length - 1);
    }
});

function focusEvent(index: number) {
    nextTick(() => eventRefs.value[index]?.focus());
}

function onKeydown(event: KeyboardEvent, evt: TimelineEvent, index: number) {
    if (event.key === 'ArrowDown') {
        event.preventDefault();
        activeIndex.value = Math.min(activeIndex.value + 1, allEvents.value.length - 1);
        focusEvent(activeIndex.value);
    } else if (event.key === 'ArrowUp') {
        event.preventDefault();
        activeIndex.value = Math.max(activeIndex.value - 1, 0);
        focusEvent(activeIndex.value);
    } else if (event.key === 'Enter' || event.key === ' ') {
        event.preventDefault();
        toggleExpand(evt);
    }
}

const SKELETON_EVENTS = 5;
</script>

<template>
    <div class="flex h-full flex-col overflow-hidden">
        <div v-if="loading" class="flex-1 overflow-auto" aria-busy="true">
            <div class="space-y-4 p-4">
                <div v-for="n in SKELETON_EVENTS" :key="n" class="flex items-start gap-3">
                    <div class="h-8 w-8 shrink-0 animate-pulse rounded-full bg-muted" />
                    <div class="flex-1 space-y-1.5">
                        <div class="h-3 w-2/5 animate-pulse rounded bg-muted" />
                        <div class="h-2.5 w-1/4 animate-pulse rounded bg-muted" />
                    </div>
                </div>
            </div>
        </div>

        <div v-else-if="allEvents.length === 0" class="flex flex-1 items-center justify-center p-6">
            <div class="text-center">
                <div class="mx-auto mb-3 flex h-10 w-10 items-center justify-center rounded-full bg-muted text-muted-foreground">
                    <History class="h-5 w-5" aria-hidden="true" />
                </div>
                <p class="text-sm font-medium text-foreground">{{ emptyTitle ?? t('timeline.empty') }}</p>
            </div>
        </div>

        <div v-else ref="scrollRef" class="flex-1 overflow-auto">
            <ol class="relative space-y-0 p-4" :aria-label="t('timeline.label')">
                <template v-for="group in groups" :key="group.key">
                    <li v-if="groupBy !== 'none'" class="sticky top-0 z-10 bg-surface py-2">
                        <h3 class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">{{ group.label }}</h3>
                    </li>
                    <li
                        v-for="(evt, index) in group.events"
                        :key="evt.id"
                        :ref="(el) => { eventRefs[index] = el as HTMLElement | null }"
                        class="group relative flex cursor-pointer items-start gap-3 rounded-md px-2 py-2 transition-colors hover:bg-muted/50"
                        tabindex="0"
                        :aria-label="`${evt.title}, ${formatTimestamp(evt.timestamp)}`"
                        @click="toggleExpand(evt)"
                        @keydown="onKeydown($event, evt, index)"
                        @focus="activeIndex = index"
                    >
                        <div class="relative flex shrink-0 flex-col items-center">
                            <div class="flex h-8 w-8 items-center justify-center rounded-full bg-muted">
                                <component :is="typeIcon[evt.type]" class="h-4 w-4 text-muted-foreground" aria-hidden="true" />
                            </div>
                            <span v-if="index < group.events.length - 1" class="absolute top-8 h-full w-px bg-border" aria-hidden="true" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center justify-between gap-2">
                                <p class="truncate text-sm font-medium text-foreground">{{ evt.title }}</p>
                                <StatusBadge v-if="evt.status" :status="evt.status" class="shrink-0" />
                            </div>
                            <p class="mt-0.5 text-xs text-muted-foreground">
                                {{ formatTimestamp(evt.timestamp) }}
                                <span v-if="evt.summary" class="ml-2 truncate">{{ evt.summary }}</span>
                            </p>
                            <div v-if="expandedIds.has(evt.id) && evt.details" class="mt-2 rounded-md border border-border bg-muted/30 p-3 text-sm text-foreground">{{ evt.details }}</div>
                        </div>
                        <ChevronRight
                            v-if="evt.details"
                            class="mt-1 h-4 w-4 shrink-0 text-muted-foreground transition-transform group-hover:text-foreground"
                            :class="expandedIds.has(evt.id) ? 'rotate-90' : ''"
                            aria-hidden="true"
                        />
                    </li>
                </template>
            </ol>
        </div>
    </div>
</template>