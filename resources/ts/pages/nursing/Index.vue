/**
 * Nursing Workspace (Volume 2.3)
 * ===============================
 * The workspace for nurses to record vitals, perform assessments,
 * administer medications (MAR), and manage their task list.
 *
 * Uses the split-2 layout (context + main) with an optional detail pane
 * for MAR. Composed entirely from Tier 1 components — no new tokens,
 * primitives, or components.
 *
 * Refactored 2026-08-13 for component decomposition and separation of
 * concerns (Reception-style): all feature logic lives in
 * `pages/nursing/composables/*`, all presentation in `pages/nursing/
 * components/*`, and this page is a thin orchestrator that wires them
 * together (cross-composable effects, keyboard shortcuts, split-ratio).
 */

<script setup lang="ts">
import { Activity, ClipboardList, Contact, Users } from "lucide-vue-next";
import { computed, onBeforeUnmount, onMounted, ref, watch, type Ref } from "vue";
import { useI18n } from "vue-i18n";
import EmptyState from "@/components/common/EmptyState.vue";
import SplitPane from "@/components/common/SplitPane.vue";
import { Badge } from "@/components/ui/badge";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { usePatientFlowLiveSync } from "@/composables/usePatientFlowLiveSync";
import { useShortcuts } from "@/composables/useShortcuts";
import { useToast } from "@/composables/useToast";
import { useWorkspaceUrlSync } from "@/composables/useWorkspaceUrlSync";
import { usePatientStore } from "@/stores/patientStore";
import { useQueueStore } from "@/stores/queueStore";
import AdmissionForm from "@/pages/nursing/components/AdmissionForm.vue";
import AssessmentForm from "@/pages/nursing/components/AssessmentForm.vue";
import MarPanel from "@/pages/nursing/components/MarPanel.vue";
import NursingNotesForm from "@/pages/nursing/components/NursingNotesForm.vue";
import NursingPatientProfileTab from "@/pages/nursing/components/NursingPatientProfileTab.vue";
import PatientHeader from "@/pages/nursing/components/PatientHeader.vue";
import PatientListPanel from "@/pages/nursing/components/PatientListPanel.vue";
import RecentVitalsView from "@/pages/nursing/components/RecentVitalsView.vue";
import ReturnToReceptionDialog from "@/pages/nursing/components/ReturnToReceptionDialog.vue";
import TaskQueuePanel from "@/pages/nursing/components/TaskQueuePanel.vue";
import VitalsForm from "@/pages/nursing/components/VitalsForm.vue";
import { useMar } from "@/pages/nursing/composables/useMar";
import { useNursingAdmission } from "@/pages/nursing/composables/useNursingAdmission";
import { useNursingAssessment } from "@/pages/nursing/composables/useNursingAssessment";
import { useNursingContact } from "@/pages/nursing/composables/useNursingContact";
import { useNursingNotes } from "@/pages/nursing/composables/useNursingNotes";
import { useNursingPatientList } from "@/pages/nursing/composables/useNursingPatientList";
import { useNursingTasks } from "@/pages/nursing/composables/useNursingTasks";
import { useVitals } from "@/pages/nursing/composables/useVitals";
import { usePatientProfile } from "@/pages/reception/composables/usePatientProfile";

const { t } = useI18n();
const toast = useToast();
const patientStore = usePatientStore();
const queueStore = useQueueStore();

// ---- Context pane tabs — persisted per-browser (matching Reception's pattern) ----
const NURSING_ACTIVE_TAB_KEY = "afyanova:nursing:active-tab";
type NursingTab = "patients" | "tasks";
function loadActiveTab(): NursingTab {
  try {
    const stored = localStorage.getItem(NURSING_ACTIVE_TAB_KEY);
    if (stored === "patients" || stored === "tasks") return stored;
  } catch {
    // ignore — falls through to default
  }
  return "patients";
}
const activeTab = ref<NursingTab>(loadActiveTab());
watch(activeTab, (tab) => {
  try {
    localStorage.setItem(NURSING_ACTIVE_TAB_KEY, tab);
  } catch {
    // ignore
  }
});

// ---- Feature composables (Volume 3.8 decomposition, 2026-08-13) ----
const activeWorkbenchTab = ref<"vitals" | "profile">("vitals");

const patientList = useNursingPatientList({
  // On selection/deselection: close any open form, hide the MAR pane, and
  // load the newly selected patient's latest vitals.
  onSelectionChange: (patient) => {
    mainView.value = "none";
    activeWorkbenchTab.value = "vitals";
    mar.showMar.value = false;
    if (patient) vitals.loadLatest(patient.id);
  },
});

const profile = usePatientProfile(computed(() => patientList.selectedPatient.value));

const tasks = useNursingTasks({
  // Opening a task selects its patient with an active-encounter context.
  onOpen: (patientId, encounterId, visit, readiness) => {
    const patient =
      patientList.patients.value.find((p) => p.id === patientId) ??
      patientStore.patients.get(patientId);
    if (!patient) {
      toast.warning(t("nursing.task_patient_not_found"));
      return;
    }
    patientList.selectPatient(patient, encounterId, visit, readiness);
  },
});

const patientId = () => patientList.selectedPatient.value?.id ?? null;
const encounterId = () => patientList.selectedEncounterId.value;

const vitals = useVitals({
  patientId,
  onSaved: () => {
    mainView.value = "none";
    // Recording vitals advances the appointment from waiting_triage →
    // waiting_provider server-side. Refetch tasks so the queue reflects the
    // new status and refresh the visit context in PatientHeader (stage:
    // "In Triage" → "Waiting for Clinician").
    void tasks.refetchTasks();
    const current = patientList.selectedPatient.value;
    if (current) {
      patientList.refreshVisitContext(current.id);
    }
  },
});

const assessment = useNursingAssessment({
  encounterId,
  onSaved: () => {
    mainView.value = "none";
    // Completing the assessment clears this encounter off the Tasks queue
    // server-side — refresh so the Tasks tab reflects it.
    void tasks.refetchTasks();
  },
});

const notes = useNursingNotes({
  encounterId,
  onSaved: () => {
    mainView.value = "none";
  },
});

// Explicit nursing pickup/hand-back (2026-08-16 flow audit, finding 04) — makes
// "a nurse is with this patient right now" a recorded step instead of something
// other staff had to infer or ask about.
const contact = useNursingContact({
  encounterId,
  onChanged: () => {
    void tasks.refetchTasks();
    const current = patientList.selectedPatient.value;
    if (current) {
      patientList.refreshVisitContext(current.id);
    }
  },
});

// Selecting a different patient means this nurse is no longer holding the
// previous one, so the optimistic pickup indicator must not carry over.
watch(() => patientList.selectedEncounterId.value, () => contact.resetContact());

/**
 * Whether this patient is currently picked up, preferring the server-resolved
 * step over local state so the indicator survives a reload and reflects a
 * release made from another session. `contact.hasPatient` remains only as an
 * optimistic overlay for the moment between clicking and the queue refetch
 * landing.
 */
const hasPatientInContact = computed(
  () => patientList.selectedVisit.value?.stage === "with_nurse" || contact.hasPatient.value,
);

// Live board sync (finding 03) — the nursing workspace never subscribed, so a
// doctor starting a consultation or reception checking someone in never
// reached this screen without a manual reload.
usePatientFlowLiveSync({
  onBoardUpdated: () => {
    void tasks.refetchTasks();
  },
});

const admission = useNursingAdmission({
  patientId,
  encounterId,
  visit: () => patientList.selectedVisit.value,
  onSaved: () => {
    mainView.value = "none";
    void tasks.refetchTasks();
    const current = patientList.selectedPatient.value;
    if (current) {
      patientList.refreshVisitContext(current.id);
      void patientStore.fetchPatientSummary(current.id);
    }
  },
});

const mar = useMar({ patientId });

// Sync selected patient and active tab with URL query params (?patient=...&tab=...)
const urlSync = useWorkspaceUrlSync({
  activeTab: activeTab as Ref<string>,
  selectedPatientId: computed(() => patientList.selectedPatient.value?.id),
  onHydrateTab: (tab) => {
    if (tab === "patients" || tab === "tasks") {
      activeTab.value = tab as NursingTab;
    }
  },
  onHydratePatient: async (patientId) => {
    if (!patientId) return;
    const patient =
      patientList.patients.value.find((p) => p.id === patientId) ??
      patientStore.patients.get(patientId) ??
      (await patientStore.fetchPatient(patientId));
    if (patient) {
      patientList.selectPatient(patient);
      return;
    }
    // Linked patient no longer exists (deleted record, stale bookmark). Drop
    // the dead id from the URL so the workspace settles on its empty state
    // rather than retrying it on every reload.
    urlSync.clearPatientSelectionFromUrl();
  },
});

// ---- Main pane: which view is open (Volume 2.3 §7/§6/§10) ----
const mainView = ref<"vitals" | "assessment" | "notes" | "admission" | "none">("none");

/**
 * Starting any hands-on nursing action claims the patient.
 *
 * The claim used to be its own "Start With Patient" button sitting beside
 * "Record Vitals", which left a nurse choosing between two unranked calls to
 * action with no stated relationship — and created the exact failure the claim
 * exists to prevent: record vitals without claiming, and the board still shows
 * the patient waiting while a nurse is stood in front of them.
 *
 * A nurse opening the vitals or assessment form is, definitionally, with the
 * patient. So the step is recorded as a consequence of real work rather than as
 * bookkeeping staff must remember. Ending contact keeps an explicit control,
 * because nothing else marks it (see PatientHeader's release button).
 */
function beginNursingContact() {
  if (!hasPatientInContact.value) {
    void contact.claimPatient({ silent: true });
  }
}

function openVitals() {
  activeWorkbenchTab.value = "vitals";
  mainView.value = "vitals";
  beginNursingContact();
  // Routing targets are loaded lazily, on the one form that offers the choice.
  void vitals.loadDepartmentOptions();
}
function openAssessment() {
  activeWorkbenchTab.value = "vitals";
  mainView.value = "assessment";
  beginNursingContact();
}
function openNotes() {
  activeWorkbenchTab.value = "vitals";
  mainView.value = "notes";
}
function openAdmission() {
  activeWorkbenchTab.value = "vitals";
  mainView.value = "admission";
}

// ---- Return to Reception dialog ----
const showReturnDialog = ref(false);
const isReturningToReception = ref(false);

function openReturnToReception() {
  showReturnDialog.value = true;
}

async function handleConfirmReturn(reason: string) {
  const currentPatientId = patientList.selectedPatient.value?.id;
  const taskMatch = tasks.tasks.value.find((t) => t.patientId === currentPatientId);
  const appointmentId = patientList.selectedVisit.value?.appointmentId ?? taskMatch?.visit?.appointmentId ?? taskMatch?.id ?? null;

  if (!appointmentId) {
    toast.warning(t("nursing.task_patient_not_found"));
    return;
  }

  isReturningToReception.value = true;
  const ok = await queueStore.returnToReception(appointmentId, reason);
  isReturningToReception.value = false;

  if (ok) {
    toast.success(t("nursing.return_success"));
    showReturnDialog.value = false;
    mainView.value = "none";
    patientList.deselectPatient();
    void tasks.refetchTasks();
  } else {
    toast.warning(t("nursing.return_failed"));
  }
}

// ---- Context-pane split ratio (Volume 3.8 Phase 7) ----
const CONTEXT_PANE_RATIO_EMPTY = 0.38;
const CONTEXT_PANE_RATIO_SELECTED = 0.45;
const splitPaneRef = ref<InstanceType<typeof SplitPane> | null>(null);
watch(
  () => patientList.selectedPatient.value?.id,
  (id) => {
    splitPaneRef.value?.applyAutoRatio(id ? CONTEXT_PANE_RATIO_SELECTED : CONTEXT_PANE_RATIO_EMPTY);
  },
);

// ---- Keyboard shortcuts (Volume 2.3 §14, Volume 3.8 Phase 7) ----
const shortcuts = useShortcuts();

function saveCurrentForm() {
  if (mainView.value === "vitals") return void vitals.saveVitals();
  if (mainView.value === "assessment") return void assessment.saveAssessment();
  if (mainView.value === "notes") return void notes.saveNote();
}

onMounted(() => {
  shortcuts.registerShortcuts([
    {
      key: "ctrl+v",
      action: "record-vitals",
      label: t("nursing.record_vitals"),
      scope: "workspace",
      handler: () => {
        if (patientList.selectedEncounterId.value) openVitals();
      },
    },
    {
      key: "ctrl+m",
      action: "mar",
      label: t("nursing.mar"),
      scope: "workspace",
      handler: () => {
        if (patientList.selectedEncounterId.value) mar.toggleMar();
      },
    },
    {
      key: "ctrl+a",
      action: "new-assessment",
      label: t("nursing.new_assessment"),
      scope: "workspace",
      handler: () => {
        if (patientList.selectedEncounterId.value) openAssessment();
      },
    },
    {
      key: "ctrl+s",
      action: "save-form",
      label: t("common.save"),
      scope: "workspace",
      handler: saveCurrentForm,
    },
  ]);
});

onBeforeUnmount(() => {
  shortcuts.unregisterShortcuts(["ctrl+v", "ctrl+m", "ctrl+a", "ctrl+s"]);
});
</script>

<template>
  <SplitPane
    ref="splitPaneRef"
      direction="horizontal"
      :initial-ratio="CONTEXT_PANE_RATIO_EMPTY"
      :min-size="324"
      persist-key="nursing-context-pane"
      class="h-full"
    >
      <template #start>
        <!-- ============================================================
             CONTEXT PANE (Volume 2.3 §4.1)
             ============================================================ -->
        <aside class="flex h-full flex-col rounded-lg border border-border bg-surface overflow-hidden">
          <Tabs v-model="activeTab" class="flex flex-1 flex-col overflow-hidden">
            <div class="border-b border-border bg-surface px-3 pt-1 shrink-0">
              <TabsList class="h-8 gap-1 bg-transparent p-0 justify-start w-auto border-b-0 -mb-px">
                <TabsTrigger
                  value="patients"
                  class="h-8 gap-1.5 rounded-none border-b-2 border-transparent px-2 text-xs font-semibold data-[state=active]:border-primary data-[state=active]:bg-transparent data-[state=active]:text-primary cursor-pointer -mb-px"
                >
                  <Users class="size-3.5" aria-hidden="true" />
                  <span>{{ t("clinician.patients") }}</span>
                  <Badge
                    v-if="patientList.patients.value.length > 0"
                    variant="secondary"
                    class="ml-0.5 px-1.5 py-0 text-[10px] font-mono tabular-nums transition-colors"
                    :class="activeTab === 'patients' ? 'bg-primary/15 text-primary font-semibold' : 'text-muted-foreground'"
                    :aria-label="
                      t('patient.count_sr', {
                        count: patientList.patients.value.length,
                      })
                    "
                  >
                    {{ patientList.patients.value.length }}
                  </Badge>
                </TabsTrigger>
                <TabsTrigger
                  value="tasks"
                  class="h-8 gap-1.5 rounded-none border-b-2 border-transparent px-2 text-xs font-semibold data-[state=active]:border-primary data-[state=active]:bg-transparent data-[state=active]:text-primary cursor-pointer -mb-px"
                >
                  <ClipboardList class="size-3.5" aria-hidden="true" />
                  <span>{{ t("nursing.tasks") }}</span>
                  <span
                    v-if="tasks.tasks.value.length > 0"
                    class="relative flex size-1.5 shrink-0 ml-0.5"
                    aria-hidden="true"
                  >
                    <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-primary opacity-75" />
                    <span class="relative inline-flex size-1.5 rounded-full bg-primary" />
                  </span>
                  <Badge
                    v-if="tasks.tasks.value.length > 0"
                    variant="secondary"
                    class="ml-0.5 px-1.5 py-0 text-[10px] font-mono tabular-nums transition-colors"
                    :class="activeTab === 'tasks' ? 'bg-primary/15 text-primary font-semibold' : 'text-muted-foreground'"
                    :aria-label="
                      t('nursing.task_count_sr', {
                        count: tasks.tasks.value.length,
                      })
                    "
                  >
                    {{ tasks.tasks.value.length }}
                  </Badge>
                </TabsTrigger>
              </TabsList>
            </div>

            <TabsContent value="patients" class="flex flex-1 flex-col overflow-hidden">
              <PatientListPanel :list="patientList" />
            </TabsContent>

            <TabsContent value="tasks" class="flex flex-1 flex-col overflow-hidden">
              <TaskQueuePanel :tasks="tasks" />
            </TabsContent>
          </Tabs>
        </aside>
      </template>

      <template #end>
        <div class="flex h-full gap-4">
          <!-- ============================================================
               MAIN PANE (Volume 2.3 §4.2)
               ============================================================ -->
          <main class="flex flex-1 flex-col overflow-hidden rounded-lg border border-border bg-surface">
            <!-- No patient selected -->
            <div v-if="!patientList.selectedPatient.value" class="flex flex-1 items-center justify-center p-6">
              <EmptyState
                illustration="clipboard"
                :badge="t('nursing.workspace_badge')"
                :title="t('nursing.no_patient_selected_title')"
                :description="t('nursing.no_patient_selected_desc')"
              />
            </div>

            <!-- Patient selected -->
            <template v-else>
              <PatientHeader
                :patient="patientList.selectedPatient.value"
                :encounter-id="patientList.selectedEncounterId.value"
                :allergies="patientList.selectedPatientAllergies.value"
                :is-loading-allergies="patientList.isLoadingAllergies.value"
                :has-encounter="!!patientList.selectedEncounterId.value"
                :visit="patientList.selectedVisit.value"
                :readiness="patientList.selectedReadiness.value"
                :display-name="patientList.patientDisplayName"
                :initials="patientList.patientInitials"
                :has-patient-in-contact="hasPatientInContact"
                :is-updating-contact="contact.isUpdating.value"
                @deselect="patientList.deselectPatient"
                @open-vitals="openVitals"
                @open-assessment="openAssessment"
                @open-notes="openNotes"
                @open-admission="openAdmission"
                @return-to-reception="openReturnToReception"
                @toggle-mar="mar.toggleMar"
                @claim-patient="contact.claimPatient"
                @release-patient="() => contact.releasePatient()"
              />

              <!-- Workbench Sub-Tabs (Clinical Vitals & Acuity vs Demographics & Coverage) -->
              <div class="border-b border-border bg-surface px-3.5 pt-1 flex items-center justify-between shrink-0">
                <Tabs v-model="activeWorkbenchTab" class="w-auto">
                  <TabsList class="h-8 gap-1 bg-transparent p-0 justify-start w-auto border-b-0 -mb-px">
                    <TabsTrigger
                      value="vitals"
                      class="h-8 gap-1.5 rounded-none border-b-2 border-transparent px-2.5 text-xs font-semibold data-[state=active]:border-primary data-[state=active]:bg-transparent data-[state=active]:text-primary cursor-pointer -mb-px"
                      @click="activeWorkbenchTab = 'vitals'"
                    >
                      <Activity class="size-3.5" />
                      <span>Clinical Vitals & Acuity</span>
                    </TabsTrigger>
                    <TabsTrigger
                      value="profile"
                      class="h-8 gap-1.5 rounded-none border-b-2 border-transparent px-2.5 text-xs font-semibold data-[state=active]:border-primary data-[state=active]:bg-transparent data-[state=active]:text-primary cursor-pointer -mb-px"
                      @click="activeWorkbenchTab = 'profile'"
                    >
                      <Contact class="size-3.5" />
                      <span>Patient Demographics & Coverage</span>
                    </TabsTrigger>
                  </TabsList>
                </Tabs>
              </div>

              <!-- View 1: Patient Profile & Demographics Tab -->
              <NursingPatientProfileTab
                v-if="activeWorkbenchTab === 'profile'"
                :patient="patientList.selectedPatient.value"
                :profile="profile"
              />

              <!-- View 2: Clinical Forms & Vitals Workbench -->
              <template v-else>
                <VitalsForm v-if="mainView === 'vitals'" :vitals="vitals" @cancel="mainView = 'none'" />

                <AssessmentForm
                  v-else-if="mainView === 'assessment'"
                  :assessment="assessment"
                  @cancel="mainView = 'none'"
                />

                <NursingNotesForm v-else-if="mainView === 'notes'" :notes="notes" @cancel="mainView = 'none'" />

                <AdmissionForm v-else-if="mainView === 'admission'" :admission="admission" @cancel="mainView = 'none'" />

                <!-- Default: recent vitals -->
                <RecentVitalsView v-else :vitals="vitals" @record-vitals="openVitals" />
              </template>
            </template>

            <ReturnToReceptionDialog
              v-model:open="showReturnDialog"
              :patient-name="patientList.selectedPatient.value ? patientList.patientDisplayName(patientList.selectedPatient.value) : undefined"
              :is-submitting="isReturningToReception"
              :readiness="patientList.selectedReadiness.value"
              @confirm="handleConfirmReturn"
            />
          </main>

          <!-- ============================================================
               DETAIL PANE — MAR (Volume 2.3 §4.3, §8)
               ============================================================ -->
          <MarPanel v-if="mar.showMar.value" :mar="mar" @close="mar.closeMar" />
        </div>
      </template>
    </SplitPane>
</template>
