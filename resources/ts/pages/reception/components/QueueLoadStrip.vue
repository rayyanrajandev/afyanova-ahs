/**
 * QueueLoadStrip — reception queue load-at-a-glance (Volume 2.1 §10.2)
 * =====================================================================
 * New 2026-08-13 (reception UI audit). Restores an at-a-glance urgency
 * signal that reception lost, and fills the empty header row that loss left
 * behind.
 *
 * Why this exists — the specific regression it closes:
 * `hide-priority-chips` (2026-08-12, direct user feedback: "seems like they
 * are useless") was the right call on its own terms — this queue is
 * `stage=waiting_triage`, so Queue.vue's wait-bucketed Critical/Urgent/
 * Normal chips described nothing clinical. But the live critical pulse
 * (Queue.vue's `animate-ping` dot) lived *on the Critical chip's own dot*,
 * deliberately moved there in the 2026-08-11 header redesign when the old
 * standalone title-adjacent alert was removed. Hiding the chips therefore
 * took the pulse with it, and reception has had no board-level alert since:
 * an Emergency arrival is visible only as one row's border colour, inside a
 * scrollable list, on a tab that may not even be open. Reception's header
 * row also collapses to `justify-end` with nothing in it but the Filters
 * icon button, so the space was already there.
 *
 * What it shows, and what it deliberately doesn't:
 *   - Longest wait — the one genuinely board-level number that no other
 *     surface reports. Per-row wait times exist, but finding the worst one
 *     means reading every row.
 *   - Emergency count — arrival-mode derived (`item.priority === 'critical'`
 *     is `arrivalModePriority`'s output for reception, i.e. a true Emergency
 *     arrival, NOT a wait-time bucket), so it carries the pulse honestly.
 *     Hidden entirely at zero rather than rendering a reassuring "0".
 *   - NOT a total count. The 2026-08-11 header redesign removed the
 *     "N patients" line as redundant with the tab, and the tab badge now
 *     shows exactly that number a few pixels above this strip. Re-adding it
 *     here would walk that decision back for no new information.
 *
 * The whole strip unmounts when nothing is queued — an empty board should
 * read as empty, not as a rack of zeroes, and Queue.vue already owns the
 * real empty state below it.
 *
 * Wait minutes come from the store snapshot (`useQueueActions`'s `queue`),
 * so this advances on refetch/live-sync (§10.4) rather than ticking every
 * 60s the way Queue.vue's rows do — Queue.vue increments its own local
 * reactive copy, which is intentionally not shared state. Thresholds below
 * mirror Queue.vue's `waitStatus` (§9.4: >=60 critical, >=30 warning) and
 * its `formatWait` output format; both are file-private there, and lifting
 * them into a shared util for one consumer would mean touching the queue
 * component every workspace depends on.
 */

<script setup lang="ts">
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import type { QueueItem } from "@/components/common/Queue.vue";
import { Badge } from "@/components/ui/badge";

const props = defineProps<{
  items: QueueItem[];
}>();

const { t } = useI18n();

// Completed/cancelled rows are excluded the same way Queue.vue's own
// `criticalWaitingCount` excludes them — someone already seen isn't part of
// the load still on the floor.
const activeItems = computed(() =>
  props.items.filter((item) => {
    const status = item.status ?? "pending";
    return status !== "complete" && status !== "cancelled";
  }),
);

const longestWaitMinutes = computed(() =>
  activeItems.value.reduce((max, item) => Math.max(max, item.waitMinutes), 0),
);

const emergencyCount = computed(
  () => activeItems.value.filter((item) => item.priority === "critical").length,
);

/** Mirrors Queue.vue's `formatWait` so the strip and the rows agree. */
const longestWaitLabel = computed(() => {
  const minutes = longestWaitMinutes.value;
  if (minutes < 60) return `${minutes} min`;
  const hours = Math.floor(minutes / 60);
  const rest = minutes % 60;
  return rest ? `${hours}h ${rest}m` : `${hours}h`;
});

/**
 * Mirrors Queue.vue's `waitStatus` (§9.4). Text weight/colour only — the
 * threshold is never the sole signal, since the number itself is right
 * there (Volume 0.3 §3, never colour alone).
 */
const longestWaitClass = computed(() => {
  if (longestWaitMinutes.value >= 60) return "text-critical";
  if (longestWaitMinutes.value >= 30) return "text-warning";
  return "text-foreground";
});
</script>

<template>
  <div
    v-if="activeItems.length > 0"
    role="group"
    :aria-label="t('queue.load_label')"
    class="flex shrink-0 items-center justify-between gap-3 border-b border-border bg-surface-raised px-4 py-2"
  >
    <div class="flex min-w-0 items-baseline gap-2">
      <span class="text-xs font-medium tracking-wide text-muted-foreground uppercase">
        {{ t("queue.longest_wait") }}
      </span>
      <span class="text-sm font-semibold tabular-nums" :class="longestWaitClass">
        {{ longestWaitLabel }}
      </span>
    </div>

    <!-- Emergency alert. `Badge variant="critical"` rather than a
         hand-rolled pill: that variant already is the bg-critical/12 +
         text-critical + border-critical/22 treatment this needs (Volume 1.2
         §12). The pulse is the same `animate-ping` + `motion-reduce`
         construction Queue.vue used on the Critical chip's dot, so the
         restored alert is visually the one staff already knew. Count is
         inside the accessible name, so the dot isn't carrying meaning on
         its own. -->
    <Badge v-if="emergencyCount > 0" variant="critical" class="gap-1.5">
      <span class="relative flex h-2 w-2 shrink-0" aria-hidden="true">
        <span
          class="absolute inline-flex h-full w-full animate-ping rounded-full bg-critical opacity-75 motion-reduce:animate-none"
        />
        <span class="relative inline-flex h-2 w-2 rounded-full bg-critical" />
      </span>
      {{ t("queue.emergency_waiting_count", { count: emergencyCount }) }}
    </Badge>
  </div>
</template>
