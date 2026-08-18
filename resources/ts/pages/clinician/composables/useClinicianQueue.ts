/**
 * Clinician Workspace Queue Composable (Volume 2.2 §4.1, Volume 1.4 §3.1)
 * =========================================================================
 * Manages the physician/clinician consultation work queue across 4 clinical stages:
 *   - `waiting_provider`: Triaged patients waiting for doctor consultation.
 *   - `in_consultation`: Patients currently in active consultation with the provider.
 *   - `admitted`: Inpatients currently admitted in wards requiring clinician review.
 *   - `completed`: Completed encounters for the shift.
 */

import { computed, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import type { QueueItem, QueuePriority } from "@/components/common/Queue.vue";
import { stepBadgeStatus, stepLabelKey } from "@/composables/patientFlowStep";
import type { ReadinessContext, VisitContext } from "@/stores/queueStore";

export type ClinicianQueueStage = "waiting_provider" | "in_consultation" | "admitted" | "completed";

export interface ClinicianEncounterItem {
  id: string;
  encounterNumber: string;
  patientId: string;
  patientNumber: string;
  patientName: string;
  appointmentId?: string | null;
  appointmentStatus?: string | null;
  /** Server-resolved flow step (PatientFlowStep). Authoritative — never re-derive it here. */
  visitStage?: string | null;
  isTriaged?: boolean;
  triagedAt?: string | null;
  triageSummary?: string | null;
  arrivalMode?: "scheduled_checkin" | "walk_in" | "emergency" | null;
  admissionId?: string | null;
  primaryClinicianUserId?: string | null;
  primaryClinicianName?: string | null;
  status: string;
  statusReason?: string | null;
  openedAt?: string | null;
  closedAt?: string | null;
  hasMedicalRecord: boolean;
  latestMedicalRecordStatus?: string | null;
  createdAt: string;
  priority?: "routine" | "urgent" | "critical";
  waitMinutes?: number;
}

export interface UseClinicianQueueOptions {
  onSelectPatient?: (
    patientId: string,
    encounterId: string | null,
    visit: VisitContext | null,
    readiness: ReadinessContext | null
  ) => void;
}

export function useClinicianQueue(options: UseClinicianQueueOptions = {}) {
  const { t, locale } = useI18n({ useScope: "global" });

  /**
   * A page of one pile, not of everything. Large enough that a real clinic list
   * fits, and asked for explicitly so the number is visible here rather than
   * inherited from a server default nobody was looking at.
   */
  const QUEUE_PAGE_SIZE = 100;

  const selectedStage = ref<ClinicianQueueStage>("waiting_provider");
  const encounters = ref<ClinicianEncounterItem[]>([]);
  const isLoading = ref(false);
  const error = ref<string | null>(null);

  const stageCounts = ref<Record<ClinicianQueueStage, number>>({
    waiting_provider: 0,
    in_consultation: 0,
    admitted: 0,
    completed: 0,
  });

  async function fetchEncounters() {
    isLoading.value = true;
    error.value = null;
    try {
      // One pile, asked for by name. This used to fetch "/clinician/encounters"
      // with no parameters at all: the server's default page is 15, so a doctor
      // saw at most 15 visits in total and the 16th simply did not exist — the
      // four tabs were slicing up one truncated page.
      const params = new URLSearchParams({
        queueStage: selectedStage.value,
        perPage: String(QUEUE_PAGE_SIZE),
      });

      const res = await fetch(`/api/v1/clinician/encounters?${params.toString()}`, {
        headers: { "X-Requested-With": "XMLHttpRequest" },
      });

      if (res.ok) {
        const body = await res.json();
        encounters.value = (body.data ?? []).map((e: any) => ({
          id: e.id,
          encounterNumber: e.encounterNumber || e.id,
          patientId: e.patientId,
          patientNumber: e.patientNumber || "MRN-0000",
          patientName: e.patientName || "Patient",
          appointmentId: e.appointmentId,
          appointmentStatus: e.appointmentStatus,
          visitStage: e.visitStage,
          isTriaged: !!e.isTriaged,
          triagedAt: e.triagedAt,
          triageSummary: e.triageSummary,
          arrivalMode: e.arrivalMode,
          admissionId: e.admissionId,
          primaryClinicianUserId: e.primaryClinicianUserId,
          primaryClinicianName: e.primaryClinicianName,
          status: e.status || "open",
          statusReason: e.statusReason,
          openedAt: e.openedAt,
          closedAt: e.closedAt,
          hasMedicalRecord: !!e.hasMedicalRecord,
          latestMedicalRecordStatus: e.latestMedicalRecordStatus,
          createdAt: e.createdAt || new Date().toISOString(),
          priority: (e.priority === "critical" || e.arrivalMode === "emergency" ? "critical" : e.priority === "urgent" ? "urgent" : "normal") as QueuePriority,
          waitMinutes: e.waitMinutes || 5,
        }));
        error.value = null;
        void fetchStageCounts();
      } else {
        encounters.value = [];
        error.value = null;
        void fetchStageCounts();
      }
    } catch {
      encounters.value = [];
      error.value = null;
    } finally {
      isLoading.value = false;
    }
  }

  // The stage drives the query, so every way it can change — clicking a tab,
  // restoring the last session, following a link — loads that pile.
  watch(selectedStage, () => { void fetchEncounters(); });

  void fetchEncounters();
  void fetchStageCounts();

  /**
   * Totals for the tab badges, from the server.
   *
   * These were counted from `encounters.value` — the page just fetched — so the
   * badges described a truncated list rather than the queue, and every tab
   * agreed with the same wrong picture.
   */
  async function fetchStageCounts() {
    try {
      const res = await fetch("/api/v1/clinician/encounters/queue-stage-counts", {
        headers: { "X-Requested-With": "XMLHttpRequest" },
      });
      if (!res.ok) return;

      const body = await res.json();
      const counts = body.data ?? {};
      stageCounts.value = {
        waiting_provider: counts.waiting_provider ?? 0,
        in_consultation: counts.in_consultation ?? 0,
        admitted: counts.admitted ?? 0,
        completed: counts.completed ?? 0,
      };
    } catch {
      // Leave the last known totals rather than blanking the tabs.
    }
  }

  function setStage(stage: ClinicianQueueStage) {
    selectedStage.value = stage;
  }

  /**
   * The server already returned exactly this pile, so there is nothing left to
   * filter here.
   *
   * The rule that used to live in this computed — duplicated in the counts
   * function beside it — tested encounter statuses that do not exist
   * ("admitted", "completed", "resolved", "in_consultation", "open"; the real
   * set is opened / in_progress / ready_for_sign / signed / closed / amended /
   * cancelled). Every one of those comparisons was dead, and a visit with no
   * appointment could only qualify through `status === "open"`, so walk-ins were
   * fetched and then belonged to no tab at all. It now lives in
   * ClinicianQueueStage, in SQL, defined once.
   */
  const filteredEncounters = computed<ClinicianEncounterItem[]>(() => encounters.value);

  const queueItems = computed<QueueItem[]>(() => {
    void locale.value;
    return filteredEncounters.value.map((item) => {
      let status: QueueItem["status"] = "pending";
      let statusLabel: string | undefined = undefined;

      // The server resolves the step once (PatientFlowStep) and every workspace
      // renders it through the same shared mapping as reception. This used to
      // guess locally from `status === "open" && hasMedicalRecord`, which
      // tracked "a note exists" rather than "a doctor started" and had no idea
      // nursing pickup existed.
      const stepStatus = stepBadgeStatus(item.visitStage);
      const stepKey = stepLabelKey(item.visitStage);

      if (item.admissionId) {
        status = "success";
        statusLabel = t("patient.stage_admitted_inpatient");
      } else if (stepStatus !== null && stepKey !== null) {
        // Priority still escalates the colour for a critical patient who is
        // waiting; it never overrides "somebody is with them right now".
        status = stepStatus === "warning" && item.priority === "critical" ? "critical" : stepStatus;
        statusLabel = t(stepKey);
      } else if (item.status === "in_consultation") {
        status = "info";
        statusLabel = t("patient.stage_in_consultation");
      } else if (item.status === "completed" || item.status === "closed") {
        status = "complete";
        statusLabel = t("patient.stage_completed");
      } else {
        status = item.priority === "critical" ? "critical" : "warning";
        statusLabel = t("patient.stage_waiting_provider");
      }

      return {
        id: item.id,
        name: item.patientName,
        waitTime: item.openedAt ? new Date(item.openedAt).toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" }) : "Today",
        waitMinutes: item.waitMinutes,
        priority: item.priority === "critical" ? "critical" : item.priority === "urgent" ? "urgent" : "normal",
        status,
        statusLabel,
        category: item.admissionId ? "Inpatient Ward" : "OPD Consultation",
        hasWarning: false,
      };
    });
  });

  function handleOpenItem(item: QueueItem) {
    const raw = encounters.value.find((e) => e.id === item.id);
    if (!raw) return;

    if (options.onSelectPatient) {
      options.onSelectPatient(
        raw.patientId,
        raw.id,
        {
          appointmentId: raw.appointmentId,
          appointmentStatus: raw.appointmentStatus ?? raw.status,
          stage: raw.visitStage ?? raw.appointmentStatus ?? raw.status,
          visitStage: raw.visitStage ?? null,
          isAdmitted: !!raw.admissionId || raw.status === "admitted",
          encounterType: raw.admissionId ? "inpatient" : "ambulatory",
          arrivalMode: raw.arrivalMode ?? null,
        } as VisitContext,
        null
      );
    }
  }

  return {
    selectedStage,
    stageCounts,
    fetchStageCounts,
    isLoading,
    error,
    queueItems,
    filteredEncounters,
    setStage,
    refreshQueue: fetchEncounters,
    handleOpenItem,
  };
}
