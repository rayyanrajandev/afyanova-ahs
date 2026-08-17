/**
 * Nursing task queue + open (Volume 2.3 §4.1, §9)
 * =========================================================================
 * Extracted from nursing/Index.vue (2026-08-13, component decomposition —
 * Reception-style separation of concerns). Owns the Tasks context-pane tab:
 * mapping the raw nursing task list into the shared Queue.vue item shape and
 * opening a task (which both marks it in-progress and selects its patient
 * with an active-encounter context).
 */

import { computed } from "vue";
import { useI18n } from "vue-i18n";
import type { QueueItem } from "@/components/common/Queue.vue";
import { stepBadgeStatus, stepLabelKey } from "@/composables/patientFlowStep";
import { useQueueStore, type ReadinessContext, type VisitContext } from "@/stores/queueStore";

export interface UseNursingTasksOptions {
  /**
   * Called when a task is opened with the resolved patient and encounter id,
   * so the caller can drive selection/context without reaching into this
   * composable's dependencies. `visit` carries the patient's journey context
   * (arrival mode, stage, etc.) and `readiness` carries reception admin flags.
   */
  onOpen: (
    patientId: string,
    encounterId: string,
    visit: VisitContext | null,
    readiness: ReadinessContext | null
  ) => void;
}

export function useNursingTasks(options: UseNursingTasksOptions) {
  const { t, locale } = useI18n({ useScope: "global" });
  const queueStore = useQueueStore();

  const tasks = computed(() => queueStore.tasks);

  queueStore.fetchTasks();

  function formatTaskCategory(desc?: string): string {
    if (!desc) return "";
    const lower = desc.toLowerCase();
    if (lower === "outpatient") return t("queue.category_outpatient");
    if (lower === "inpatient") return t("queue.category_inpatient");
    if (lower === "emergency") return t("queue.category_emergency");
    return desc.charAt(0).toUpperCase() + desc.slice(1);
  }

  // `name`/`category` (Volume 3.8, 2026-08-13): `name` is the patient's name
  // and `category` is the real grouping concept (the task description /
  // encounter type) — the two were swapped from the start.
  const taskQueue = computed<QueueItem[]>(() => {
    void locale.value;
    return tasks.value.map((task) => {
      let status: QueueItem["status"] = "warning";
      let statusLabel = t("queue.needs_vitals");

      const stage = task.visit?.stage || task.stage || task.visit?.appointmentStatus;

      /**
       * Ordering matters here, and it was wrong three ways (2026-08-16):
       *
       *  - `task.status === "in_progress"` was checked before every stage
       *    branch. That status is set client-side by markInProgress() the
       *    instant a row is clicked, with no server round trip, so a clicked
       *    row read "In Progress" forever — recording vitals changed the
       *    patient's real step and the badge never moved.
       *  - There was no branch for `with_nurse`, so a patient the nurse had
       *    picked up fell through to the default.
       *  - The default was "Needs Vitals" — a specific clinical instruction
       *    standing in for "this state is unrecognised". Every step added to
       *    PatientFlowStep would silently render as "Needs Vitals" here.
       *
       * The server-resolved step now decides, with two deliberate exceptions
       * kept above it: an admission and a closed encounter are encounter-level
       * facts the visit step does not describe.
       */
      if (
        task.visit?.isAdmitted ||
        task.visit?.encounterType === "inpatient" ||
        stage === "admitted_inpatient" ||
        stage === "admitted"
      ) {
        status = "success";
        statusLabel = t("patient.stage_admitted_inpatient");
      } else if (task.status === "complete") {
        status = "complete";
        statusLabel = t("status.complete");
      } else if (stage === "waiting_triage" || stage === "in_triage") {
        // The one state where this queue's job is to ask for something rather
        // than report a state — so it keeps its call to action.
        status = "warning";
        statusLabel = t("queue.needs_vitals");
      } else {
        const stepStatus = stepBadgeStatus(stage);
        const stepKey = stepLabelKey(stage);

        if (stepStatus !== null && stepKey !== null) {
          status = stepStatus;
          statusLabel = t(stepKey);
        }
      }

      return {
        id: task.id,
        name: task.patientName,
        waitTime: task.dueTime,
        waitMinutes: task.waitMinutes,
        priority: task.priority,
        status,
        statusLabel,
        category: formatTaskCategory(task.description),
        hasWarning:
          task.readiness?.insuranceVerified === false ||
          (task.readiness?.coverageType === "insurance" && task.readiness?.insuranceVerified === null),
      };
    });
  });

  // `QueueItem` (the generic shared Queue.vue item shape) doesn't carry a
  // patientId, so the match goes through the raw `QueueTask` this item was
  // built from (`item.id` === the task/encounter id) rather than extending
  // the shared component's type for one workspace's need.
  function handleTaskOpen(item: QueueItem) {
    // No markInProgress() here any more: it wrote an optimistic client-side
    // status that nothing renders now, and while it did render it outranked the
    // patient's real step — a clicked row stayed "In Progress" through vitals
    // being recorded and beyond. Opening a row is not itself a clinical event;
    // the step changes when the nurse actually starts work (see
    // beginNursingContact in nursing/Index.vue).
    const task = tasks.value.find((task) => task.id === item.id);
    if (!task) return;
    options.onOpen(task.patientId, task.id, task.visit ?? null, task.readiness ?? null);
  }

  const isLoading = computed(() => queueStore.isLoading);
  const error = computed(() => queueStore.error);

  return {
    tasks,
    taskQueue,
    isLoading,
    error,
    handleTaskOpen,
    refetchTasks: () => queueStore.fetchTasks(),
  };
}

export type UseNursingTasks = ReturnType<typeof useNursingTasks>;
