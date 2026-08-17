/** * Reception Workspace (Volume 2.1) * ================================= * The
pilot workspace: patient registration, search, profile, queue. * Uses the
split-2 layout (context + main, resizable via SplitPane — Volume 1.1 §4.2),
* the AppShell, the patientStore, * and shadcn-vue primitives styled with
Afyanova tokens (Volume 1.2 §4.1). */

<script setup lang="ts">
import { Calendar, Clock, Search, Users, UserSearch } from "lucide-vue-next";
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch, type Ref } from "vue";
import { useI18n } from "vue-i18n";
import EmptyState from "@/components/common/EmptyState.vue";
import SplitPane from "@/components/common/SplitPane.vue";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { TooltipProvider } from "@/components/ui/tooltip";
import { useCommandPalette } from "@/composables/useCommandPalette";
import { useShortcuts } from "@/composables/useShortcuts";
import { useToast } from "@/composables/useToast";
import ArrivalIntakeDialog from "@/pages/reception/components/ArrivalIntakeDialog.vue";
import CancelQueueItemDialog from "@/pages/reception/components/CancelQueueItemDialog.vue";
import DuplicatePatientDialog from "@/pages/reception/components/DuplicatePatientDialog.vue";
import EditDemographicsForm from "@/pages/reception/components/EditDemographicsForm.vue";
import InsuranceFormDialog from "@/pages/reception/components/InsuranceFormDialog.vue";
import PatientProfileView from "@/pages/reception/components/PatientProfileView.vue";
import PatientSearchPanel from "@/pages/reception/components/PatientSearchPanel.vue";
import QueuePanel from "@/pages/reception/components/QueuePanel.vue";
import RegistrationForm from "@/pages/reception/components/RegistrationForm.vue";
import ScheduleAppointmentDialog from "@/pages/reception/components/ScheduleAppointmentDialog.vue";
import ScheduleView from "@/pages/reception/components/ScheduleView.vue";
import { useAppointmentScheduling } from "@/pages/reception/composables/useAppointmentScheduling";
import { useArrivalIntake } from "@/pages/reception/composables/useArrivalIntake";
import { useEditDemographics } from "@/pages/reception/composables/useEditDemographics";
import { useInsuranceForm } from "@/pages/reception/composables/useInsuranceForm";
import { usePatientProfile } from "@/pages/reception/composables/usePatientProfile";
import { usePatientRegistration } from "@/pages/reception/composables/usePatientRegistration";
import { usePatientSearch } from "@/pages/reception/composables/usePatientSearch";
import { useQueueActions } from "@/pages/reception/composables/useQueueActions";
import { useQueueLiveAnnouncer } from "@/pages/reception/composables/useQueueLiveAnnouncer";
import { useReceptionLiveSync } from "@/pages/reception/composables/useReceptionLiveSync";
import ReturnedPatientAlertDialog, {
  type ReturnedPatientInfo,
} from "@/pages/reception/components/ReturnedPatientAlertDialog.vue";
import { printPatientLabel } from "@/pages/reception/patientLabel";
import { patientInitials } from "@/pages/reception/receptionFormatters";
import { useWorkspaceUrlSync } from "@/composables/useWorkspaceUrlSync";
import { usePatientStore } from "@/stores/patientStore";
import { useRecentStore } from "@/stores/recentStore";

const { t } = useI18n();
const toast = useToast();
const patientStore = usePatientStore();
const recentStore = useRecentStore();
const commandPalette = useCommandPalette();

const showReturnedModal = ref(false);
const returnedPatientInfo = ref<ReturnedPatientInfo | null>(null);

function handleAcknowledgeReturned(info: ReturnedPatientInfo) {
  if (info.patientId) {
    patientStore.setCurrentPatient(info.patientId);
  }
  activeTab.value = "queue";
  void queueActions.refetchQueue();
}

// ---- Context pane tabs (Volume 1.4 §12.1, Volume 3.7 T7.4) — persisted
// per-browser like SplitPane's ratio and DataTable's sort/filters/columns
// already are (same `afyanova:` localStorage namespace, same try/catch
// guard), not routed through the shared `uiStore` — this is reception-
// workspace-local state, not shell-level state the way theme/density/nav
// are.
const RECEPTION_ACTIVE_TAB_KEY = "afyanova:reception:active-tab";
type ReceptionTab = "patients" | "queue" | "schedule";
function loadActiveTab(): ReceptionTab {
  try {
    const stored = localStorage.getItem(RECEPTION_ACTIVE_TAB_KEY);
    if (stored === "patients" || stored === "queue" || stored === "schedule") return stored;
  } catch {
    // ignore — falls through to the default below
  }
  return "patients";
}
const activeTab = ref<ReceptionTab>(loadActiveTab());
watch(activeTab, (tab) => {
  try {
    localStorage.setItem(RECEPTION_ACTIVE_TAB_KEY, tab);
  } catch {
    // ignore
  }
});

// Sync selected patient and active tab with URL query params (?patient=...&tab=...)
const urlSync = useWorkspaceUrlSync({
  activeTab: activeTab as Ref<string>,
  selectedPatientId: computed(() => patientStore.currentPatientId),
  onHydrateTab: (tab) => {
    if (tab === "patients" || tab === "queue" || tab === "schedule") {
      activeTab.value = tab as ReceptionTab;
    }
  },
  onHydratePatient: async (patientId) => {
    if (!patientId) return;
    const patient =
      patientStore.patients.get(patientId) ??
      (await patientStore.fetchPatient(patientId));
    if (patient) {
      patientStore.cachePatient(patient);
      patientStore.setCurrentPatient(patient.id);
      recentStore.addRecent(patient);
      return;
    }
    // Linked patient no longer exists (deleted record, stale bookmark). Forget
    // it and drop the dead id from the URL so the workspace settles on its
    // empty state rather than retrying it on every reload.
    recentStore.removeRecent(patientId);
    urlSync.clearPatientSelectionFromUrl();
  },
});

// ---- Patient search + recent patients (Volume 2.1 §7.2, Volume 1.3
// §6.3/§9.1, Volume 1.2 §6). Extracted to composables/usePatientSearch.ts
// (2026-08-10, component-library audit) — pure extraction, no behavior
// change.
const patientSearch = usePatientSearch();

// ---- Selected patient ----
const selectedPatient = computed(() => patientStore.currentPatient);

// ---- Context-pane split ratio (Volume 1.1 §4.2 follow-up, reception UI
// audit) — SplitPane's default 38/62 favors the detail pane even with
// nothing selected (today's empty state has little to show). Once a
// patient IS selected the detail pane has real content competing for room
// against the list, so this nudges the ratio toward a more even split —
// and back to the empty-state ratio on deselect, so the split isn't a
// one-way ratchet. `applyAutoRatio` is a no-op once the user has resized
// by hand (this session or a persisted one) — see its own docblock in
// SplitPane.vue — so this only shapes the out-of-the-box experience and
// never fights a deliberate manual width. Watches the id (not the whole
// object) so switching between two already-selected patients re-applies
// it too, while re-renders of the same patient don't.
const CONTEXT_PANE_RATIO_EMPTY = 0.38;
const CONTEXT_PANE_RATIO_SELECTED = 0.45;
const splitPaneRef = ref<InstanceType<typeof SplitPane> | null>(null);
watch(
  () => selectedPatient.value?.id,
  (id) => {
    splitPaneRef.value?.applyAutoRatio(
      id ? CONTEXT_PANE_RATIO_SELECTED : CONTEXT_PANE_RATIO_EMPTY,
    );
  },
);

// ---- Patient profile (Volume 2.1 §8) ----
// Extracted to composables/usePatientProfile.ts (2026-08-10, component-
// library audit) — pure extraction, no behavior change.
const patientProfile = usePatientProfile(selectedPatient);

// ---- Patient registration + duplicate-check (Volume 2.1 §6, §6.2/§7.3,
// Volume 3.7 T2.4/T7.4). Extracted to composables/usePatientRegistration.ts
// (2026-08-10, component-library audit) — pure extraction, no behavior
// change.
const registration = usePatientRegistration({
  onRegistered: (_patientId, andCheckedIn) => {
    if (andCheckedIn) {
      void queueActions.refetchQueue();
      activeTab.value = "queue";
    }
  },
});

// ---- Edit demographics (Volume 2.1 §8.3, Volume 3.7 audit 2026-08-10) ----
// Extracted to composables/useEditDemographics.ts (2026-08-10, component-
// library audit) — pure extraction, no behavior change. onSaved refreshes
// the audit feed so the "Patient Profile Updated" entry the PATCH just
// created shows up without requiring a reselect.
const editDemographics = useEditDemographics(selectedPatient, (patientId) => {
  void patientProfile.fetchPatientActivityFeed(patientId);
});

// ---- Insurance add/edit/verify (Volume 2.1 §8.1, Volume 3.7 §16 #10) ----
// onSaved refreshes the summary card so a just-added/edited/verified
// record shows up without a reselect — same pattern as editDemographics
// above and onCheckedIn/onCancelled elsewhere in this file.
const insuranceForm = useInsuranceForm({
  onSaved: (patientId) => {
    void patientProfile.refreshSummary(patientId);
  },
});

// ---- Arrival intake (Volume 2.1 §10.1) ----
// Extracted to composables/useArrivalIntake.ts (2026-08-10, component-
// library audit). onCheckedIn refreshes the currently selected patient's
// Upcoming appointments card after a Scheduled check-in (mirrors what the
// old inline version did directly). Extended (2026-08-11 bug fix) to also
// refresh Latest visit + Audit trail — check-in opens a new encounter and
// now writes a "Patient Checked In" audit entry (CheckInUseCase); neither
// showed up on an already-open profile before this. Also refreshes the
// Appointments tab unconditionally (not gated on selectedPatient — a
// Scheduled check-in moves ITS OWN appointment out of that list's
// status=scheduled filter regardless of which patient is currently open in
// the main pane): `scheduling` is declared further down this file, but
// this callback only runs later, on an actual check-in, by which point
// it's initialized — same closure-over-a-later-const pattern already
// relied on for queueActions.onCancelled below.
const arrivalIntake = useArrivalIntake({
  onCheckedIn: (patientId) => {
    if (selectedPatient.value?.id === patientId) {
      void patientProfile.fetchUpcomingAppointments(patientId);
      void patientProfile.refreshSummary(patientId);
      void patientProfile.fetchPatientActivityFeed(patientId);
    }
    scheduling.refreshScheduleIfLoaded();
    void queueActions.refetchQueue();
    // Jump to Queue after check-in (2026-08-12, direct user feedback: had
    // to switch tabs manually to see the patient land there). Fires for
    // both check-in paths — submitArrival (walk-in/emergency) and
    // checkInAppointment (an existing scheduled appointment) — since both
    // already funnel through this one onCheckedIn callback.
    activeTab.value = "queue";
  },
});

// ---- Appointment Scheduling (Volume 2.1 §9) ----
// Extracted to composables/useAppointmentScheduling.ts (2026-08-10,
// component-library audit) — was ~380 lines of schedule-tab + create-dialog
// state/logic inline here. onAppointmentBooked refreshes the currently
// selected patient's Upcoming appointments card if the booking was for them
// (mirrors what the old inline version did directly).
const scheduling = useAppointmentScheduling({
  activeTab,
  onAppointmentBooked: (patientId) => {
    if (selectedPatient.value?.id === patientId) {
      void patientProfile.fetchUpcomingAppointments(patientId);
    }
  },
});

// ---- Print label (Volume 2.1 §5.2 W5 / §6.3 step 4, Volume 3.7 T2.7) ----
function printSelectedLabel() {
  if (selectedPatient.value) printPatientLabel(selectedPatient.value);
}

// T2.7 + T8.1d: Ctrl+P prints the label for the patient in context.
const shortcuts = useShortcuts();

// ---- Command palette recent/pinned commands (Volume 1.3 §9.1 / Volume 1.1 §6.2) ----
let recentCommandIds: string[] = [];

function syncRecentCommands() {
  commandPalette.unregisterCommands(recentCommandIds);
  recentCommandIds = recentStore.items.map(
    (item) => `recent-patient-${item.id}`,
  );
  commandPalette.registerCommands(
    recentStore.items.map((item) => ({
      id: `recent-patient-${item.id}`,
      label: item.name,
      icon: patientInitials(item.name).charAt(0),
      keywords: [item.mrn, item.name],
      type: "patient",
      action: () => {
        patientStore.setCurrentPatient(item.id);
        recentStore.addRecentEntry({
          id: item.id,
          name: item.name,
          mrn: item.mrn,
        });
      },
    })),
  );
}

watch(() => recentStore.items, syncRecentCommands);

// ---- Keyboard shortcuts (Volume 2.1 §14, Volume 1.6 §5.1) ----
// Ctrl+P (print-label) was already registered; adding the remaining three.
function focusPatientSearch() {
  // The search field only renders on the Patients tab — switch to it first.
  activeTab.value = "patients";
  void nextTick(() => {
    document.getElementById("reception-patient-search")?.focus();
  });
}

function saveCurrentForm() {
  // Ctrl+S "save current form" (§14): only meaningful while the
  // registration form is open. Clicks the real submit button rather than
  // reaching into VeeForm internals — identical to a user pressing Save,
  // so validation/duplicate-check/etc. all run exactly as normal.
  if (!registration.showRegistration.value) return;
  document.getElementById("reception-registration-save")?.click();
}

onMounted(() => {
  shortcuts.registerShortcuts([
    {
      key: "ctrl+p",
      action: "print-label",
      label: t("registration.print_label"),
      scope: "workspace",
      handler: printSelectedLabel,
    },
    {
      key: "ctrl+n",
      action: "new-patient",
      label: t("patient.register"),
      scope: "workspace",
      handler: registration.openRegistration,
    },
    {
      key: "ctrl+f",
      action: "focus-search",
      label: t("patient.search"),
      scope: "workspace",
      handler: focusPatientSearch,
    },
    {
      key: "ctrl+s",
      action: "save-form",
      label: t("common.save"),
      scope: "workspace",
      handler: saveCurrentForm,
    },
  ]);
  syncRecentCommands();
});

onBeforeUnmount(() => {
  shortcuts.unregisterShortcuts(["ctrl+p", "ctrl+n", "ctrl+f", "ctrl+s"]);
  commandPalette.unregisterCommands(recentCommandIds);
});

// ---- Reception queue + queue actions (Volume 2.1 §10) ----
// Extracted to composables/useQueueActions.ts (2026-08-10, component-
// library audit) — pure extraction, no behavior change. onCancelled added
// (2026-08-11 bug fix): cancelling previously refreshed nothing on an
// already-open profile — Latest visit kept showing the visit as "In
// progress" and Audit trail never showed the cancellation, even though
// CancelQueueItemUseCase (backend) was correctly recording both.
const queueActions = useQueueActions({
  onCancelled: (patientId) => {
    if (selectedPatient.value?.id === patientId) {
      void patientProfile.refreshSummary(patientId);
      void patientProfile.fetchPatientActivityFeed(patientId);
    }
  },
});

// ---- Real-time queue/appointments sync (Volume 2.1 §10.4) ----
// onCheckedIn/onCancelled above already refresh everything a check-in/
// cancel touches *when the action happens in this tab*. This closes the
// remaining gap: the same board event fires for a check-in/cancel/order
// completion from ANY session — another receptionist, or another
// workspace entirely — which this tab previously never learned about
// without a manual reopen or full reload. Declared last, after
// queueActions/scheduling/patientProfile all exist, since its callback
// closes over all three.
useReceptionLiveSync({
  onBoardUpdated: () => {
    if (selectedPatient.value) {
      void patientProfile.fetchUpcomingAppointments(selectedPatient.value.id);
      void patientProfile.refreshSummary(selectedPatient.value.id);
      void patientProfile.fetchPatientActivityFeed(selectedPatient.value.id);
    }
    void queueActions.refetchQueue();
    scheduling.refreshScheduleIfLoaded();
  },
  // Call (§10.3, §16 #3) — the broadcast IS the message (no refetch to do);
  // fires in every open session at this facility, including the one that
  // triggered it (see useReceptionLiveSync.ts's own docblock for why
  // that's deliberate, not a missed dedupe).
  onPatientCalled: (payload) => {
    toast.warning(t("queue.now_calling", { name: payload.patientName }));
  },
  onPatientReturned: (payload) => {
    returnedPatientInfo.value = {
      appointmentId: payload.appointmentId,
      patientId: payload.patientId,
      patientName: payload.patientName,
      reason: payload.reason,
    };
    showReturnedModal.value = true;
  },
});

// `aria-live` counterpart to the sync above (§10.4, T5.7) — see
// useQueueLiveAnnouncer.ts's own docblock for why it watches the store
// directly instead of hooking into onBoardUpdated specifically.
const queueLiveAnnouncer = useQueueLiveAnnouncer();

</script>

<template>
  <TooltipProvider :delay-duration="200">
    <!-- Live-region announcer (§10.4, T5.7) — visually hidden, always
          mounted regardless of which context-pane tab is active, so a new
          arrival is announced to a screen-reader user even while they're
          looking at Patients or Appointments, not only while Queue happens
          to be open. `role="status"`/`aria-live="polite"` (not "assertive"):
          a new patient in the queue is informational, not urgent enough to
          interrupt whatever the receptionist is doing right now. -->
    <div role="status" aria-live="polite" class="sr-only">
      {{ queueLiveAnnouncer.announcement.value }}
    </div>

    <!-- Context pane + main pane, resizable (Volume 1.1 §4.2 SplitPane —
         previously a hard-coded w-80 aside; every workspace's docblock
         claimed "split-2 layout" but none actually used the real,
         already-built SplitPane component. Found and fixed 2026-08-10 after
         T5.1's live testing measured the fixed 320px pane running out of
         room for the queue's new tier labels. `persist-key` remembers each
         user's chosen width; `initial-ratio` matches the old fixed 320px on
         a typical viewport so this reads as "same as before, but now
         resizable," not a sudden layout change.

         `initial-ratio` corrected 0.31 -> 0.38, `min-size` raised
         280 -> 324 (2026-08-11, tab count-badge UX pass, bumped again
         same day when the tabs moved to the underline redesign below):
         0.31 was already undershooting its own "320px on a typical
         viewport" target at 1024px specifically — live-measured at
         262px, not 320px. SplitPane already supports a per-consumer
         `minSize` prop for exactly this (its own docblock names
         280/320/360 as compact/comfortable/spacious floors), this isn't
         a new pattern. 324 (not 320) leaves a couple of px of genuine
         slack past the true worst case live-measured for the underline
         redesign below — realistic 2-digit counts (40/12/99) on all 3
         tabs simultaneously, at the true drag-to-floor minimum, both
         locales — rather than sitting exactly on the boundary. -->
    <SplitPane
      ref="splitPaneRef"
      direction="horizontal"
      :initial-ratio="CONTEXT_PANE_RATIO_EMPTY"
      :min-size="324"
      persist-key="reception-context-pane"
      class="h-full"
    >
      <template #start>
        <!-- ============================================================
                   CONTEXT PANE (Volume 2.1 §4.1)
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
                    v-if="patientSearch.totalPatients.value > 0"
                    variant="secondary"
                    class="ml-0.5 px-1.5 py-0 text-[10px] font-mono tabular-nums transition-colors"
                    :class="activeTab === 'patients' ? 'bg-primary/15 text-primary font-semibold' : 'text-muted-foreground'"
                    :aria-label="
                      t('patient.count_sr', {
                        count: patientSearch.totalPatients.value,
                      })
                    "
                  >
                    {{ patientSearch.totalPatients.value }}
                  </Badge>
                </TabsTrigger>
                <TabsTrigger
                  value="queue"
                  class="h-8 gap-1.5 rounded-none border-b-2 border-transparent px-2 text-xs font-semibold data-[state=active]:border-primary data-[state=active]:bg-transparent data-[state=active]:text-primary cursor-pointer -mb-px"
                >
                  <Clock class="size-3.5" aria-hidden="true" />
                  <span>{{ t("queue.label") }}</span>
                  <span
                    v-if="(queueActions.stageCounts.value?.total ?? queueActions.queue.value?.length ?? 0) > 0"
                    class="relative flex size-1.5 shrink-0 ml-0.5"
                    aria-hidden="true"
                  >
                    <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-primary opacity-75" />
                    <span class="relative inline-flex size-1.5 rounded-full bg-primary" />
                  </span>
                  <Badge
                    v-if="(queueActions.stageCounts.value?.total ?? queueActions.queue.value?.length ?? 0) > 0"
                    variant="secondary"
                    class="ml-0.5 px-1.5 py-0 text-[10px] font-mono tabular-nums transition-colors"
                    :class="activeTab === 'queue' ? 'bg-primary/15 text-primary font-semibold' : 'text-muted-foreground'"
                    :aria-label="
                      t('queue.waiting_count_sr', {
                        count: queueActions.stageCounts.value?.total ?? queueActions.queue.value?.length ?? 0,
                      })
                    "
                  >
                    {{ queueActions.stageCounts.value?.total ?? queueActions.queue.value?.length ?? 0 }}
                  </Badge>
                </TabsTrigger>
                <TabsTrigger
                  value="schedule"
                  class="h-8 gap-1.5 rounded-none border-b-2 border-transparent px-2 text-xs font-semibold data-[state=active]:border-primary data-[state=active]:bg-transparent data-[state=active]:text-primary cursor-pointer -mb-px"
                >
                  <Calendar class="size-3.5" aria-hidden="true" />
                  <span>{{ t("appointment.schedule_tab") }}</span>
                  <Badge
                    v-if="scheduling.scheduleAppointments.value.length > 0"
                    variant="secondary"
                    class="ml-0.5 px-1.5 py-0 text-[10px] font-mono tabular-nums transition-colors"
                    :class="activeTab === 'schedule' ? 'bg-primary/15 text-primary font-semibold' : 'text-muted-foreground'"
                    :aria-label="
                      t('appointment.count_sr', {
                        count: scheduling.scheduleAppointments.value.length,
                      })
                    "
                  >
                    {{ scheduling.scheduleAppointments.value.length }}
                  </Badge>
                </TabsTrigger>
              </TabsList>
            </div>

            <!-- Patients tab. Extracted to PatientSearchPanel.vue (2026-08-10,
                 component-library audit). -->
            <TabsContent value="patients" class="flex flex-1 flex-col overflow-hidden">
              <PatientSearchPanel :search="patientSearch" :open-registration="registration.openRegistration" />
            </TabsContent>

            <!-- Queue tab (Volume 1.2 §9 — Queue composite). Extracted to
                 QueuePanel.vue (2026-08-10, component-library audit). -->
            <TabsContent value="queue" class="flex flex-1 flex-col overflow-hidden">
              <QueuePanel :queue-actions="queueActions" />
            </TabsContent>

            <!-- Schedule tab (Volume 2.1 §9) — booking-ahead, not the primary
                 entry path (§9 intro), so a compact list + dialog rather than
                 a full calendar grid. Extracted to ScheduleView.vue
                 (2026-08-10, component-library audit). -->
            <TabsContent value="schedule" class="flex flex-1 flex-col overflow-hidden">
              <ScheduleView :scheduling="scheduling" :arrival-intake="arrivalIntake" />
            </TabsContent>
          </Tabs>
        </aside>
      </template>

      <template #end>
        <div class="flex h-full gap-4">
          <!-- ============================================================
                     MAIN PANE (Volume 2.1 §4.2)
                     ============================================================ -->
          <main class="flex flex-1 flex-col overflow-hidden rounded-lg border border-border bg-surface">
            <!-- Registration form. Extracted to RegistrationForm.vue
                 (2026-08-10, component-library audit). -->
            <div v-if="registration.showRegistration.value" class="flex flex-1 flex-col overflow-hidden">
              <RegistrationForm :registration="registration" />
            </div>

            <!-- Edit demographics (Volume 2.1 §8.3). Extracted to
                 EditDemographicsForm.vue (2026-08-10, component-library audit). -->
            <div
              v-else-if="editDemographics.isEditingDemographics.value && selectedPatient"
              class="flex-1 overflow-y-auto p-3"
            >
              <EditDemographicsForm :edit="editDemographics" />
            </div>

            <!-- Patient profile (Volume 2.1 §8). Extracted to
                 PatientProfileView.vue (2026-08-10, component-library audit). -->
            <PatientProfileView
              v-else-if="selectedPatient"
              :patient="selectedPatient"
              :profile="patientProfile"
              :arrival-intake="arrivalIntake"
              :scheduling="scheduling"
              :insurance-form="insuranceForm"
              :open-edit-demographics="editDemographics.openEditDemographics"
              :print-selected-label="printSelectedLabel"
              @view-in-queue="activeTab = 'queue'"
              @register-new="registration.openRegistration"
            />

            <!-- Empty state (Volume 1.2 §14) -->
            <div
              v-else
              class="flex flex-1 items-center justify-center p-6"
            >
              <EmptyState
                illustration="users"
                :badge="t('patient.workspace_badge')"
                :title="t('patient.reception_empty_title')"
                :description="t('patient.reception_empty_desc')"
                :action-label="t('patient.register')"
                :secondary-action-label="t('patient.search')"
                @action="registration.openRegistration"
                @secondary-action="focusPatientSearch"
              />
            </div>
          </main>
        </div>
      </template>
    </SplitPane>
    </TooltipProvider>

    <!-- Duplicate-check dialog (Volume 2.1 §6.2 / §7.3, Volume 1.2 §10 —
         Volume 3.7 T2.4). Extracted to DuplicatePatientDialog.vue
         (2026-08-10, component-library audit). -->
    <DuplicatePatientDialog :registration="registration" />

    <!-- Cancel-appointment dialog (Volume 2.1 §10.3). Extracted to
         CancelQueueItemDialog.vue (2026-08-10, component-library audit). -->
    <CancelQueueItemDialog :queue-actions="queueActions" />

    <ArrivalIntakeDialog
      :arrival="arrivalIntake"
      :patient="selectedPatient"
      :insurance="patientProfile.profileSummary.value?.insurance"
      :insurance-form="insuranceForm"
    />

    <!-- Schedule appointment dialog: create (Volume 2.1 §9.2/§9.3).
         Extracted to ScheduleAppointmentDialog.vue (2026-08-10,
         component-library audit). -->
    <ScheduleAppointmentDialog :scheduling="scheduling" />

    <!-- Insurance add/edit dialog (Volume 2.1 §8.1, Volume 3.7 §16 #10). -->
    <InsuranceFormDialog :insurance-form="insuranceForm" />

    <!-- Returned patient alert modal dialog -->
    <ReturnedPatientAlertDialog
      v-model:open="showReturnedModal"
      :patient-info="returnedPatientInfo"
      @acknowledge="handleAcknowledgeReturned"
    />
</template>
