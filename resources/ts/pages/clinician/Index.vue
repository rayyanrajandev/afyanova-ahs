/**
 * Clinician Workspace (Volume 2.2 §4)
 * ====================================
 * The primary workstation for Physicians, Medical Officers, and Clinical Providers.
 * Built on SplitPane architecture with Live Consultation Queue, SOAP Documentation,
 * ICD-10 Diagnostic Coding, Diagnostic & Prescription Order Entry, and Inpatient Ward Actions.
 */

<script setup lang="ts">
import {
  Activity,
  BedDouble,
  FileCheck,
  FileText,
  FlaskConical,
  HeartPulse,
  History,
  Pill,
  Radio,
  Save,
  Search,
  Stethoscope,
  Users,
} from "lucide-vue-next";
import { computed, ref, watch, type Ref } from "vue";
import { useI18n } from "vue-i18n";
import EmptyState from "@/components/common/EmptyState.vue";
import PatientFlowTimeline from "@/components/common/PatientFlowTimeline.vue";
import SplitPane from "@/components/common/SplitPane.vue";
import { Badge } from "@/components/ui/badge";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { usePatientFlowLiveSync } from "@/composables/usePatientFlowLiveSync";
import { useToast } from "@/composables/useToast";
import { useWorkspaceUrlSync } from "@/composables/useWorkspaceUrlSync";
import { usePatientSearch } from "@/pages/reception/composables/usePatientSearch";
import { usePatientStore, type Patient } from "@/stores/patientStore";
import type { ReadinessContext, VisitContext } from "@/stores/queueStore";

// Clinician Components & Composables
import ClinicianPatientHeader from "./components/ClinicianPatientHeader.vue";
import ClinicianPatientListPanel from "./components/ClinicianPatientListPanel.vue";
import ConsultationNoteTab from "./components/ConsultationNoteTab.vue";
import ConsultationQueuePanel from "./components/ConsultationQueuePanel.vue";
import DiagnosticOrdersTab from "./components/DiagnosticOrdersTab.vue";
import ConsultationTakeoverDialog from "./components/ConsultationTakeoverDialog.vue";
import InpatientAdmissionDialog from "./components/InpatientAdmissionDialog.vue";
import PrescriptionsTab from "./components/PrescriptionsTab.vue";
import ResultsReviewTab from "./components/ResultsReviewTab.vue";
import VitalsHistoryTab from "./components/VitalsHistoryTab.vue";
import { useClinicianEncounter } from "./composables/useClinicianEncounter";
import { isDiagnosticOrderOutstanding, useClinicianOrders } from "./composables/useClinicianOrders";
import { useClinicianQueue } from "./composables/useClinicianQueue";
import { useClinicianResults } from "./composables/useClinicianResults";

const { t } = useI18n({ useScope: "global" });
const toast = useToast();
const patientStore = usePatientStore();

const CLINICIAN_CONTEXT_TAB_KEY = "afyanova:clinician:context_tab";
const CLINICIAN_CHART_TAB_KEY = "afyanova:clinician:chart_tab";

function loadSavedContextTab(): "queue" | "patients" {
  try {
    const saved = localStorage.getItem(CLINICIAN_CONTEXT_TAB_KEY);
    if (saved === "queue" || saved === "patients") return saved;
  } catch {
    // ignore
  }
  return "queue";
}

function loadSavedChartTab(): "consultation" | "vitals" | "orders" | "prescriptions" | "results" | "activity" {
  try {
    const saved = localStorage.getItem(CLINICIAN_CHART_TAB_KEY);
    if (["consultation", "vitals", "orders", "prescriptions", "results", "activity"].includes(saved as string)) {
      return saved as any;
    }
  } catch {
    // ignore
  }
  return "consultation";
}

// Left Context Pane active tab
const contextTab = ref<"queue" | "patients">(loadSavedContextTab());

// Main Pane chart active tab
const activeChartTab = ref<"consultation" | "vitals" | "orders" | "prescriptions" | "results" | "activity">(loadSavedChartTab());

watch(contextTab, (tab) => {
  try {
    localStorage.setItem(CLINICIAN_CONTEXT_TAB_KEY, tab);
  } catch {
    // ignore
  }
});

watch(activeChartTab, (tab) => {
  try {
    localStorage.setItem(CLINICIAN_CHART_TAB_KEY, tab);
  } catch {
    // ignore
  }
});

// Active Patient Context
const selectedPatient = ref<Patient | null>(null);
const selectedEncounterId = ref<string | null>(null);
const selectedVisit = ref<VisitContext | null>(null);
const selectedReadiness = ref<ReadinessContext | null>(null);
const selectedPatientAllergies = ref<string[]>([]);
const isLoadingVisitContext = ref(false);

// Modals
const showAdmissionDialog = ref(false);

// Composables
const encounterManager = useClinicianEncounter();
const ordersManager = useClinicianOrders();
const resultsManager = useClinicianResults();
const searchManager = usePatientSearch({ workspace: "clinician" });

const queueManager = useClinicianQueue({
  onSelectPatient: (patientId, encounterId, visit, readiness) => {
    openPatientRecord(patientId, encounterId, visit, readiness);
  },
});

async function openPatientRecord(
  patientId: string,
  encounterId: string | null = null,
  visit: VisitContext | null = null,
  readiness: ReadinessContext | null = null
) {
  // If visit was not directly provided, set loading flag so UI does not flash "Not Checked In"
  isLoadingVisitContext.value = !visit;

  // Load patient entity
  let patient = patientStore.patients.get(patientId);
  if (!patient) {
    patient = await patientStore.fetchPatient(patientId);
  }
  if (!patient) {
    // The patient does not exist — a deleted record, or a stale link/recent
    // entry pointing at one. Clear the whole selection so the workspace falls
    // back to its own empty state instead of holding a half-opened record,
    // and drop the dead id from the URL so a reload does not retry it.
    isLoadingVisitContext.value = false;
    selectedPatient.value = null;
    selectedEncounterId.value = null;
    selectedVisit.value = null;
    selectedReadiness.value = null;
    selectedPatientAllergies.value = [];
    encounterManager.resetNoteFields();
    ordersManager.clearOrders();
    urlSync.clearPatientSelectionFromUrl();
    return;
  }

  selectedPatient.value = patient;
  selectedEncounterId.value = encounterId;
  selectedVisit.value = visit;
  selectedReadiness.value = readiness;

  let resolvedEncounterId = encounterId;

  // Load summary / alerts and derive visit context if needed
  try {
    const summary = await patientStore.fetchPatientSummary(patientId);
    if (selectedPatient.value?.id === patientId && summary) {
      selectedPatientAllergies.value = (summary?.alerts ?? []).map((a) => a.allergen || a.substance || "Allergy");

      if (!selectedVisit.value) {
        if (summary.activeAppointment) {
          selectedVisit.value = {
            appointmentId: summary.activeAppointment.id,
            appointmentStatus: summary.activeAppointment.status,
            stage: summary.activeAppointment.visitStage ?? summary.activeAppointment.status,
            visitStage: summary.activeAppointment.visitStage ?? null,
            isAdmitted: false,
            encounterType: "ambulatory",
            arrivalMode: null,
            visitCategory: summary.activeAppointment.department ?? null,
          };
        } else if (summary.latestEncounter) {
          selectedVisit.value = {
            appointmentId: null,
            appointmentStatus: summary.latestEncounter.status,
            stage: summary.latestEncounter.status,
            isAdmitted: false,
            encounterType: "ambulatory",
            arrivalMode: null,
            visitCategory: null,
          };
        }
      }

      // If encounter ID was not provided in arguments, resolve it from active appointment or latest encounter
      if (!resolvedEncounterId) {
        if (summary.activeAppointment?.id) {
          const res = await fetch(
            `/api/v1/clinician/encounters/by-appointment/${encodeURIComponent(summary.activeAppointment.id)}?view=workspace`,
            { headers: { "X-Requested-With": "XMLHttpRequest" } },
          );
          if (res.ok) {
            const body = await res.json();
            if (body.data?.encounter?.id) {
              resolvedEncounterId = body.data.encounter.id;
            }
          }
        } else if (summary.latestEncounter?.id) {
          resolvedEncounterId = summary.latestEncounter.id;
        }
      }
    }
  } finally {
    if (selectedPatient.value?.id === patientId) {
      isLoadingVisitContext.value = false;
    }
  }

  // If encounter ID is available, load encounter workspace and hydrate all orders
  if (resolvedEncounterId) {
    selectedEncounterId.value = resolvedEncounterId;
    await encounterManager.loadEncounterWorkspace(resolvedEncounterId);
    if (encounterManager.encounterWorkspace.value) {
      ordersManager.hydrateOrdersFromWorkspace(encounterManager.encounterWorkspace.value);
    }
    if (!selectedVisit.value && encounterManager.encounterWorkspace.value?.appointment) {
      const appt = encounterManager.encounterWorkspace.value.appointment;
      selectedVisit.value = {
        appointmentId: appt.id,
        appointmentStatus: appt.status,
        stage: appt.status,
        isAdmitted: !!encounterManager.encounterWorkspace.value.admission,
        encounterType: encounterManager.encounterWorkspace.value.admission ? "inpatient" : "ambulatory",
        arrivalMode: (appt as any).arrival_mode ?? null,
        visitCategory: appt.department ?? null,
      };
    }
  } else {
    encounterManager.resetNoteFields();
    ordersManager.clearOrders();
  }

  // Fetch all active orders (medications, lab, imaging) directly from API for this patient
  ordersManager.fetchOrders(patientId, resolvedEncounterId ?? undefined);

  // Fetch all diagnostic laboratory and radiology results for the selected patient
  resultsManager.fetchResults(patientId);
}

// Sync selected patient, encounter, and active tabs with URL query params (?patient=...&encounter=...&tab=...&chartTab=...)
const urlSync = useWorkspaceUrlSync({
  activeTab: contextTab as Ref<string>,
  activeChartTab: activeChartTab as Ref<string>,
  selectedPatientId: computed(() => selectedPatient.value?.id),
  selectedEncounterId: selectedEncounterId,
  onHydrateTab: (tab) => {
    if (tab === "queue" || tab === "patients") {
      contextTab.value = tab;
    }
  },
  onHydrateChartTab: (tab) => {
    if (["consultation", "vitals", "orders", "prescriptions", "results", "activity"].includes(tab)) {
      activeChartTab.value = tab as any;
    }
  },
  onHydratePatient: async (patientId, encounterId) => {
    if (!patientId) return;
    await openPatientRecord(patientId, encounterId || null, null, null);
  },
});

function handleSelectPatientFromDirectory(patientId: string) {
  openPatientRecord(patientId, null, null, null);
}

async function handleSaveDraft() {
  await encounterManager.saveDraftNote(false);
}

async function handleSignComplete() {
  const success = await encounterManager.signAndCompleteConsultation();
  if (success) {
    // Refresh queue & advance stage
    await queueManager.refreshQueue();
  }
}

function handleAdmittedSuccess() {
  queueManager.refreshQueue();
}

/**
 * What the doctor may do with this record right now.
 *
 * `waiting_provider` / `waiting_clinician` used to resolve to "active", which
 * meant a patient who was merely *queued* for a doctor had a fully writable
 * chart: notes editable, diagnoses addable, labs and prescriptions orderable,
 * and "Admit to Ward" and "Sign & Complete" offered alongside "Call Patient In"
 * with nothing saying which came first (2026-08-16).
 *
 * That is a clinical-safety problem, not a layout one: documenting or ordering
 * on a patient nobody has called in produces a record of a consultation that
 * never happened, attributed to a doctor who never started one — and it is
 * exactly how the flow log ends up disagreeing with reality again.
 *
 * `awaiting_start` closes it. The chart is read-only and the header offers one
 * action, "Call Patient In", which is a real server transition
 * (PATCH clinician/visits/{id}/start-consultation) that takes ownership and
 * records the step. Everything unlocks once the consultation is genuinely open.
 */
const clinicalMode = computed<"active" | "awaiting_start" | "triage_pending" | "read_only" | "completed">(() => {
  if (isLoadingVisitContext.value && !selectedVisit.value) return "read_only";
  if (selectedVisit.value?.isAdmitted || selectedVisit.value?.encounterType === "inpatient") return "active";
  const stage = selectedVisit.value?.appointmentStatus ?? selectedVisit.value?.stage;
  if (stage === "waiting_triage" || stage === "in_triage") return "triage_pending";
  if (stage === "with_nurse") return "triage_pending";
  if (
    stage === "waiting_provider" ||
    stage === "waiting_clinician" ||
    stage === "waiting_clinician_review" ||
    stage === "triaged"
  ) {
    return "awaiting_start";
  }
  if (
    stage === "in_consultation" ||
    stage === "with_clinician" ||
    stage === "in_progress" ||
    // Encounter-level statuses, reached when there is no appointment to claim
    // against at all (a direct encounter) — there is no consultation to start,
    // so these stay writable.
    stage === "open" ||
    stage === "opened"
  ) {
    return "active";
  }
  if (stage === "completed" || stage === "closed" || stage === "resolved") {
    return "completed";
  }
  if (selectedEncounterId.value) return "active";
  return "read_only";
});

const isStartingConsultation = ref(false);
const flowTimeline = ref<{ refresh: () => void } | null>(null);
const takeoverPrompt = ref<{ appointmentId: string; ownerUserId: number | null } | null>(null);

/**
 * Starts (or takes over) the consultation for the selected visit.
 *
 * This replaces the previous handleBypassTriage(), which resolved the encounter
 * with a GET and then assigned `selectedVisit.value.stage = "in_consultation"`
 * in local component state. Nothing was ever sent to the server: the badge
 * flipped for the doctor who clicked it, reverted on refresh, and no other
 * workspace ever saw that the patient was with a doctor — the exact bug this
 * screen was reported for.
 *
 * The backend action has existed all along; the clinician workspace simply had
 * no route to call and never called it (see routes/api-workspaces.php,
 * `clinician/visits/{id}/start-consultation`). Ownership arbitration, takeover
 * and the audit row are all handled there.
 */
async function handleStartConsultation(forceTakeover = false, takeoverReason?: string) {
  const patientId = selectedPatient.value?.id;
  const appointmentId = selectedVisit.value?.appointmentId;
  if (!patientId || !appointmentId || isStartingConsultation.value) return;

  isStartingConsultation.value = true;

  try {
    const res = await fetch(
      `/api/v1/clinician/visits/${encodeURIComponent(appointmentId)}/start-consultation`,
      {
        method: "PATCH",
        headers: {
          "Content-Type": "application/json",
          Accept: "application/json",
          "X-Requested-With": "XMLHttpRequest",
        },
        body: JSON.stringify(
          forceTakeover ? { forceTakeover: true, takeoverReason: takeoverReason ?? null } : {},
        ),
      },
    );

    // Another clinician owns this consultation. The backend refuses rather than
    // silently stealing it, and records the blocked attempt — so surface the
    // choice instead of retrying behind the doctor's back.
    if (res.status === 409) {
      const conflict = (await res.json()) as {
        context?: { consultationOwnerUserId?: number };
        message?: string;
      };
      takeoverPrompt.value = {
        appointmentId,
        ownerUserId: conflict.context?.consultationOwnerUserId ?? null,
      };
      toast.warning(
        conflict.message ??
          t("clinician.consultation_owned_by_other") ??
          "Another clinician is already with this patient.",
      );
      return;
    }

    if (!res.ok) {
      const failure = (await res.json().catch(() => ({}))) as { message?: string };
      toast.critical(failure.message ?? "Could not start the consultation. Try again.");
      return;
    }

    const body = (await res.json()) as { data?: { status?: string; visitStage?: string | null } };
    takeoverPrompt.value = null;

    // Drive local state from what the server actually stored, never from an
    // assumption about what the call did.
    if (selectedVisit.value && body.data?.status) {
      selectedVisit.value.stage = body.data.status;
      selectedVisit.value.appointmentStatus = body.data.status;
      // The profile badge reads the flow step, not the status. Updating only
      // the two status fields left it showing "Waiting for Clinician" after the
      // doctor had already called the patient in, until a reload re-fetched the
      // patient summary.
      if (body.data.visitStage !== undefined) {
        selectedVisit.value.visitStage = body.data.visitStage;
      }
    }

    await openEncounterForAppointment(appointmentId);

    toast.success(
      forceTakeover
        ? t("clinician.consultation_taken_over_toast") ?? "You have taken over this consultation."
        : t("clinician.consultation_started_toast") ?? "Consultation started — the patient is now with you.",
    );

    await queueManager.refreshQueue();
    flowTimeline.value?.refresh();
  } catch {
    toast.critical("Could not start the consultation. Check your connection and try again.");
  } finally {
    isStartingConsultation.value = false;
  }
}

/** Resolves and opens the encounter workspace for a visit already in consultation. */
async function openEncounterForAppointment(appointmentId: string) {
  const res = await fetch(
    `/api/v1/clinician/encounters/by-appointment/${encodeURIComponent(appointmentId)}?view=workspace`,
    { headers: { "X-Requested-With": "XMLHttpRequest" } },
  );
  if (!res.ok) return;

  const body = (await res.json()) as { data?: any };
  const encounter = body.data?.encounter;
  if (!encounter?.id) return;

  selectedEncounterId.value = encounter.id;
  await encounterManager.loadEncounterWorkspace(encounter.id);
  if (encounterManager.encounterWorkspace.value) {
    ordersManager.hydrateOrdersFromWorkspace(encounterManager.encounterWorkspace.value);
  }
}

function handleConfirmTakeover(reason: string) {
  void handleStartConsultation(true, reason);
}

const isSendingForDiagnostics = ref(false);

/**
 * True when this consultation has diagnostic work outstanding — the only case
 * where "send the patient out" is a meaningful thing to offer.
 *
 * The rule lives in isDiagnosticOrderOutstanding() so it cannot drift from the
 * status vocabulary again. This compared `status !== "complete"` while the API
 * spells it `completed`, so a finished lab or X-ray never stopped counting and
 * the button stayed on screen for the rest of the consultation — offering to
 * send a patient out for results that were already back.
 */
const hasOutstandingDiagnostics = computed<boolean>(() =>
  ordersManager.activeOrders.value.some(isDiagnosticOrderOutstanding),
);

/**
 * Sends the patient out for the diagnostics just ordered, without ending the
 * consultation.
 *
 * Ordering a test and sending the patient to the lab are two different acts,
 * and only the doctor knows when the second one happens — they may order
 * bloods and keep examining. So this is an explicit control rather than a side
 * effect of placing an order.
 *
 * The doctor keeps the visit: the server preserves consultation_started_at, so
 * the patient returns as "Waiting for Doctor Review" rather than re-joining the
 * queue as though they had never been seen. What changes is the *room* — it is
 * released, so the queue can route the doctor their next patient instead of
 * showing them busy while the patient stands in the lab.
 */
async function handleSendForDiagnostics() {
  const appointmentId = selectedVisit.value?.appointmentId;
  if (!appointmentId || isSendingForDiagnostics.value) return;

  isSendingForDiagnostics.value = true;

  try {
    const res = await fetch(
      `/api/v1/clinician/visits/${encodeURIComponent(appointmentId)}/provider-workflow`,
      {
        method: "PATCH",
        headers: {
          "Content-Type": "application/json",
          Accept: "application/json",
          "X-Requested-With": "XMLHttpRequest",
        },
        body: JSON.stringify({ status: "waiting_provider" }),
      },
    );

    if (!res.ok) {
      const failure = (await res.json().catch(() => ({}))) as { message?: string };
      toast.critical(
        failure.message ??
          t("clinician.send_for_diagnostics_failed") ??
          "Could not send the patient out. Try again.",
      );
      return;
    }

    const body = (await res.json()) as { data?: { status?: string; visitStage?: string | null } };

    // Same rule as starting a consultation: drive local state from what the
    // server stored, never from an assumption about what the call did.
    if (selectedVisit.value && body.data?.status) {
      selectedVisit.value.stage = body.data.status;
      selectedVisit.value.appointmentStatus = body.data.status;
      if (body.data.visitStage !== undefined) {
        selectedVisit.value.visitStage = body.data.visitStage;
      }
    }

    toast.success(
      t("clinician.sent_for_diagnostics_toast") ??
        "Patient sent out. They will return for your review once results are ready.",
    );

    await queueManager.refreshQueue();
    flowTimeline.value?.refresh();
  } catch {
    toast.critical("Could not send the patient out. Check your connection and try again.");
  } finally {
    isSendingForDiagnostics.value = false;
  }
}

/**
 * The clinician queue rows carry no owner identity, and the 409 body gives a
 * user id rather than a name — so the dialog deliberately shows the unnamed
 * warning. Naming the wrong colleague on a takeover record is worse than not
 * naming one, and resolving the id would need a lookup this screen does not
 * have.
 */
const takeoverPatientName = computed<string | null>(() => {
  const name = selectedPatient.value?.name?.[0];
  if (!name) return null;

  return [...(name.given ?? []), name.family].filter(Boolean).join(" ") || null;
});

// Live board sync (2026-08-16 flow audit, finding 03) — until now the clinician
// workspace never subscribed, so a transition made anywhere else (reception
// checking a patient in, a nurse finishing triage, another doctor taking over)
// never reached this screen without a manual reload.
usePatientFlowLiveSync({
  onBoardUpdated: () => {
    void queueManager.refreshQueue();
  },
});
</script>

<template>
  <SplitPane
    direction="horizontal"
    :initial-ratio="0.28"
    :min-size="280"
    persist-key="afyanova:clinician:split"
    class="h-full"
  >
    <!-- ============================================================
         LEFT CONTEXT PANE: Queue & Patient Directory
         ============================================================ -->
    <template #start>
      <aside class="flex h-full flex-col rounded-lg border border-border bg-surface overflow-hidden">
        <Tabs v-model="contextTab" class="flex flex-1 flex-col overflow-hidden">
          <!-- Context Header Tabs -->
          <div class="border-b border-border bg-surface px-3 pt-1 shrink-0">
            <TabsList class="h-8 gap-1 bg-transparent p-0 justify-start w-auto border-b-0 -mb-px">
              <TabsTrigger
                value="queue"
                class="h-8 gap-1.5 rounded-none border-b-2 border-transparent px-2 text-xs font-semibold data-[state=active]:border-primary data-[state=active]:bg-transparent data-[state=active]:text-primary cursor-pointer -mb-px"
              >
                <Stethoscope class="size-3.5" aria-hidden="true" />
                <span>{{ t("queue.label") }}</span>
                <Badge
                  v-if="queueManager.queueItems.value.length > 0"
                  variant="secondary"
                  class="ml-0.5 px-1.5 py-0 text-[10px] font-mono tabular-nums transition-colors"
                  :class="contextTab === 'queue' ? 'bg-primary/15 text-primary font-semibold' : 'text-muted-foreground'"
                >
                  {{ queueManager.queueItems.value.length }}
                </Badge>
              </TabsTrigger>
              <TabsTrigger
                value="patients"
                class="h-8 gap-1.5 rounded-none border-b-2 border-transparent px-2 text-xs font-semibold data-[state=active]:border-primary data-[state=active]:bg-transparent data-[state=active]:text-primary cursor-pointer -mb-px"
              >
                <Users class="size-3.5" aria-hidden="true" />
                <span>{{ t("clinician.patients") }}</span>
                <Badge
                  v-if="searchManager.totalPatients.value > 0"
                  variant="secondary"
                  class="ml-0.5 px-1.5 py-0 text-[10px] font-mono tabular-nums transition-colors"
                  :class="contextTab === 'patients' ? 'bg-primary/15 text-primary font-semibold' : 'text-muted-foreground'"
                >
                  {{ searchManager.totalPatients.value }}
                </Badge>
              </TabsTrigger>
            </TabsList>
          </div>

          <!-- Tab 1: Live Consultation Queue -->
          <TabsContent value="queue" class="flex-1 min-h-0 overflow-hidden m-0 data-[state=inactive]:hidden">
            <ConsultationQueuePanel :queue-actions="queueManager" />
          </TabsContent>

          <!-- Tab 2: Patients Directory Lookup -->
          <TabsContent value="patients" class="flex-1 min-h-0 overflow-hidden m-0 data-[state=inactive]:hidden">
            <ClinicianPatientListPanel
              :search="searchManager"
              :on-select-patient="handleSelectPatientFromDirectory"
            />
          </TabsContent>
        </Tabs>
      </aside>
    </template>

    <!-- ============================================================
         RIGHT MAIN PANE: Clinical Workstation Chart & Tools
         ============================================================ -->
    <template #end>
      <div class="flex h-full gap-4">
        <main class="flex flex-1 flex-col overflow-hidden rounded-lg border border-border bg-surface">
          <!-- Empty State: No Patient Selected -->
          <div
            v-if="!selectedPatient"
            class="flex flex-1 items-center justify-center p-6"
          >
            <EmptyState
              illustration="stethoscope"
              :badge="t('clinician.workspace_badge')"
              :title="t('clinician.no_patient_selected_title')"
              :description="t('clinician.no_patient_selected_desc')"
            />
          </div>

          <!-- Active Patient Clinical Workstation -->
          <div v-else class="flex flex-1 flex-col overflow-hidden">
            <!-- Patient Banner & Action Bar -->
            <ClinicianPatientHeader
              :patient="selectedPatient"
              :encounter-id="selectedEncounterId"
              :visit="selectedVisit"
              :readiness="selectedReadiness"
              :allergies="selectedPatientAllergies"
              :is-loading-visit="isLoadingVisitContext"
              :clinical-mode="clinicalMode"
              :is-signing="encounterManager.isSigningNote.value"
              :is-starting-consultation="isStartingConsultation"
              :on-sign-complete="handleSignComplete"
              :on-open-admission-dialog="() => (showAdmissionDialog = true)"
              :on-start-consultation="() => handleStartConsultation()"
              :on-send-for-diagnostics="hasOutstandingDiagnostics ? handleSendForDiagnostics : undefined"
              :is-sending-for-diagnostics="isSendingForDiagnostics"
            />

            <!-- Workstation Tabs -->
            <Tabs v-model="activeChartTab" class="flex flex-1 flex-col overflow-hidden">
              <!-- Tab Navigation Bar -->
              <div class="border-b border-border bg-surface px-3.5 pt-1 shrink-0">
                <TabsList class="h-8 gap-1 bg-transparent p-0 justify-start w-auto border-b-0 -mb-px">
                  <!-- 1. Consultation (SOAP & ICD-10) -->
                  <TabsTrigger
                    value="consultation"
                    class="h-8 gap-1.5 rounded-none border-b-2 border-transparent px-2.5 text-xs font-semibold data-[state=active]:border-primary data-[state=active]:bg-transparent data-[state=active]:text-primary cursor-pointer -mb-px"
                  >
                    <FileText class="size-3.5" />
                    <span>{{ t("clinician.consultation") }}</span>
                  </TabsTrigger>

                  <!-- 2. Vitals & Triage History -->
                  <TabsTrigger
                    value="vitals"
                    class="h-8 gap-1.5 rounded-none border-b-2 border-transparent px-2.5 text-xs font-semibold data-[state=active]:border-primary data-[state=active]:bg-transparent data-[state=active]:text-primary cursor-pointer -mb-px"
                  >
                    <Activity class="size-3.5 text-emerald-600 dark:text-emerald-400" />
                    <span>{{ t("clinician.vitals_and_triage") }}</span>
                  </TabsTrigger>

                  <!-- 3. Diagnostic Orders (Lab & Radiology) -->
                  <TabsTrigger
                    value="orders"
                    class="h-8 gap-1.5 rounded-none border-b-2 border-transparent px-2.5 text-xs font-semibold data-[state=active]:border-primary data-[state=active]:bg-transparent data-[state=active]:text-primary cursor-pointer -mb-px"
                  >
                    <FlaskConical class="size-3.5 text-blue-600 dark:text-blue-400" />
                    <span>{{ t("clinician.diagnostic_orders") }}</span>
                    <span
                      v-if="ordersManager.activeOrders.value.length > 0"
                      class="rounded-full bg-blue-500/15 px-1.5 py-0 text-[10px] font-bold text-blue-600 dark:text-blue-400 font-mono"
                    >
                      {{ ordersManager.activeOrders.value.length }}
                    </span>
                  </TabsTrigger>

                  <!-- 4. Prescriptions (Medication Orders) -->
                  <TabsTrigger
                    value="prescriptions"
                    class="h-8 gap-1.5 rounded-none border-b-2 border-transparent px-2.5 text-xs font-semibold data-[state=active]:border-primary data-[state=active]:bg-transparent data-[state=active]:text-primary cursor-pointer -mb-px"
                  >
                    <Pill class="size-3.5 text-purple-600 dark:text-purple-400" />
                    <span>{{ t("clinician.prescriptions") }}</span>
                    <span
                      v-if="ordersManager.prescriptionDrafts.value.length > 0"
                      class="rounded-full bg-purple-500/15 px-1.5 py-0 text-[10px] font-bold text-purple-600 dark:text-purple-400 font-mono"
                    >
                      {{ ordersManager.prescriptionDrafts.value.length }}
                    </span>
                  </TabsTrigger>

                  <!-- 5. Results Review -->
                  <TabsTrigger
                    value="results"
                    class="h-8 gap-1.5 rounded-none border-b-2 border-transparent px-2.5 text-xs font-semibold data-[state=active]:border-primary data-[state=active]:bg-transparent data-[state=active]:text-primary cursor-pointer -mb-px"
                  >
                    <Radio class="size-3.5 text-amber-600 dark:text-amber-400" />
                    <span>{{ t("clinician.results_review") }}</span>
                    <span
                      v-if="resultsManager.totalResultsCount.value > 0"
                      class="rounded-full bg-amber-500/15 px-1.5 py-0 text-[10px] font-bold text-amber-600 dark:text-amber-400 font-mono"
                    >
                      {{ resultsManager.totalResultsCount.value }}
                    </span>
                  </TabsTrigger>

                  <!-- 6. Activity log — the recorded sequence of this visit -->
                  <TabsTrigger
                    value="activity"
                    class="h-8 gap-1.5 rounded-none border-b-2 border-transparent px-2.5 text-xs font-semibold data-[state=active]:border-primary data-[state=active]:bg-transparent data-[state=active]:text-primary cursor-pointer -mb-px"
                  >
                    <History class="size-3.5 text-teal-600 dark:text-teal-400" />
                    <span>{{ t("flow_timeline.label") }}</span>
                  </TabsTrigger>
                </TabsList>
              </div>

              <!-- Tab 1: Consultation Note (SOAP & ICD-10) -->
              <TabsContent value="consultation" class="flex-1 overflow-y-auto m-0 data-[state=inactive]:hidden">
                <ConsultationNoteTab
                  :encounter="encounterManager"
                  :clinical-mode="clinicalMode"
                />
              </TabsContent>

              <!-- Tab 2: Vitals & Triage History -->
              <TabsContent value="vitals" class="flex-1 overflow-y-auto m-0 data-[state=inactive]:hidden">
                <VitalsHistoryTab
                  :patient="selectedPatient"
                  :vitals="encounterManager.encounterWorkspace.value?.vitals"
                />
              </TabsContent>

              <!-- Tab 3: Diagnostic Orders (Lab & Radiology) -->
              <TabsContent value="orders" class="flex-1 overflow-y-auto m-0 data-[state=inactive]:hidden">
                <DiagnosticOrdersTab
                  :encounter-id="selectedEncounterId"
                  :patient-id="selectedPatient.id"
                  :orders="ordersManager"
                  :clinical-mode="clinicalMode"
                />
              </TabsContent>

              <!-- Tab 4: Prescriptions -->
              <TabsContent value="prescriptions" class="flex-1 overflow-y-auto m-0 data-[state=inactive]:hidden">
                <PrescriptionsTab
                  :encounter-id="selectedEncounterId"
                  :patient-id="selectedPatient.id"
                  :orders="ordersManager"
                  :clinical-mode="clinicalMode"
                />
              </TabsContent>

              <!-- Tab 5: Results Review -->
              <TabsContent value="results" class="flex-1 overflow-y-auto m-0 data-[state=inactive]:hidden">
                <ResultsReviewTab
                  :patient-id="selectedPatient.id"
                  :results-manager="resultsManager"
                  :clinical-mode="clinicalMode"
                />
              </TabsContent>

              <!-- Tab 6: Activity Log -->
              <TabsContent value="activity" class="flex-1 overflow-y-auto m-0 data-[state=inactive]:hidden">
                <PatientFlowTimeline
                  ref="flowTimeline"
                  :patient-id="selectedPatient.id"
                  workspace="clinician"
                />
              </TabsContent>
            </Tabs>
          </div>
        </main>
      </div>
    </template>
  </SplitPane>

  <!-- Consultation Takeover Confirmation -->
  <ConsultationTakeoverDialog
    :open="takeoverPrompt !== null"
    :patient-name="takeoverPatientName"
    :is-submitting="isStartingConsultation"
    @update:open="(value: boolean) => { if (!value) takeoverPrompt = null; }"
    @confirm="handleConfirmTakeover"
  />

  <!-- Inpatient Ward Admission Dialog -->
  <InpatientAdmissionDialog
    v-model:open="showAdmissionDialog"
    :patient="selectedPatient"
    :encounter-id="selectedEncounterId"
    @admitted="handleAdmittedSuccess"
  />
</template>