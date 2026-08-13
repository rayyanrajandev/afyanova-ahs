/**
 * Queue live-arrival announcer (Volume 2.1 §10.4, Volume 3.7 T5.7)
 * =====================================================================
 * `aria-live="polite"` counterpart to the real-time data sync
 * (useReceptionLiveSync.ts, T5.6b) — that composable makes new arrivals
 * appear in the queue without a manual refresh; this makes a screen-reader
 * user actually learn that happened. A DOM list gaining a row with no
 * focus change and no visible action taken by *this* user is otherwise
 * silent to assistive tech — exactly the gap §10.4 names.
 *
 * Deliberately watches `queueStore.tasks` directly rather than hooking
 * into any one caller (useReceptionLiveSync's onBoardUpdated,
 * useArrivalIntake's own local check-in, useQueueActions' reorder
 * refetch) — there are three different call sites that can refresh the
 * queue, and diffing the store's own state once, centrally, is both
 * simpler and can't miss a path a future caller adds. Announcing on the
 * user's own local check-in too (on top of that action's own toast) is a
 * deliberate, accepted trade-off: mild redundancy for one user's own
 * action is a much smaller cost than an announcement path with a subtle
 * "was this refetch triggered by me or someone else" bug.
 */

import { ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { useQueueStore } from "@/stores/queueStore";

export function useQueueLiveAnnouncer() {
  const { t } = useI18n();
  const queueStore = useQueueStore();
  const announcement = ref("");

  let previousIds = new Set<string>();
  let hasSeenFirstLoad = false;

  watch(
    () => queueStore.tasks,
    (tasks) => {
      const currentIds = new Set(tasks.map((task) => task.id));

      // First population of the store (the initial mount fetch) isn't a
      // "new arrival" — every row is new relative to the empty starting
      // state, and announcing all of them on page load would be noise,
      // not information. Same guard shape as scheduleLoadedOnce
      // elsewhere in this workspace: skip the diff once, then diff for
      // real from here on.
      if (!hasSeenFirstLoad) {
        hasSeenFirstLoad = true;
        previousIds = currentIds;
        return;
      }

      const newArrivals = tasks.filter((task) => !previousIds.has(task.id));
      previousIds = currentIds;
      if (newArrivals.length === 0) return;

      announcement.value =
        newArrivals.length === 1
          ? t("queue.live_announce_single", { name: newArrivals[0].patientName })
          : t("queue.live_announce_multiple", { count: newArrivals.length });
    },
  );

  return { announcement };
}
