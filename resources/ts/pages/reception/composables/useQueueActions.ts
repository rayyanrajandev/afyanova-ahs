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
 *
 * Enhanced 2026-08-14: Multi-stage patient journey tracking (Waiting Triage,
 * Waiting Doctor / OPD Consultation, In Consultation) with live stage counters.
 */

import { computed, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import type { QueueItem } from "@/components/common/Queue.vue";
import { stepBadgeStatus, stepLabelKey } from "@/composables/patientFlowStep";
import { useToast } from "@/composables/useToast";
import { usePatientStore } from "@/stores/patientStore";
import { useQueueStore, type QueueTask, type ReceptionQueueStage } from "@/stores/queueStore";
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
  /**
   * Stage to open on. Taken as an argument rather than assigned afterwards
   * because this queue fetches per stage: setting the ref post-construction
   * would leave the first request already in flight for the default.
   */
  initialStage?: ReceptionQueueStage;
}

export function useQueueActions(options: UseQueueActionsOptions = {}) {
  const { t, locale } = useI18n({ useScope: "global" });
  const toast = useToast();
  const patientStore = usePatientStore();
  const queueStore = useQueueStore();
  const recentStore = useRecentStore();

  const selectedStage = ref<ReceptionQueueStage>(options.initialStage ?? "waiting_triage");
  const stageCounts = computed(() => queueStore.stageCounts);

  function tierLabel(arrivalMode: QueueTask["arrivalMode"]): string | undefined {
    switch (arrivalMode) {
      case "returned":
        return "queue.tier_returned";
      case "emergency":
        return "queue.tier_emergency";
      case "scheduled_checkin":
        return "queue.tier_scheduled";
      case "walk_in":
        return "queue.tier_walk_in";
      default:
        return undefined;
    }
  }

  function arrivalModePriority(arrivalMode: QueueTask["arrivalMode"]): QueueItem["priority"] {
    if (arrivalMode === "returned" || arrivalMode === "emergency") {
      return "critical";
    }
    return "normal";
  }

  const queue = computed<QueueItem[]>(() => {
    void locale.value;
    return queueStore.tasks.map((task) => {
      let category = task.description || t("queue.category_general_opd");

      if (selectedStage.value === "awaiting_payment") {
        // The one thing the desk needs on this tab is how much to send them
        // with, so the amount replaces the usual arrival-tier category.
        const due = task.paymentStatus?.amountDue;
        const currency = task.paymentStatus?.currencyCode;
        category = due
          ? t("queue.amount_due", { amount: `${currency ?? ""} ${due}`.trim() })
          : t("queue.unpaid_chip");
      } else if (selectedStage.value === "waiting_triage") {
        const baseTier = tierLabel(task.arrivalMode);
        category = baseTier ? t(baseTier) : (task.description || t("queue.category_general_opd"));
      } else if (selectedStage.value === "waiting_provider") {
        category = task.description || t("queue.category_general_opd");
      } else if (selectedStage.value === "in_consultation") {
        category = task.description || t("queue.category_general_opd");
      } else if (selectedStage.value === "admitted") {
        category = task.description || t("patient.stage_admitted_inpatient");
      }

      // Driven by the row's own server-resolved step, not by which tab is open
      // (2026-08-16 flow audit). Previously every row on the "Waiting Doctor"
      // tab was labelled "Waiting Doctor" regardless of what was actually
      // happening to that patient, so a nurse who had picked someone up — or a
      // doctor who had started — was invisible here. The tab now filters; the
      // step labels.
      let status: QueueItem["status"] = "pending";
      let statusLabel: string | undefined = undefined;

      const stepStatus = stepBadgeStatus(task.stage);
      const stepKey = stepLabelKey(task.stage);

      if (stepStatus !== null && stepKey !== null) {
        status = stepStatus;
        statusLabel = t(stepKey);
      } else if (selectedStage.value === "waiting_triage") {
        // No resolved step yet (a visit checked in but not yet placed) — the
        // triage tab's own call to action still applies.
        status = "warning";
        statusLabel = t("queue.needs_triage");
      } else if (task.status === "complete") {
        status = "complete";
        statusLabel = t("patient.stage_completed");
      }

      return {
        id: task.id,
        name: task.patientName,
        waitTime: task.dueTime,
        waitMinutes: task.waitMinutes,
        priority: arrivalModePriority(task.arrivalMode),
        status,
        statusLabel,
        category,
        // Set only while the prepaid gate is shut. Queue.vue renders this as
        // the row's administrative warning.
        hasWarning: task.paymentStatus != null,
      };
    });
  });

  // ---- Loading/error state ----
  const isLoading = computed(() => queueStore.isLoading);
  const error = computed(() => queueStore.error);

  async function fetchStageCounts() {
    await queueStore.fetchStageCounts();
  }

  function setStage(stage: ReceptionQueueStage) {
    selectedStage.value = stage;
  }

  // One loader for every way the stage can change — clicking a tab, restoring
  // the last session, or following a link. `immediate` covers the initial load,
  // so there is no separate mount-time fetch to fall out of step with this.
  watch(
    selectedStage,
    (stage) => {
      void queueStore.fetchReceptionQueue(stage);
    },
    { immediate: true },
  );

  /**
   * Re-fetch on a live patient-flow board update (§10.4, useReceptionLiveSync)
   */
  async function refetchQueue() {
    await queueStore.fetchReceptionQueue(selectedStage.value);
  }

  /**
   * Call (§10.3, §16 #3, decided + built 2026-08-11)
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

  /**
   * Open a queue row's patient in the main pane
   */
  async function handleQueueOpen(item: QueueItem) {
    const patientId = queueStore.tasks.find((task) => task.id === item.id)?.patientId;
    if (!patientId) return;

    patientStore.setCurrentPatient(patientId);

    const patient =
      patientStore.patients.get(patientId) ?? (await patientStore.fetchPatient(patientId));
    if (patient) recentStore.addRecent(patient);
  }

  /**
   * Drag-to-reorder (Volume 2.1 §10.3 "Reorder", Volume 3.7 T5.5).
   */
  async function handleQueueReorder(orderedItems: QueueItem[]) {
    const ok = await queueStore.reorderQueue(orderedItems.map((item) => item.id));
    if (!ok) {
      toast.critical(queueStore.error ?? t("queue.reorder_failed"));
    }
    await queueStore.fetchReceptionQueue(selectedStage.value);
    void fetchStageCounts();
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
    const patientId = queueStore.tasks.find((task) => task.id === target.id)?.patientId;
    cancelSubmitting.value = true;
    const ok = await queueStore.cancelQueueItem(target.id, cancelReason.value.trim());
    cancelSubmitting.value = false;
    if (ok) {
      toast.success(t("queue.cancel_success", { name: target.name }));
      closeCancelDialog();
      void fetchStageCounts();
      if (patientId) options.onCancelled?.(patientId);
    } else {
      toast.critical(queueStore.error ?? t("queue.cancel_failed"));
    }
  }

  return {
    queue,
    selectedStage,
    stageCounts,
    setStage,
    fetchStageCounts,
    isLoading,
    error,
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
