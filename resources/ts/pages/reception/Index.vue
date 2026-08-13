/** * Reception Workspace (Volume 2.1) * ================================= * The
pilot workspace: patient registration, search, profile, queue. * Uses the
split-2 layout (context + main, resizable via SplitPane — Volume 1.1 §4.2),
* the AppShell, the patientStore, * and shadcn-vue primitives styled with
Afyanova tokens (Volume 1.2 §4.1). */

<script setup lang="ts">
import { UserSearch } from "lucide-vue-next";
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import SplitPane from "@/components/common/SplitPane.vue";
import AppShell from "@/components/shell/AppShell.vue";
import { Button } from "@/components/ui/button";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
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
import { printPatientLabel } from "@/pages/reception/patientLabel";
import { patientInitials } from "@/pages/reception/receptionFormatters";
import { usePatientStore } from "@/stores/patientStore";
import { useRecentStore } from "@/stores/recentStore";

const { t } = useI18n();
const toast = useToast();
const patientStore = usePatientStore();
const recentStore = useRecentStore();
const commandPalette = useCommandPalette();

// ---- Context pane tabs ----
const activeTab = ref<"patients" | "queue" | "schedule">("patients");

// ---- Patient search + recent patients (Volume 2.1 §7.2, Volume 1.3
// §6.3/§9.1, Volume 1.2 §6). Extracted to composables/usePatientSearch.ts
// (2026-08-10, component-library audit) — pure extraction, no behavior
// change.
const patientSearch = usePatientSearch();

// ---- Selected patient ----
const selectedPatient = computed(() => patientStore.currentPatient);

// ---- Patient profile (Volume 2.1 §8) ----
// Extracted to composables/usePatientProfile.ts (2026-08-10, component-
// library audit) — pure extraction, no behavior change.
const patientProfile = usePatientProfile(selectedPatient);

// ---- Patient registration + duplicate-check (Volume 2.1 §6, §6.2/§7.3,
// Volume 3.7 T2.4/T7.4). Extracted to composables/usePatientRegistration.ts
// (2026-08-10, component-library audit) — pure extraction, no behavior
// change.
const registration = usePatientRegistration();

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
});

// `aria-live` counterpart to the sync above (§10.4, T5.7) — see
// useQueueLiveAnnouncer.ts's own docblock for why it watches the store
// directly instead of hooking into onBoardUpdated specifically.
const queueLiveAnnouncer = useQueueLiveAnnouncer();

</script>

<template>
  <AppShell>
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
      direction="horizontal"
      :initial-ratio="0.38"
      :min-size="324"
      persist-key="reception-context-pane"
      class="h-full"
    >
      <template #start>
        <!-- ============================================================
                   CONTEXT PANE (Volume 2.1 §4.1)
                   ============================================================ -->
        <aside class="flex h-full flex-col rounded-lg border border-border bg-surface">
          <!-- Tabs (shadcn-vue, Volume 1.2 §4.1). Underline style
               (2026-08-11, direct user feedback + design research — see
               TabsList.vue/TabsTrigger.vue docblocks for the NN/g +
               uxpatterns.dev sourcing): the segmented-pill look this
               replaced needed constant reception-only padding/margin
               overrides all session just to keep 3 equal-width-stretched
               tabs from truncating in a narrow pane (history below, kept
               for the overflow-bug lesson, which is still real even
               though the pill fitting-fight itself is gone) — natural-
               width tabs (TabsTrigger's new default) don't compete for a
               forced-equal share of the row, so that fight doesn't
               recur: this section carries zero trigger-level overrides
               now, first tried with none at all and confirmed live
               rather than assumed. -->
          <Tabs v-model="activeTab" class="flex flex-1 flex-col">
            <!-- Spacing moved from TabsList's own margin to padding on
                 this wrapper (2026-08-11 — root-caused a horizontal
                 overflow bug, live-confirmed with getBoundingClientRect,
                 not just visual guessing). The old `<TabsList class="m-2
                 mb-0 w-full">` overflowed: a percentage width is computed
                 against the containing block, and margin sits *outside*
                 that box, not subtracted from it — so 100% + 8px left +
                 8px right margin genuinely rendered 16px wider than the
                 pane. Padding is safe here because Tailwind's preflight
                 sets `box-sizing: border-box` — padding is included
                 inside a percentage width, margin never is. -->
            <div class="p-2 pb-0">
              <!-- `w-full` on the list (not the pill-era `w-fit` default)
                   so the underline baseline runs the full pane width —
                   common pattern in real underline-tab implementations
                   (GitHub, Linear): the row of natural-width, left-
                   clustered tabs sits on top of a border that continues
                   past the last tab, not one that stops abruptly where
                   "Appointments" ends. -->
              <TabsList class="w-full">
                <!-- "Patients" (clinician.patients), not "Search patients" —
                     tab labels are conventionally terse, and clinician's/
                     nursing's own patients tab already uses this exact
                     key, so this also makes reception consistent with
                     both instead of uniquely saying something longer for
                     the same concept (2026-08-11, tabs-overflow fix). -->
                <!-- Count badges (2026-08-11 UX pass): all 3 tabs now show
                     a compact pill instead of "(N)" plain text (Queue's old
                     format) or no count at all (Patients/Appointments' old
                     state) — a real badge reads as a count at a glance
                     instead of parsing as part of the label string.
                     `bg-primary`/`text-primary-foreground` (not `bg-muted`,
                     tried first and reported hard to see in both themes:
                     that token pair is also what TabsList's own inactive-
                     state background used to be, so an inactive tab's
                     badge blended into the surface behind it). Solid
                     primary (same shape/weight as AppShell's notification-
                     count badge, Volume 1.1 §8.2) reads clearly against
                     both trigger states in light and dark, live-compared
                     against a tinted-primary and a neutral candidate
                     before choosing this one. No whitespace between the
                     label interpolation and `<span` (deliberate, not a
                     formatting slip): Vue's template compiler condenses
                     adjacent whitespace into one real space character in
                     the rendered text node, which was stacking with the
                     badge's own `ml-0.5` margin. -->
                <TabsTrigger value="patients">{{
                  t("clinician.patients")
                }}<span
                    class="ml-1 inline-flex h-3.5 min-w-3.5 items-center justify-center rounded-lg bg-secondary px-1 text-xs leading-none font-medium tabular-nums text-secondary-foreground"
                    >{{ patientSearch.totalPatients.value }}</span
                  ></TabsTrigger>
                <TabsTrigger value="queue">{{
                  t("queue.label")
                }}<span
                    class="ml-1 inline-flex h-3.5 min-w-3.5 items-center justify-center rounded-full bg-secondary px-1 text-xs leading-none font-medium tabular-nums text-secondary-foreground"
                    >{{ queueActions.queue.value.length }}</span
                  ></TabsTrigger>
                <TabsTrigger value="schedule">{{
                  t("appointment.schedule_tab")
                }}<span
                    class="ml-1 inline-flex h-3.5 min-w-3.5 items-center justify-center rounded-full bg-secondary px-1 text-xs leading-none font-medium tabular-nums text-secondary-foreground"
                    >{{ scheduling.scheduleAppointments.value.length }}</span
                  ></TabsTrigger>
              </TabsList>
            </div>

            <!-- Patients tab. Extracted to PatientSearchPanel.vue (2026-08-10,
                 component-library audit). -->
            <TabsContent value="patients" class="flex flex-col overflow-hidden">
              <PatientSearchPanel :search="patientSearch" :open-registration="registration.openRegistration" />
            </TabsContent>

            <!-- Queue tab (Volume 1.2 §9 — Queue composite). Extracted to
                 QueuePanel.vue (2026-08-10, component-library audit). -->
            <TabsContent value="queue" class="flex flex-col overflow-hidden">
              <QueuePanel :queue-actions="queueActions" />
            </TabsContent>

            <!-- Schedule tab (Volume 2.1 §9) — booking-ahead, not the primary
                 entry path (§9 intro), so a compact list + dialog rather than
                 a full calendar grid. Extracted to ScheduleView.vue
                 (2026-08-10, component-library audit). -->
            <TabsContent value="schedule" class="flex flex-col overflow-hidden">
              <ScheduleView :scheduling="scheduling" />
            </TabsContent>
          </Tabs>
        </aside>
      </template>

      <template #end>
        <!-- ============================================================
                   MAIN PANE (Volume 2.1 §4.2)
                   ============================================================ -->
        <main class="flex h-full min-h-0 flex-col overflow-y-auto rounded-lg border border-border bg-surface p-3">
          <!-- `min-h-0 overflow-y-auto` (2026-08-12, layout audit — the
               registration form's new grouped sections + Emergency
               Contact box made this the first content tall enough to
               expose it): this card previously relied on `overflow:
               visible` (the default) with a fixed `h-full`, which was
               harmless while every consumer's content happened to fit —
               once it didn't, content kept rendering past the card's own
               border/background instead of scrolling inside it, breaking
               the card's visual containment even though the resizable
               pane's own ancestor already scrolls the page around it.
               `min-h-0` is the standard flex-item fix alongside
               `overflow-y-auto` — without it a flex child's default
               `min-height: auto` stops it from ever shrinking to trigger
               its own scrollbar. -->
          <!-- Registration form. Extracted to RegistrationForm.vue
               (2026-08-10, component-library audit). -->
          <RegistrationForm v-if="registration.showRegistration.value" :registration="registration" />

          <!-- Edit demographics (Volume 2.1 §8.3). Extracted to
               EditDemographicsForm.vue (2026-08-10, component-library audit). -->
          <EditDemographicsForm
            v-else-if="editDemographics.isEditingDemographics.value && selectedPatient"
            :edit="editDemographics"
          />

          <!-- Patient profile (Volume 2.1 §8). Extracted to
               PatientProfileView.vue (2026-08-10, component-library audit). -->
          <div v-else-if="selectedPatient">
            <PatientProfileView
              :patient="selectedPatient"
              :profile="patientProfile"
              :arrival-intake="arrivalIntake"
              :scheduling="scheduling"
              :insurance-form="insuranceForm"
              :open-edit-demographics="editDemographics.openEditDemographics"
              :print-selected-label="printSelectedLabel"
              @view-in-queue="activeTab = 'queue'"
            />
          </div>

          <!-- Empty state (Volume 1.2 §14) -->
          <div
            v-else
            class="flex flex-1 flex-col items-center justify-center text-center"
          >
            <UserSearch
              class="mb-4 h-10 w-10 text-muted-foreground"
              aria-hidden="true"
            />
            <h2 class="mb-2 text-lg font-semibold text-foreground">
              {{ t("patient.search") }}
            </h2>
            <p class="mb-4 text-sm text-muted-foreground">
              {{ t("patient.empty_hint") }}
            </p>
            <Button @click="registration.openRegistration">
              {{ t("patient.register") }}
            </Button>
          </div>
        </main>
      </template>
    </SplitPane>

    <!-- Duplicate-check dialog (Volume 2.1 §6.2 / §7.3, Volume 1.2 §10 —
         Volume 3.7 T2.4). Extracted to DuplicatePatientDialog.vue
         (2026-08-10, component-library audit). -->
    <DuplicatePatientDialog :registration="registration" />

    <!-- Cancel-appointment dialog (Volume 2.1 §10.3). Extracted to
         CancelQueueItemDialog.vue (2026-08-10, component-library audit). -->
    <CancelQueueItemDialog :queue-actions="queueActions" />

    <!-- Arrival intake dialog: Walk-in / Emergency (Volume 2.1 §10.1).
         Extracted to ArrivalIntakeDialog.vue (2026-08-10, component-library
         audit). -->
    <ArrivalIntakeDialog :arrival="arrivalIntake" :patient="selectedPatient" />

    <!-- Schedule appointment dialog: create (Volume 2.1 §9.2/§9.3).
         Extracted to ScheduleAppointmentDialog.vue (2026-08-10,
         component-library audit). -->
    <ScheduleAppointmentDialog :scheduling="scheduling" />

    <!-- Insurance add/edit dialog (Volume 2.1 §8.1, Volume 3.7 §16 #10). -->
    <InsuranceFormDialog :insurance-form="insuranceForm" />
  </AppShell>
</template>
