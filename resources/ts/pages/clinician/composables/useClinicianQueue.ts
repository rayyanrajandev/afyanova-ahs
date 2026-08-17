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
      const res = await fetch("/api/v1/clinician/encounters", {
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
        calculateStageCounts();
      } else {
        encounters.value = [];
        error.value = null;
        calculateStageCounts();
      }
    } catch {
      encounters.value = [];
      error.value = null;
      calculateStageCounts();
    } finally {
      isLoading.value = false;
    }
  }

  void fetchEncounters();

  function calculateStageCounts() {
    const counts: Record<ClinicianQueueStage, number> = {
      waiting_provider: 0,
      in_consultation: 0,
      admitted: 0,
      completed: 0,
    };

    encounters.value.forEach((item) => {
      const isAdmitted = !!item.admissionId || item.status === "admitted";
      const isComplete = item.status === "completed" || item.status === "closed" || item.status === "resolved" || item.appointmentStatus === "completed";
      const isInConsult = item.visitStage === "with_clinician" || item.status === "in_consultation" || item.appointmentStatus === "in_consultation";
      const isWaitingTriage = item.appointmentStatus === "waiting_triage" && !item.isTriaged;
      const isWaitingProvider = (item.appointmentStatus === "waiting_provider" || item.isTriaged || (!item.appointmentId && item.status === "open")) && !isAdmitted && !isComplete && !isInConsult && !isWaitingTriage;

      if (isAdmitted) {
        counts.admitted++;
      } else if (isComplete) {
        counts.completed++;
      } else if (isInConsult) {
        counts.in_consultation++;
      } else if (isWaitingProvider) {
        counts.waiting_provider++;
      }
    });

    stageCounts.value = counts;
  }

  function setStage(stage: ClinicianQueueStage) {
    selectedStage.value = stage;
  }

  const filteredEncounters = computed<ClinicianEncounterItem[]>(() => {
    return encounters.value.filter((item) => {
      const isAdmitted = !!item.admissionId || item.status === "admitted";
      const isComplete = item.status === "completed" || item.status === "closed" || item.status === "resolved" || item.appointmentStatus === "completed";
      const isInConsult = item.visitStage === "with_clinician" || item.status === "in_consultation" || item.appointmentStatus === "in_consultation";
      const isWaitingTriage = item.appointmentStatus === "waiting_triage" && !item.isTriaged;
      const isWaitingProvider = (item.appointmentStatus === "waiting_provider" || item.isTriaged || (!item.appointmentId && item.status === "open")) && !isAdmitted && !isComplete && !isInConsult && !isWaitingTriage;

      if (selectedStage.value === "admitted") {
        return isAdmitted;
      }
      if (selectedStage.value === "completed") {
        return isComplete;
      }
      if (selectedStage.value === "in_consultation") {
        return isInConsult && !isAdmitted && !isComplete;
      }
      // default: waiting_provider (only patients that have completed triage)
      return isWaitingProvider;
    });
  });

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
    isLoading,
    error,
    queueItems,
    filteredEncounters,
    setStage,
    refreshQueue: fetchEncounters,
    handleOpenItem,
  };
}
