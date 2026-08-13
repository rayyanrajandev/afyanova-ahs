/** * PatientProfileView — main-pane Patient Profile (Volume 2.1 §8) *
==================================================================== *
Redesigned (Reception workspace visual audit, 2026-08-12) from a grid of *
bordered/muted Cards (Demographics, Allergies, Contact, Insurance, Latest *
visit, Upcoming appointments, Audit trail all separately boxed) into one *
workspace surface: the patient's name is the page's actual heading, * followed
by MRN/age/sex and a live visit-status line, then plain * whitespace-separated
sections instead of card chrome. Demographics as a * standalone card is gone —
its fields (name/MRN/age/sex) now live in the * header, and its Edit action
moved into the header's "More" menu. * * The header's primary action is
state-aware (§3 of the audit): a patient * with an active visit no longer shows
a disabled "Checked In" button (the * 2026-08-12 duplicate-check-in fix) —
instead the primary slot becomes * "View in Queue" (emits `view-in-queue`,
handled by Index.vue switching * the context pane to the Queue tab), and a
patient whose most recent * encounter closed becomes "Start New Visit". Both
still ultimately call * the same `arrivalIntake.openArrivalDialog`/backend flow
as "Check In" — * the same patient record, a new visit — this only changes what
the button * is labeled given what's already true about the patient. * *
Receives the arrival intake and appointment scheduling composable * instances as
props (both are opened from buttons here) rather than * re-deriving them —
Index.vue owns the single shared instances, same * pattern as
ScheduleView/ArrivalIntakeDialog. */

<script setup lang="ts">
import {
  Activity,
  ArrowRight,
  CalendarClock,
  CalendarPlus,
  CircleCheck,
  Contact,
  DoorOpen,
  History,
  LogIn,
  MoreHorizontal,
  Pencil,
  Pin,
  Plus,
  Printer,
  ScrollText,
  ShieldCheck,
  TriangleAlert,
  X,
} from "lucide-vue-next";
import { PopoverClose } from "reka-ui";
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import StatusBadge from "@/components/common/StatusBadge.vue";
import type { StatusType } from "@/components/common/StatusBadge.vue";
import { Avatar, AvatarFallback } from "@/components/ui/avatar";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from "@/components/ui/popover";
import { Separator } from "@/components/ui/separator";
import type { Patient } from "@/stores/patientStore";
import { usePatientStore } from "@/stores/patientStore";
import { useRecentStore } from "@/stores/recentStore";
import type { useAppointmentScheduling } from "../composables/useAppointmentScheduling";
import type { useArrivalIntake } from "../composables/useArrivalIntake";
import type { useInsuranceForm } from "../composables/useInsuranceForm";
import type { usePatientProfile } from "../composables/usePatientProfile";
import {
  formatClinicalDate,
  patientDisplayName,
  patientInitials,
} from "../receptionFormatters";

const props = defineProps<{
  patient: Patient;
  profile: ReturnType<typeof usePatientProfile>;
  arrivalIntake: ReturnType<typeof useArrivalIntake>;
  scheduling: ReturnType<typeof useAppointmentScheduling>;
  insuranceForm: ReturnType<typeof useInsuranceForm>;
  openEditDemographics: () => void;
  printSelectedLabel: () => void;
}>();

const emit = defineEmits<{
  "view-in-queue": [];
}>();

const { t } = useI18n();
const patientStore = usePatientStore();
const recentStore = useRecentStore();

// ---- Empty-section gates (progressive disclosure) ----
const insuranceIsEmpty = computed(
  () =>
    !props.profile.isSummaryLoading.value &&
    !props.profile.profileSummary.value?.insurance,
);
const upcomingAppointmentsIsEmpty = computed(
  () =>
    !props.profile.isSummaryLoading.value &&
    props.profile.upcomingAppointments.value.length === 0,
);
const auditTrailIsEmpty = computed(
  () =>
    !props.profile.isSummaryLoading.value &&
    props.profile.auditFeed.value.length === 0,
);

/**
 * The patient's current unresolved visit, if any — drives both the header
 * status line/primary action and the "Current visit" section below.
 */
const activeAppointment = computed(
  () => props.profile.profileSummary.value?.activeAppointment ?? null,
);

/**
 * True when `latestEncounter` IS the active visit (an open encounter tied
 * to `activeAppointment`), not a past, already-closed one. "Current visit"
 * and "Recent visits" both read from this one encounter field — this flag
 * is what keeps the same visit from being shown twice under two headings.
 */
const latestEncounterIsActive = computed(() => {
  const enc = props.profile.profileSummary.value?.latestEncounter;
  if (!enc || !activeAppointment.value) return false;
  return enc.status !== "closed" && enc.status !== "cancelled";
});

const currentVisitIsEmpty = computed(
  () => !props.profile.isSummaryLoading.value && !activeAppointment.value,
);
const recentVisitIsEmpty = computed(
  () =>
    !props.profile.isSummaryLoading.value &&
    (!props.profile.profileSummary.value?.latestEncounter ||
      latestEncounterIsActive.value),
);

const currentVisitClinicianName = computed(() =>
  latestEncounterIsActive.value
    ? (props.profile.profileSummary.value?.latestEncounter
        ?.primaryClinicianName ?? null)
    : null,
);

const currentVisitStatusType = computed<StatusType>(() =>
  activeAppointment.value?.status === "in_consultation"
    ? "in_progress"
    : "pending",
);

/**
 * Bug fix (Latest/Recent visit status): was a bare `status === 'closed' ?
 * 'complete' : 'in_progress'` — every other EncounterStatus value
 * (App\Modules\Encounter\Domain\ValueObjects\EncounterStatus), including
 * 'cancelled', collapsed into "in_progress". A cancelled visit displayed as
 * permanently in progress even after CancelQueueItemUseCase correctly closed
 * out its encounter — StatusBadge already has a first-class 'cancelled'
 * variant (icon: X), it just was never reachable from here.
 */
function latestVisitStatus(
  encounterStatus: string,
): "complete" | "cancelled" | "in_progress" {
  if (encounterStatus === "closed") return "complete";
  if (encounterStatus === "cancelled") return "cancelled";
  return "in_progress";
}

/**
 * Bug fix (i18n): both insurance status fields were rendering the raw
 * backend enum value (`'active'`, `'unverified'`, …) directly in the
 * template, never passed through `t()`. Falls back to the raw value for any
 * status the translation table doesn't know about, rather than showing
 * nothing.
 */
function insuranceStatusLabel(status: string | null): string {
  if (!status) return "—";
  const key = `insurance.status_${status}`;
  const translated = t(key);
  return translated === key ? status : translated;
}

function insuranceVerificationLabel(status: string | null): string {
  if (!status) return "—";
  const key = `insurance.verification_${status}`;
  const translated = t(key);
  return translated === key ? status : translated;
}

function togglePin() {
  if (recentStore.isPinned(props.patient.id)) {
    recentStore.unpin(props.patient.id);
  } else {
    recentStore.pin(props.patient.id);
  }
}

// ---- Header identity + status (Reception audit §2) ----
type VisitState =
  | "not_checked_in"
  | "waiting"
  | "in_consultation"
  | "completed";

const visitState = computed<VisitState>(() => {
  const appt = activeAppointment.value;
  if (appt)
    return appt.status === "in_consultation" ? "in_consultation" : "waiting";
  if (props.profile.profileSummary.value?.latestEncounter?.status === "closed")
    return "completed";
  return "not_checked_in";
});

// Color rules (§10): gray = inactive, amber = waiting/attention, teal/blue
// (primary) = active state, green = positive/complete.
const visitStateDotClass: Record<VisitState, string> = {
  not_checked_in: "bg-muted-foreground/50",
  waiting: "bg-warning",
  in_consultation: "bg-primary",
  completed: "bg-success",
};

const visitStateLabelKey: Record<VisitState, string> = {
  not_checked_in: "patient.not_checked_in",
  waiting: "patient.checked_in_waiting",
  in_consultation: "patient.in_consultation",
  completed: "patient.visit_completed",
};

/**
 * Duplicate check-in fix (§3): a patient with an active visit no longer
 * shows a disabled "Check In" — the primary slot becomes a real action
 * ("View in Queue") instead of a dead button. "Start New Visit" and
 * "Check In" both open the same arrival dialog for the same patient
 * record — the backend already reuses the patient and only creates a new
 * visit (RegisterWalkInAndCheckInUseCase); this only changes the label to
 * match what's already true about the patient's history.
 */
const primaryAction = computed(() => {
  switch (visitState.value) {
    case "waiting":
    case "in_consultation":
      return {
        label: t("arrival.view_in_queue"),
        icon: ArrowRight,
        handler: () => emit("view-in-queue"),
      };
    case "completed":
      return {
        label: t("arrival.start_new_visit"),
        icon: DoorOpen,
        handler: props.arrivalIntake.openArrivalDialog,
      };
    case "not_checked_in":
    default:
      return {
        label: t("arrival.check_in"),
        icon: DoorOpen,
        handler: props.arrivalIntake.openArrivalDialog,
      };
  }
});
</script>

<template>
  <!-- @container wraps the whole view (not just the info-grid below) so
       the header can share the exact same breakpoint sections use — see
       header docblock note just below for why. -->
  <div class="@container">
    <!-- Header: identity, status, actions. Deliberate stack-below-
       @profile-header via flex-col/flex-row (not the accidental
       flex-wrap this used to be): the previous version let the browser
       wrap the whole actions cluster under the identity block only once
       combined content stopped fitting — which meant it looked fine in
       English (short button labels) and dropped to two rows in Kiswahili
       (longer labels: "Ona kwenye Foleni", "Panga Miadi") at the exact
       same pane width — a locale-dependent layout difference a
       receptionist would notice switching languages (2026-08-12, direct
       user feedback). `@profile-header` (tailwind.css, 800px) is
       calibrated, not guessed — the default scale's nearest steps
       (`@3xl` 768px / `@4xl` 896px) either still squeezed Kiswahili or
       left the row visibly stacked well past the point it actually had
       room to go inline, which is exactly what prompted a second look
       (same feedback pass). Below `@profile-header` both locales
       consistently stack (identity block, then actions block, each safe
       to wrap internally on its own) — same layout at the same width
       regardless of language, which was the actual bug, not "must
       always be one row". -->
    <div
      class="flex flex-col gap-y-4 @profile-header:flex-row @profile-header:items-start @profile-header:justify-between @profile-header:gap-x-6"
    >
      <div class="flex min-w-0 items-start gap-4">
        <Avatar class="size-12 shrink-0">
          <AvatarFallback class="text-base font-semibold">
            {{ patientInitials(patientDisplayName(patient)) }}
          </AvatarFallback>
        </Avatar>
        <div class="min-w-0">
          <h1 class="truncate text-2xl font-semibold text-foreground">
            {{ patientDisplayName(patient) }}
          </h1>
          <p class="mt-1 text-sm text-muted-foreground">
            {{ t("patient.mrn") }}
            <span class="clinical-value">{{
              patient.identifier[0]?.value
            }}</span>
            <span aria-hidden="true"> · </span>
            {{ t("patient.age_display", { age: patient.meta.extension.age }) }}
            <span aria-hidden="true"> · </span>
            {{ profile.genderLabel(patient.gender) }}
          </p>
          <p
            class="mt-2 flex items-center gap-2 text-sm font-medium text-foreground"
          >
            <span
              class="h-2 w-2 shrink-0 rounded-full"
              :class="visitStateDotClass[visitState]"
              aria-hidden="true"
            />
            {{ t(visitStateLabelKey[visitState]) }}
          </p>
        </div>
      </div>

      <!-- Action hierarchy (§8): primary (state-aware) → secondary
         (Schedule) → More (Print Label, Edit demographics, Pin, Close).
         Print Label and Close both moved off their own top-level slots
         into More (2026-08-12, direct user feedback) — fewer always-
         visible buttons, and less width competing against Kiswahili's
         longer labels in the header row. Close stays set apart from the
         patient-data actions by its own divider inside the menu, the
         same "dismiss lives below a separator" pattern common account/
         profile menus use for sign-out — Close isn't a patient action
         like the others, it dismisses the panel itself. -->
      <div class="flex flex-wrap items-center gap-2">
        <Button
          size="sm"
          class="inline-flex items-center gap-1.5"
          @click="primaryAction.handler"
        >
          <component
            :is="primaryAction.icon"
            class="h-3.5 w-3.5"
            aria-hidden="true"
          />
          {{ primaryAction.label }}
        </Button>
        <Button
          variant="outline"
          size="sm"
          class="inline-flex items-center gap-1.5"
          @click="scheduling.openScheduleDialogForPatient(patient)"
        >
          <CalendarPlus class="h-3.5 w-3.5" aria-hidden="true" />
          {{ t("appointment.schedule_button") }}
        </Button>

        <Popover>
          <PopoverTrigger as-child>
            <Button
              variant="ghost"
              size="sm"
              class="inline-flex items-center gap-1"
            >
              <MoreHorizontal class="h-3.5 w-3.5" aria-hidden="true" />
              {{ t("common.more") }}
            </Button>
          </PopoverTrigger>
          <PopoverContent class="w-56 p-1" align="end">
            <PopoverClose as-child>
              <button
                type="button"
                class="focus-ring flex w-full items-center gap-2 rounded-sm px-2 py-1.5 text-left text-sm text-foreground transition-colors hover:bg-muted"
                @click="printSelectedLabel"
              >
                <Printer
                  class="h-3.5 w-3.5 text-muted-foreground"
                  aria-hidden="true"
                />
                {{ t("registration.print_label") }}
              </button>
            </PopoverClose>
            <PopoverClose as-child>
              <button
                type="button"
                class="focus-ring flex w-full items-center gap-2 rounded-sm px-2 py-1.5 text-left text-sm text-foreground transition-colors hover:bg-muted"
                @click="openEditDemographics"
              >
                <Pencil
                  class="h-3.5 w-3.5 text-muted-foreground"
                  aria-hidden="true"
                />
                {{ t("common.edit") }} {{ t("patient.demographics") }}
              </button>
            </PopoverClose>
            <PopoverClose as-child>
              <button
                type="button"
                class="focus-ring flex w-full items-center gap-2 rounded-sm px-2 py-1.5 text-left text-sm text-foreground transition-colors hover:bg-muted"
                @click="togglePin"
              >
                <Pin
                  class="h-3.5 w-3.5"
                  :class="
                    recentStore.isPinned(patient.id)
                      ? 'text-primary'
                      : 'text-muted-foreground'
                  "
                  :fill="
                    recentStore.isPinned(patient.id) ? 'currentColor' : 'none'
                  "
                  aria-hidden="true"
                />
                {{
                  recentStore.isPinned(patient.id)
                    ? t("patient.unpin")
                    : t("patient.pin")
                }}
              </button>
            </PopoverClose>

            <div class="my-1 border-t border-border" />

            <PopoverClose as-child>
              <button
                type="button"
                class="focus-ring flex w-full items-center gap-2 rounded-sm px-2 py-1.5 text-left text-sm text-foreground transition-colors hover:bg-muted"
                @click="patientStore.clearCurrentPatient()"
              >
                <X class="h-3.5 w-3.5 text-muted-foreground" aria-hidden="true" />
                {{ t("common.close") }}
              </button>
            </PopoverClose>
          </PopoverContent>
        </Popover>
      </div>
    </div>

    <Separator class="mt-5" />

    <!-- Patient information (§7): plain sections on one surface, no per-
       section card chrome. Each row pairs two related sections side by
       side with a vertical Separator between them (only at @lg — a
       stacked single column has no adjacent row to divide), and each row
       is itself set off from the next by an explicit horizontal Separator
       sibling — the same divider used everywhere else in this view, just
       rotated, rather than a second one-off way of drawing a line.
       (Tailwind's `divide-y` was tried first here and produced a 0px
       border-top in this build — root cause not chased down since a real
       element sidesteps it entirely and is already proven working for the
       header/audit-trail dividers.) Container-queried so this reflows to
       one column once the *pane* (SplitPane-resizable, not the window)
       gets narrow. -->
    <div class="mt-6">
      <div class="flex flex-col gap-y-8">
        <!-- Row: Contact / Current visit -->
        <div
          class="flex flex-col gap-y-7 @lg:flex-row @lg:items-stretch @lg:gap-x-8"
        >
          <section class="@lg:flex-1">
            <h2
              class="mb-2 flex items-center gap-1.5 text-xs font-semibold tracking-wide text-muted-foreground uppercase"
            >
              <Contact class="h-3.5 w-3.5" aria-hidden="true" />
              {{ t("patient.contact") }}
            </h2>
            <div
              v-if="profile.isSummaryLoading.value"
              class="space-y-2.5"
              role="status"
              :aria-label="t('common.loading')"
            >
              <div class="flex justify-between gap-4" aria-hidden="true">
                <div class="h-3 w-12 animate-pulse rounded bg-muted" />
                <div class="h-3 w-32 animate-pulse rounded bg-muted" />
              </div>
              <div class="flex justify-between gap-4" aria-hidden="true">
                <div class="h-3 w-12 animate-pulse rounded bg-muted" />
                <div class="h-3 w-40 animate-pulse rounded bg-muted" />
              </div>
              <div class="flex justify-between gap-4" aria-hidden="true">
                <div class="h-3 w-14 animate-pulse rounded bg-muted" />
                <div class="h-3 w-28 animate-pulse rounded bg-muted" />
              </div>
            </div>
            <dl v-else class="space-y-1.5 text-sm">
              <div class="flex justify-between gap-4">
                <dt class="text-muted-foreground">{{ t("patient.phone") }}</dt>
                <dd class="font-medium text-foreground">
                  {{
                    patient.telecom.find((t2) => t2.system === "phone")
                      ?.value ?? "—"
                  }}
                </dd>
              </div>
              <div class="flex justify-between gap-4">
                <dt class="text-muted-foreground">{{ t("patient.email") }}</dt>
                <dd class="font-medium text-foreground">
                  {{ profile.profileSummary.value?.contact.email ?? "—" }}
                </dd>
              </div>
              <div class="flex justify-between gap-4">
                <dt class="shrink-0 text-muted-foreground">
                  {{ t("patient.address") }}
                </dt>
                <dd
                  class="min-w-0 flex-1 text-right font-medium text-foreground"
                >
                  {{ profile.contactAddress.value ?? "—" }}
                </dd>
              </div>
              <div
                v-if="profile.profileSummary.value?.contact.nextOfKinName"
                class="flex justify-between gap-4"
              >
                <dt class="text-muted-foreground">
                  {{ t("patient.next_of_kin") }}
                </dt>
                <dd class="font-medium text-foreground">
                  {{ profile.profileSummary.value.contact.nextOfKinName }}
                  <span
                    v-if="profile.profileSummary.value.contact.nextOfKinPhone"
                    class="block text-xs text-muted-foreground"
                    >{{
                      profile.profileSummary.value.contact.nextOfKinPhone
                    }}</span
                  >
                </dd>
              </div>
            </dl>
          </section>

          <Separator
            orientation="vertical"
            class="hidden self-stretch @lg:block data-[orientation=vertical]:h-auto"
          />

          <section class="@lg:flex-1">
            <h2
              class="mb-2 flex items-center gap-1.5 text-xs font-semibold tracking-wide text-muted-foreground uppercase"
            >
              <Activity class="h-3.5 w-3.5" aria-hidden="true" />
              {{ t("patient.current_visit") }}
            </h2>
            <div
              v-if="profile.isSummaryLoading.value"
              class="flex items-center justify-between gap-3"
              role="status"
              :aria-label="t('common.loading')"
            >
              <div class="space-y-1.5" aria-hidden="true">
                <div class="h-3 w-24 animate-pulse rounded bg-muted" />
                <div class="h-2.5 w-32 animate-pulse rounded bg-muted" />
              </div>
              <div class="h-5 w-16 shrink-0 animate-pulse rounded-full bg-muted" aria-hidden="true" />
            </div>
            <p
              v-else-if="currentVisitIsEmpty"
              class="text-sm text-muted-foreground/70"
            >
              {{ t("patient.no_active_visit") }}
            </p>
            <div v-else class="flex items-center justify-between gap-3 text-sm">
              <div class="min-w-0">
                <p class="font-medium text-foreground">
                  {{ activeAppointment!.department ?? "—" }}
                </p>
                <p class="text-xs text-muted-foreground">
                  {{ formatClinicalDate(activeAppointment!.scheduledAt) }}
                  <template v-if="currentVisitClinicianName">
                    — {{ currentVisitClinicianName }}</template
                  >
                </p>
              </div>
              <StatusBadge :status="currentVisitStatusType" class="shrink-0" />
            </div>
          </section>
        </div>

        <Separator />

        <!-- Row: Allergies / Upcoming appointments -->
        <div
          class="flex flex-col gap-y-7 @lg:flex-row @lg:items-stretch @lg:gap-x-8"
        >
          <section class="@lg:flex-1">
            <h2
              class="mb-2 flex items-center gap-1.5 text-xs font-semibold tracking-wide text-muted-foreground uppercase"
            >
              <TriangleAlert class="h-3.5 w-3.5" aria-hidden="true" />
              {{ t("patient.allergies") }}
            </h2>
            <div
              v-if="profile.isSummaryLoading.value"
              role="status"
              :aria-label="t('common.loading')"
            >
              <div class="h-5 w-32 animate-pulse rounded-full bg-muted" aria-hidden="true" />
            </div>
            <div
              v-else-if="(profile.profileSummary.value?.alerts.length ?? 0) > 0"
            >
              <Badge
                v-for="allergy in profile.profileSummary.value?.alerts"
                :key="allergy.id"
                :variant="
                  allergy.severity === 'severe' ? 'critical' : 'warning'
                "
                class="mr-2 mb-2 inline-flex items-center gap-1"
              >
                <TriangleAlert class="h-3 w-3" aria-hidden="true" />
                {{ allergy.substanceName }}
              </Badge>
            </div>
            <div v-else>
              <Badge variant="success" class="inline-flex items-center gap-1">
                <CircleCheck class="h-3 w-3" aria-hidden="true" />
                {{ t("patient.no_allergies") }}
              </Badge>
            </div>
          </section>

          <Separator
            orientation="vertical"
            class="hidden self-stretch @lg:block data-[orientation=vertical]:h-auto"
          />

          <section class="@lg:flex-1">
            <h2
              class="mb-2 flex items-center gap-1.5 text-xs font-semibold tracking-wide text-muted-foreground uppercase"
            >
              <CalendarClock class="h-3.5 w-3.5" aria-hidden="true" />
              {{ t("patient.upcoming_appointments") }}
            </h2>
            <div
              v-if="profile.isSummaryLoading.value"
              class="space-y-2.5"
              role="status"
              :aria-label="t('common.loading')"
            >
              <div class="flex items-center justify-between gap-2" aria-hidden="true">
                <div class="space-y-1.5">
                  <div class="h-3 w-28 animate-pulse rounded bg-muted" />
                  <div class="h-2.5 w-20 animate-pulse rounded bg-muted" />
                </div>
                <div class="h-6 w-16 shrink-0 animate-pulse rounded bg-muted" />
              </div>
            </div>
            <p
              v-else-if="upcomingAppointmentsIsEmpty"
              class="text-sm text-muted-foreground/70"
            >
              {{ t("patient.no_upcoming_appointments") }}
            </p>
            <ul v-else class="space-y-2 text-sm">
              <li
                v-for="appt in profile.upcomingAppointments.value"
                :key="appt.id"
                class="flex items-center justify-between gap-2"
              >
                <span class="min-w-0">
                  <span class="block font-medium text-foreground">{{
                    formatClinicalDate(appt.scheduledAt)
                  }}</span>
                  <span class="block truncate text-xs text-muted-foreground">{{
                    appt.department ?? appt.reason ?? "—"
                  }}</span>
                </span>
                <Button
                  variant="ghost"
                  size="sm"
                  class="h-6 shrink-0 gap-1 px-2 text-xs"
                  :aria-label="t('arrival.checkin_appointment')"
                  @click="arrivalIntake.checkInAppointment(appt.id)"
                >
                  <LogIn class="h-3 w-3" aria-hidden="true" />
                  {{ t("arrival.check_in") }}
                </Button>
              </li>
            </ul>
          </section>
        </div>

        <Separator />

        <!-- Row: Insurance / Recent visits -->
        <div
          class="flex flex-col gap-y-7 @lg:flex-row @lg:items-stretch @lg:gap-x-8"
        >
          <section class="@lg:flex-1">
            <div class="mb-2 flex items-center justify-between gap-2">
              <h2
                class="flex items-center gap-1.5 text-xs font-semibold tracking-wide text-muted-foreground uppercase"
              >
                <ShieldCheck class="h-3.5 w-3.5" aria-hidden="true" />
                {{ t("patient.insurance") }}
              </h2>
              <button
                v-if="!profile.isSummaryLoading.value && !insuranceIsEmpty"
                type="button"
                class="focus-ring inline-flex shrink-0 items-center gap-1 rounded-sm text-xs font-medium text-primary hover:underline"
                @click="
                  insuranceForm.openInsuranceForm(
                    patient.id,
                    profile.profileSummary.value?.insurance,
                  )
                "
              >
                <Pencil class="h-3 w-3" aria-hidden="true" />
                {{ t("common.edit") }}
              </button>
            </div>
            <div
              v-if="profile.isSummaryLoading.value"
              class="space-y-2.5"
              role="status"
              :aria-label="t('common.loading')"
            >
              <div class="flex justify-between gap-4" aria-hidden="true">
                <div class="h-3 w-14 animate-pulse rounded bg-muted" />
                <div class="h-3 w-24 animate-pulse rounded bg-muted" />
              </div>
              <div class="flex justify-between gap-4" aria-hidden="true">
                <div class="h-3 w-20 animate-pulse rounded bg-muted" />
                <div class="h-3 w-28 animate-pulse rounded bg-muted" />
              </div>
              <div class="flex justify-between gap-4" aria-hidden="true">
                <div class="h-3 w-12 animate-pulse rounded bg-muted" />
                <div class="h-3 w-16 animate-pulse rounded bg-muted" />
              </div>
              <div class="flex justify-between gap-4" aria-hidden="true">
                <div class="h-3 w-16 animate-pulse rounded bg-muted" />
                <div class="h-3 w-20 animate-pulse rounded bg-muted" />
              </div>
            </div>
            <p
              v-else-if="insuranceIsEmpty"
              class="text-sm text-muted-foreground/70"
            >
              {{ t("patient.no_insurance") }}
              <button
                type="button"
                class="focus-ring ml-1 inline-flex items-center gap-1 rounded-sm font-medium text-primary hover:underline"
                @click="
                  insuranceForm.openInsuranceForm(
                    patient.id,
                    profile.profileSummary.value?.insurance,
                  )
                "
              >
                <Plus class="h-3 w-3" aria-hidden="true" />
                {{ t("insurance.add_title") }}
              </button>
            </p>
            <dl v-else class="space-y-1.5 text-sm">
              <div class="flex justify-between">
                <dt class="text-muted-foreground">
                  {{ t("patient.insurance_provider") }}
                </dt>
                <dd class="font-medium text-foreground">
                  {{
                    profile.profileSummary.value!.insurance!
                      .insuranceProvider ?? "—"
                  }}
                </dd>
              </div>
              <div class="flex justify-between">
                <dt class="text-muted-foreground">
                  {{ t("patient.insurance_member_id") }}
                </dt>
                <dd class="clinical-value font-medium text-foreground">
                  {{ profile.profileSummary.value!.insurance!.memberId ?? "—" }}
                </dd>
              </div>
              <div class="flex justify-between">
                <dt class="text-muted-foreground">
                  {{ t("patient.insurance_status") }}
                </dt>
                <dd class="font-medium text-foreground">
                  {{
                    insuranceStatusLabel(
                      profile.profileSummary.value!.insurance!.status,
                    )
                  }}
                </dd>
              </div>
              <div class="flex items-center justify-between">
                <dt class="text-muted-foreground">
                  {{ t("insurance.verification_status") }}
                </dt>
                <dd
                  class="flex items-center gap-1.5 font-medium text-foreground"
                >
                  {{
                    insuranceVerificationLabel(
                      profile.profileSummary.value!.insurance!
                        .verificationStatus,
                    )
                  }}
                  <Button
                    v-if="
                      profile.profileSummary.value!.insurance!
                        .verificationStatus !== 'verified' &&
                      profile.profileSummary.value!.insurance!.id
                    "
                    variant="ghost"
                    size="sm"
                    class="h-6 gap-1 px-1.5 text-xs text-muted-foreground hover:text-foreground"
                    @click="
                      insuranceForm.verifyInsurance(
                        patient.id,
                        profile.profileSummary.value!.insurance!.id!,
                      )
                    "
                  >
                    <CircleCheck class="h-3 w-3" aria-hidden="true" />
                    {{ t("insurance.verify") }}
                  </Button>
                </dd>
              </div>
            </dl>
          </section>

          <Separator
            orientation="vertical"
            class="hidden self-stretch @lg:block data-[orientation=vertical]:h-auto"
          />

          <!-- Recent visits (reception's `patients.read` permission doesn't
             include `medical.records.read`, required by GET /encounters, so
             a full "last 5" Timeline isn't safely buildable without a
             permission/product decision — this shows the one closed
             encounter the summary endpoint already includes). -->
          <section class="@lg:flex-1">
            <h2
              class="mb-2 flex items-center gap-1.5 text-xs font-semibold tracking-wide text-muted-foreground uppercase"
            >
              <History class="h-3.5 w-3.5" aria-hidden="true" />
              {{ t("patient.recent_visits") }}
            </h2>
            <div
              v-if="profile.isSummaryLoading.value"
              class="flex items-center justify-between gap-3"
              role="status"
              :aria-label="t('common.loading')"
            >
              <div class="space-y-1.5" aria-hidden="true">
                <div class="h-3 w-24 animate-pulse rounded bg-muted" />
                <div class="h-2.5 w-32 animate-pulse rounded bg-muted" />
              </div>
              <div class="h-5 w-16 shrink-0 animate-pulse rounded-full bg-muted" aria-hidden="true" />
            </div>
            <p
              v-else-if="recentVisitIsEmpty"
              class="text-sm text-muted-foreground/70"
            >
              {{ t("patient.no_visits") }}
            </p>
            <div v-else class="flex items-center justify-between text-sm">
              <div>
                <p class="font-medium text-foreground">
                  {{
                    formatClinicalDate(
                      profile.profileSummary.value!.latestEncounter!.openedAt,
                    )
                  }}
                </p>
                <p
                  v-if="
                    profile.profileSummary.value!.latestEncounter!
                      .primaryClinicianName
                  "
                  class="text-xs text-muted-foreground"
                >
                  {{
                    profile.profileSummary.value!.latestEncounter!
                      .primaryClinicianName
                  }}
                </p>
              </div>
              <StatusBadge
                v-if="profile.profileSummary.value!.latestEncounter!.status"
                :status="
                  latestVisitStatus(
                    profile.profileSummary.value!.latestEncounter!.status,
                  )
                "
              />
            </div>
          </section>
        </div>
      </div>
    </div>

    <!-- Audit trail (§7): kept, but visually secondary — smaller text, set
       off by its own divider, at the bottom of the workspace rather than
       competing with the clinical/administrative sections above it. -->
    <Separator class="mt-9" />
    <div class="pt-4">
      <h2
        class="mb-2 flex items-center gap-1.5 text-xs font-semibold tracking-wide text-muted-foreground/70 uppercase"
      >
        <ScrollText class="h-3.5 w-3.5" aria-hidden="true" />
        {{ t("patient.audit_trail") }}
      </h2>
      <div
        v-if="profile.isSummaryLoading.value"
        class="space-y-2"
        role="status"
        :aria-label="t('common.loading')"
      >
        <div
          v-for="n in 3"
          :key="n"
          class="flex items-center justify-between"
          aria-hidden="true"
        >
          <div class="h-2.5 w-40 animate-pulse rounded bg-muted" />
          <div class="h-2.5 w-16 animate-pulse rounded bg-muted" />
        </div>
      </div>
      <p v-else-if="auditTrailIsEmpty" class="text-xs text-muted-foreground/70">
        {{ t("patient.no_audit_activity") }}
      </p>
      <ul v-else class="space-y-1.5 text-xs text-muted-foreground">
        <li
          v-for="entry in profile.auditFeed.value"
          :key="entry.id"
          class="flex items-center justify-between"
        >
          <span>
            {{ profile.auditActionLabel(entry) }}
            <span v-if="entry.actor?.name">— {{ entry.actor.name }}</span>
          </span>
          <span>{{ formatClinicalDate(entry.occurredAt) }}</span>
        </li>
      </ul>
    </div>
  </div>
</template>
