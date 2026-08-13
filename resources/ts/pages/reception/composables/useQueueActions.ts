/**
 * Reception queue + queue row actions (Volume 2.1 §10)
 * =========================================================
 * Extracted from reception/Index.vue (2026-08-10, component-library audit)
 * — pure extraction, no behavior change. Arrival-mode tier label added
 * 2026-08-10 (Volume 3.7 T5.1).
 *
 * Check-in/No-show are not offered here — see queueStore.ts's
 * cancelQueueItem doc comment for why they're not valid transitions on
 * this queue view. Cancel is the only status-changing action valid on an
 * already-queued item (§10.3).
 */

import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import type { QueueItem } from "@/components/common/Queue.vue";
import { useToast } from "@/composables/useToast";
import { usePatientStore } from "@/stores/patientStore";
import { useQueueStore, type QueueTask } from "@/stores/queueStore";
import { useRecentStore } from "@/stores/recentStore";

export interface UseQueueActionsOptions {
  /**
   * Called after a successful cancel with the cancelled item's patientId,
   * so the caller can refresh whatever depends on it — Latest visit/Audit
   * trail on the patient profile, if it's the one currently open (bug fix,
   * 2026-08-11: cancel previously refreshed nothing at all; see
   * CancelQueueItemUseCase's backend-side fix for the encounter/audit-log
   * gap this closes the frontend half of).
   */
  onCancelled?: (patientId: string) => void;
}

export function useQueueActions(options: UseQueueActionsOptions = {}) {
  const { t } = useI18n();
  const toast = useToast();
  const patientStore = usePatientStore();
  const queueStore = useQueueStore();
  const recentStore = useRecentStore();

  // Arrival-mode tier label (Volume 2.1 §10.1/§10.2, Volume 3.7 T5.1) —
  // backend already orders the queue emergency > scheduled > walk-in,
  // oldest-wait-first within each tier (`GetReceptionQueueUseCase`); this
  // just makes that grouping visible instead of a flat list. `null`
  // (arrival mode not recorded — a pre-arrival-intake visit) intentionally
  // renders no tier chip rather than guessing one.
  function tierLabel(arrivalMode: QueueTask["arrivalMode"]): string | undefined {
    switch (arrivalMode) {
      case "emergency":
        return t("queue.tier_emergency");
      case "scheduled_checkin":
        return t("queue.tier_scheduled");
      case "walk_in":
        return t("queue.tier_walk_in");
      default:
        return undefined;
    }
  }

  /**
   * Row-level urgency (2026-08-12, Reception queue-chips audit follow-up:
   * direct user feedback — "emergency and walk in both have red borders in
   * row, this is not right"). Queue.vue's row border/avatar-ring/pulse-dot
   * all key off `item.priority`, which `hidePriorityChips` above only hid
   * the *filter chips* for, not this — the underlying value was still
   * `task.priority` (queueStore.ts's wait-time bucket:
   * >=60min critical/>=30min urgent), so a long-waiting Walk-in patient got
   * the same red "critical" border as an Emergency arrival. This maps
   * `priority` to arrival mode instead — only a true Emergency arrival is
   * 'critical'; Scheduled/Walk-in are 'normal' rather than a fabricated
   * 'urgent', since the tier ordering (Emergency > Scheduled > Walk-in) is
   * about queue fairness, not clinical acuity, and is already communicated
   * by the section-header grouping above. The Clock/wait-time text's own
   * amber->red coloring (Queue.vue's `waitStatus()`) is untouched — how
   * long someone has waited is real, useful information independent of
   * this.
   */
  function arrivalModePriority(arrivalMode: QueueTask["arrivalMode"]): QueueItem["priority"] {
    return arrivalMode === "emergency" ? "critical" : "normal";
  }

  const queue = computed<QueueItem[]>(() =>
    queueStore.tasks.map((task) => ({
      id: task.id,
      name: task.patientName,
      waitTime: task.dueTime,
      waitMinutes: task.waitMinutes,
      priority: arrivalModePriority(task.arrivalMode),
      status:
        task.status === "complete"
          ? "complete"
          : task.status === "in_progress"
            ? "in_progress"
            : "pending",
      // Tier label, not department (found live-testing 2026-08-10:
      // combining them as "Scheduled · Antenatal Clinic" truncated to an
      // unreadable sliver in the 320px context pane). Volume 2.1 §10.2's
      // display-fields table doesn't list department for this row anyway;
      // tiering is the actual ask (T5.1). Falls back to department only
      // when arrival mode is unknown, so the field isn't just blank.
      category: tierLabel(task.arrivalMode) ?? task.description,
    })),
  );

  // Load the queue when the workspace mounts
  queueStore.fetchReceptionQueue();

  /**
   * Re-fetch on a live patient-flow board update (§10.4, useReceptionLiveSync)
   * — a thin, named wrapper around the same store call the initial mount
   * load above uses, so Index.vue's live-sync wiring doesn't need to reach
   * into queueStore directly (this composable already owns that
   * dependency, same reasoning as refreshScheduleIfLoaded on the
   * appointments side).
   */
  async function refetchQueue() {
    await queueStore.fetchReceptionQueue();
  }

  /**
   * Call (§10.3, §16 #3, decided + built 2026-08-11) — POST only; no local
   * "Calling {name}" toast on success here. The actual announcement comes
   * from the AppointmentCalled broadcast (useReceptionLiveSync's
   * onPatientCalled), which fires in this same tab too within a fraction
   * of a second — showing our own optimistic toast here as well would just
   * be the same message twice from two different code paths that could
   * eventually drift, for a gain of a few hundred ms. Only the failure
   * case gets a toast here, since a failed call never reaches the
   * broadcast at all.
   */
  async function callQueueItem(item: QueueItem) {
    const res = await fetch(`/api/v1/reception/queue/${encodeURIComponent(item.id)}/call`, {
      method: "POST",
      headers: { "X-Requested-With": "XMLHttpRequest" },
    });
    if (!res.ok) {
      const body = await res.json().catch(() => null);
      toast.critical(body?.message ?? t("queue.call_failed"));
    }
  }

  function handleQueueOpen(item: QueueItem) {
    // Volume 1.2 §9.3 — Enter on a queue item opens the patient in the main pane
    const patient = patientStore.patients.get(item.id);
    if (patient) {
      patientStore.setCurrentPatient(patient.id);
      recentStore.addRecent(patient);
    }
  }

  /**
   * Drag-to-reorder (Volume 2.1 §10.3 "Reorder", Volume 3.7 T5.5).
   * `Queue.vue` already applies the reorder optimistically in its own local
   * state before this fires — the backend is the single source of truth for
   * the tier-hard-floor rule (not duplicated client-side, same reasoning as
   * every other conflict check in this workspace), so either outcome
   * refetches: on success to pick up the real persisted positions, on
   * rejection to snap the visibly-wrong optimistic order back to reality.
   */
  async function handleQueueReorder(orderedItems: QueueItem[]) {
    const ok = await queueStore.reorderQueue(orderedItems.map((item) => item.id));
    if (!ok) {
      toast.critical(queueStore.error ?? t("queue.reorder_failed"));
    }
    await queueStore.fetchReceptionQueue();
  }

  const showCancelDialog = ref(false);
  const cancelTarget = ref<QueueItem | null>(null);
  const cancelReason = ref("");
  const cancelSubmitting = ref(false);

  function openCancelDialog(item: QueueItem) {
    cancelTarget.value = item;
    cancelReason.value = "";
    showCancelDialog.value = true;
  }

  function closeCancelDialog() {
    showCancelDialog.value = false;
    cancelTarget.value = null;
    cancelReason.value = "";
  }

  async function confirmCancelQueueItem() {
    if (!cancelTarget.value || !cancelReason.value.trim()) return;
    const target = cancelTarget.value;
    // Captured before cancelQueueItem() runs: it removes the task from
    // queueStore.tasks on success, so patientId wouldn't be findable after
    // (QueueItem itself doesn't carry patientId — QueueTask, the store's
    // own shape, does).
    const patientId = queueStore.tasks.find((task) => task.id === target.id)?.patientId;
    cancelSubmitting.value = true;
    const ok = await queueStore.cancelQueueItem(target.id, cancelReason.value.trim());
    cancelSubmitting.value = false;
    if (ok) {
      toast.success(t("queue.cancel_success", { name: target.name }));
      closeCancelDialog();
      if (patientId) options.onCancelled?.(patientId);
    } else {
      toast.critical(queueStore.error ?? t("queue.cancel_failed"));
    }
  }

  return {
    queue,
    handleQueueOpen,
    handleQueueReorder,
    refetchQueue,
    callQueueItem,
    showCancelDialog,
    cancelTarget,
    cancelReason,
    cancelSubmitting,
    openCancelDialog,
    closeCancelDialog,
    confirmCancelQueueItem,
  };
}
