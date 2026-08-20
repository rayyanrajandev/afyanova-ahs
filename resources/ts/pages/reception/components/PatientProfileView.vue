/**
 * PatientProfileView — Reception Main-Pane Patient Profile (Volume 2.1 §8)
 * ====================================================================
 * Upgraded to 2027 Enterprise Clinical Standard:
 * - Pinned context header with high-contrast safety flags & status
 * - Tabbed workspace sections (Overview & Active Visit, Demographics & Coverage, Appointments & History, Audit Trail)
 * - Verified insurance card with member ID & 1-click verify trigger
 * - Quick triage routing & state-aware actions
 */

<script setup lang="ts">
import {
  Activity,
  ArrowRight,
  CalendarClock,
  CalendarPlus,
  CheckCircle2,
  CircleCheck,
  Clock,
  Contact,
  DoorOpen,
  History,
  LogIn,
  Mail,
  MapPin,
  MoreHorizontal,
  Pencil,
  Phone,
  Pin,
  Plus,
  Printer,
  ScrollText,
  ShieldAlert,
  ShieldCheck,
  TriangleAlert,
  User,
  UserPlus,
  Users,
  X,
} from "lucide-vue-next";
import { PopoverClose } from "reka-ui";
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import StatusBadge from "@/components/common/StatusBadge.vue";
import type { StatusType } from "@/components/common/StatusBadge.vue";
import { Avatar, AvatarFallback } from "@/components/ui/avatar";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from "@/components/ui/popover";
import { Separator } from "@/components/ui/separator";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { stepBadgeStatus, stepLabelKey } from "@/composables/patientFlowStep";
import VisitNotesDialog from "@/pages/nursing/components/VisitNotesDialog.vue";
import type { Patient } from "@/stores/patientStore";
import { usePatientStore } from "@/stores/patientStore";
import { useQueueStore } from "@/stores/queueStore";
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
  "register-new": [];
}>();

const { t } = useI18n();
const activeProfileTab = ref<"overview" | "demographics" | "appointments" | "audit">("overview");
const showNotesDialog = ref(false);
const currentVisitNotes = ref<string>("");

function onNotesUpdated(notes: string) {
  currentVisitNotes.value = notes;
}
const patientStore = usePatientStore();
const recentStore = useRecentStore();
const queueStore = useQueueStore();

// ---- Empty-section gates (progressive disclosure) ----
const insuranceIsEmpty = computed(
  () =>
    !props.profile.isSummaryLoading.value &&
    !props.profile.profileSummary.value?.insurance,
);

const insuranceNeedsVerification = computed(
  () =>
    !props.profile.isSummaryLoading.value &&
    Boolean(props.profile.profileSummary.value?.insurance) &&
    props.profile.profileSummary.value?.insurance?.verificationStatus !==
      "verified",
);

const insuranceAttentionLabel = computed(() =>
  insuranceVerificationLabel(
    props.profile.profileSummary.value?.insurance?.verificationStatus ||
      "unverified",
  ),
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

const activeAppointment = computed(
  () => props.profile.profileSummary.value?.activeAppointment ?? null,
);

const latestEncounterIsActive = computed(() => {
  const enc = props.profile.profileSummary.value?.latestEncounter;
  if (!enc) return false;
  return enc.status !== "closed" && enc.status !== "cancelled";
});

const currentVisitIsEmpty = computed(
  () => !props.profile.isSummaryLoading.value && !activeAppointment.value && !latestEncounterIsActive.value,
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

/**
 * Driven by the server-resolved flow step, through the same shared mapping the
 * reception and clinician queues use. These two derived the badge from
 * `activeAppointment.status` alone, which cannot express a nursing pickup — so
 * this pane showed "Waiting for Triage" for a patient the queue immediately
 * beside it correctly showed as "With Nurse" (2026-08-16).
 */
const currentVisitStatusType = computed<StatusType>(() => {
  const fromStep = stepBadgeStatus(activeAppointment.value?.visitStage);
  if (fromStep) return fromStep;

  const status = activeAppointment.value?.status;
  if (status === "waiting_provider") return "success";
  if (status === "in_consultation") return "info";
  return "warning";
});

const currentVisitStatusLabel = computed<string>(() => {
  const stepKey = stepLabelKey(activeAppointment.value?.visitStage);
  if (stepKey) return t(stepKey);

  const status = activeAppointment.value?.status;
  if (status === "waiting_provider") return t("patient.stage_waiting_provider");
  if (status === "in_consultation") return t("patient.stage_in_consultation");
  return t("patient.stage_waiting_triage");
});

function latestVisitStatus(
  encounterStatus: string,
): "complete" | "cancelled" | "in_progress" {
  if (encounterStatus === "closed") return "complete";
  if (encounterStatus === "cancelled") return "cancelled";
  return "in_progress";
}

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
  | "waiting_triage"
  | "waiting_provider"
  | "in_consultation"
  | "admitted"
  | "completed";

const visitState = computed<VisitState>(() => {
  // 1. Check if patient has an active admission or inpatient encounter
  const summary = props.profile.profileSummary.value;
  if (
    summary?.currentAdmission?.status === "admitted" ||
    (summary?.latestEncounter?.type === "inpatient" && summary?.latestEncounter?.status === "opened")
  ) {
    return "admitted";
  }

  const appt = activeAppointment.value;
  if (appt) {
    // `visitState` drives the dot colour and the coarse header label. It stays a
    // coarse enum, so a nursing pickup maps onto the queue the patient is still
    // in — the precise step is what currentVisitStatusLabel renders.
    if (appt.visitStage === "with_clinician" || appt.status === "in_consultation") return "in_consultation";
    if (appt.visitStage === "with_nurse" || appt.visitStage === "in_triage") return "waiting_triage";
    if (appt.status === "waiting_provider") return "waiting_provider";
    return "waiting_triage";
  }

  // Cross-reference real-time queue store (handles immediate post-checkin reactive sync)
  const queueEntry = (queueStore.tasks ?? []).find(
    (e) => e.patientId === props.patient.id,
  );
  if (queueEntry) {
    const stage = (queueEntry.stage || queueEntry.visit?.stage || queueEntry.status || "").toLowerCase();
    if (
      queueEntry.visit?.isAdmitted ||
      queueEntry.visit?.encounterType === "inpatient" ||
      stage.includes("admit")
    ) {
      return "admitted";
    }
    if (stage.includes("consult")) return "in_consultation";
    if (stage.includes("provider") || stage.includes("clinician") || stage.includes("doctor")) return "waiting_provider";
    return "waiting_triage";
  }

  // Active encounter check
  if (latestEncounterIsActive.value) {
    return "waiting_triage";
  }

  if (props.profile.profileSummary.value?.latestEncounter?.status === "closed") {
    return "completed";
  }
  return "not_checked_in";
});

const visitStateDotClass: Record<VisitState, string> = {
  not_checked_in: "bg-muted-foreground/50",
  waiting_triage: "bg-warning",
  waiting_provider: "bg-emerald-500",
  in_consultation: "bg-primary",
  admitted: "bg-emerald-500",
  completed: "bg-success",
};

/**
 * The badge beside the patient's name — the profile's primary "where is this
 * patient" indicator, so it shows the precise step whenever one is resolved.
 *
 * `visitState` below it stays coarse on purpose: it drives the primary action
 * and the dot's bucket, where "which queue is this visit in" is the useful
 * question. But it cannot express a nursing pickup, and reading the coarse
 * value here is what kept this badge saying "Waiting for Triage" for a patient
 * the queue two panes away already showed as "With Nurse" (2026-08-16).
 */
const visitStateDisplayLabel = computed<string>(() => {
  const stepKey = stepLabelKey(activeAppointment.value?.visitStage);
  if (stepKey) return t(stepKey);

  switch (visitState.value) {
    case "admitted":
      return t("patient.stage_admitted_inpatient");
    case "waiting_triage":
      return t("patient.stage_waiting_triage");
    case "waiting_provider":
      return t("patient.stage_waiting_provider");
    case "in_consultation":
      return t("patient.stage_in_consultation");
    case "completed":
      return t("patient.stage_completed");
    case "not_checked_in":
    default:
      return t("patient.stage_not_checked_in");
  }
});

/**
 * Dot colour follows the same step when one is resolved, so "With Nurse" reads
 * as active contact rather than borrowing the waiting colour of the queue the
 * patient is technically still sitting in.
 */
const visitStateDotClassResolved = computed<string>(() => {
  switch (stepBadgeStatus(activeAppointment.value?.visitStage)) {
    case "in_progress":
      return "bg-primary";
    case "info":
      return "bg-primary";
    case "warning":
      return "bg-warning";
    case "success":
      return "bg-success";
    case "complete":
      return "bg-success";
    default:
      return visitStateDotClass[visitState.value];
  }
});

const primaryAction = computed<{
  label: string;
  icon: typeof DoorOpen | typeof ArrowRight;
  handler: () => void;
} | null>(() => {
  switch (visitState.value) {
    case "admitted":
    case "waiting_triage":
    case "waiting_provider":
    case "in_consultation":
      // Active patient already has "View in Queue" in the Overview tab's Current Visit card
      return null;
    case "completed":
      return {
        label: t("arrival.start_new_visit") || "Start New Visit",
        icon: DoorOpen,
        handler: props.arrivalIntake.openArrivalDialog,
      };
    case "not_checked_in":
    default:
      return {
        label: t("arrival.check_in") || "Check In",
        icon: DoorOpen,
        handler: props.arrivalIntake.openArrivalDialog,
      };
  }
});
</script>

<template>
  <div class="flex flex-1 flex-col overflow-hidden bg-surface">
    <!-- Pinned Patient Header (2027 High-Density Enterprise Layout — Strict Non-Wrapping) -->
    <header class="shrink-0 border-b border-border bg-surface px-4 py-2 flex items-center justify-between gap-3 overflow-hidden">
      <!-- Left identity & safety block -->
      <div class="flex min-w-0 items-center gap-3 flex-1 overflow-hidden">
        <Avatar class="size-9 shrink-0 border border-primary/20 bg-primary/5">
          <AvatarFallback class="text-xs font-bold text-primary">
            {{ patientInitials(patientDisplayName(patient)) }}
          </AvatarFallback>
        </Avatar>

        <div class="min-w-0 space-y-0.5 flex-1 overflow-hidden">
          <!-- Row 1: Name, MRN, Pin indicator, Journey State Badge -->
          <div class="flex items-center gap-2 min-w-0 overflow-hidden">
            <h1 class="truncate text-sm font-bold tracking-tight text-foreground">
              {{ patientDisplayName(patient) }}
            </h1>
            <span class="font-mono text-xs font-medium text-muted-foreground bg-secondary px-1.5 py-0.5 rounded border border-border/60 shrink-0">
              {{ patient.identifier[0]?.value }}
            </span>
            <span
              v-if="recentStore.isPinned(patient.id)"
              class="size-1.5 rounded-full bg-primary shrink-0"
              title="Pinned patient"
            />

            <!-- Journey Stage Badge -->
            <Badge
              variant="secondary"
              class="gap-1 text-[10.5px] px-2 py-0.5 font-medium border border-border shrink-0"
            >
              <span
                class="size-1.5 shrink-0 rounded-full"
                :class="visitStateDotClassResolved"
                aria-hidden="true"
              />
              {{ visitStateDisplayLabel }}
            </Badge>
          </div>

          <!-- Row 2: Age, Gender, Phone, Insurance Status, Visit Notes Trigger -->
          <div class="flex items-center gap-2.5 text-xs text-muted-foreground min-w-0 overflow-hidden">
            <span class="shrink-0">{{ t("patient.age_display", { age: patient.meta.extension.age }) }} · {{ profile.genderLabel(patient.gender) }}</span>
            <span v-if="patient.telecom?.find((t2) => t2.system === 'phone')?.value" class="font-mono shrink-0 hidden sm:inline">
              {{ patient.telecom.find((t2) => t2.system === 'phone')?.value }}
            </span>

            <!-- Insurance Verification Status -->
            <div class="flex items-center gap-1 border-l border-border/80 pl-2 min-w-0 shrink-0">
              <button
                v-if="insuranceNeedsVerification"
                type="button"
                class="inline-flex items-center gap-1 text-[11px] font-semibold text-warning hover:underline cursor-pointer max-w-[180px] truncate"
                @click="insuranceForm.openInsuranceForm(patient.id, profile.profileSummary.value?.insurance)"
              >
                <ShieldAlert class="size-3.5 shrink-0" />
                <span class="truncate">{{ profile.profileSummary.value?.insurance?.insuranceProvider ?? t("patient.insurance") }} ({{ insuranceAttentionLabel }})</span>
              </button>
              <span
                v-else-if="profile.profileSummary.value?.insurance?.verificationStatus === 'verified'"
                class="inline-flex items-center gap-1 text-[11px] font-semibold text-emerald-600 dark:text-emerald-400 max-w-[180px] truncate"
              >
                <ShieldCheck class="size-3.5 shrink-0" />
                <span class="truncate">{{ profile.profileSummary.value?.insurance.insuranceProvider }} ({{ t("insurance.verified") }})</span>
              </span>
              <span
                v-else
                class="inline-flex items-center gap-1 text-[11px] font-medium text-muted-foreground shrink-0"
              >
                <User class="size-3 shrink-0" />
                {{ t("insurance.cash_self_pay") }}
              </span>
            </div>

            <!-- Notes Quick Indicator -->
            <div v-if="activeAppointment" class="flex items-center gap-1 border-l border-border/80 pl-2 min-w-0 shrink-0">
              <button
                type="button"
                class="inline-flex items-center gap-1 text-[11px] text-primary hover:underline cursor-pointer max-w-[140px] truncate"
                @click="showNotesDialog = true"
              >
                <ScrollText class="size-3 shrink-0" />
                <span class="truncate">{{ currentVisitNotes || '💬 Visit Notes' }}</span>
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- Right actions block -->
      <div class="flex items-center gap-1.5 shrink-0 ml-auto">
        <Button
          v-if="primaryAction"
          size="sm"
          class="h-7 inline-flex items-center gap-1.5 font-medium text-xs shadow-xs cursor-pointer"
          @click="primaryAction.handler"
        >
          <component
            :is="primaryAction.icon"
            class="size-3.5"
            aria-hidden="true"
          />
          <span>{{ primaryAction.label }}</span>
        </Button>

        <Button
          variant="outline"
          size="sm"
          class="h-7 inline-flex items-center gap-1.5 font-medium text-xs cursor-pointer hidden md:inline-flex"
          @click="scheduling.openScheduleDialogForPatient(patient)"
        >
          <CalendarPlus class="size-3.5 text-muted-foreground" aria-hidden="true" />
          <span>{{ t("appointment.schedule_button") }}</span>
        </Button>

        <Popover>
          <PopoverTrigger as-child>
            <Button
              variant="ghost"
              size="sm"
              class="h-7 inline-flex items-center gap-1 text-xs text-muted-foreground hover:text-foreground cursor-pointer px-2"
              title="More actions"
            >
              <MoreHorizontal class="size-3.5" aria-hidden="true" />
            </Button>
          </PopoverTrigger>
          <PopoverContent class="w-56 p-1" align="end">
            <!-- On compact viewports, show Schedule inside More menu if hidden on toolbar -->
            <PopoverClose as-child>
              <button
                type="button"
                class="focus-ring flex w-full items-center gap-2 rounded-sm px-2.5 py-2 text-left text-xs text-foreground transition-colors hover:bg-muted cursor-pointer md:hidden"
                @click="scheduling.openScheduleDialogForPatient(patient)"
              >
                <CalendarPlus class="size-3.5 text-muted-foreground" aria-hidden="true" />
                {{ t("appointment.schedule_button") }}
              </button>
            </PopoverClose>
            <PopoverClose as-child>
              <button
                type="button"
                class="focus-ring flex w-full items-center gap-2 rounded-sm px-2.5 py-2 text-left text-xs text-foreground transition-colors hover:bg-muted cursor-pointer"
                @click="printSelectedLabel"
              >
                <Printer class="size-3.5 text-muted-foreground" aria-hidden="true" />
                {{ t("registration.print_label") }}
              </button>
            </PopoverClose>
            <PopoverClose as-child>
              <button
                type="button"
                class="focus-ring flex w-full items-center gap-2 rounded-sm px-2.5 py-2 text-left text-xs text-foreground transition-colors hover:bg-muted cursor-pointer"
                @click="openEditDemographics"
              >
                <Pencil class="size-3.5 text-muted-foreground" aria-hidden="true" />
                {{ t("patient.edit_demographics", "Edit Demographics") }}
              </button>
            </PopoverClose>
            <PopoverClose as-child>
              <button
                type="button"
                class="focus-ring flex w-full items-center gap-2 rounded-sm px-2.5 py-2 text-left text-xs text-foreground transition-colors hover:bg-muted cursor-pointer"
                @click="
                  insuranceForm.openInsuranceForm(
                    patient.id,
                    profile.profileSummary.value?.insurance,
                  )
                "
              >
                <ShieldCheck class="size-3.5 text-primary" aria-hidden="true" />
                {{ profile.profileSummary.value?.insurance ? t("insurance.edit_title") : t("insurance.add_title") }}
              </button>
            </PopoverClose>
            <PopoverClose as-child>
              <button
                type="button"
                class="focus-ring flex w-full items-center gap-2 rounded-sm px-2.5 py-2 text-left text-xs text-foreground transition-colors hover:bg-muted cursor-pointer"
                @click="emit('register-new')"
              >
                <UserPlus class="size-3.5 text-primary" aria-hidden="true" />
                {{ t("patient.register_new") || 'Register New Patient' }}
              </button>
            </PopoverClose>
            <div class="my-1 border-t border-border" />
            <PopoverClose as-child>
              <button
                type="button"
                class="focus-ring flex w-full items-center gap-2 rounded-sm px-2.5 py-2 text-left text-xs text-foreground transition-colors hover:bg-muted cursor-pointer"
                @click="togglePin"
              >
                <Pin
                  class="size-3.5"
                  :class="recentStore.isPinned(patient.id) ? 'text-primary' : 'text-muted-foreground'"
                  :fill="recentStore.isPinned(patient.id) ? 'currentColor' : 'none'"
                  aria-hidden="true"
                />
                {{ recentStore.isPinned(patient.id) ? t("patient.unpin") : t("patient.pin") }}
              </button>
            </PopoverClose>
          </PopoverContent>
        </Popover>

        <span class="mx-0.5 h-4 w-px bg-border shrink-0" aria-hidden="true" />

        <Button
          variant="ghost"
          size="icon"
          class="size-7 text-muted-foreground hover:text-foreground cursor-pointer shrink-0"
          :aria-label="t('common.close')"
          @click="patientStore.clearCurrentPatient()"
        >
          <X class="size-3.5" aria-hidden="true" />
        </Button>
      </div>
    </header>

    <!-- Tabbed Profile Navigation & Content -->
    <Tabs v-model="activeProfileTab" class="flex flex-1 flex-col overflow-hidden">
      <!-- Tabs Navigation Bar -->
      <div class="border-b border-border bg-surface px-3 pt-1 shrink-0">
        <TabsList class="h-8 gap-1 bg-transparent p-0 justify-start w-auto border-b-0 -mb-px">
          <TabsTrigger
            value="overview"
            class="h-8 gap-1.5 rounded-none border-b-2 border-transparent px-2 text-xs font-semibold data-[state=active]:border-primary data-[state=active]:bg-transparent data-[state=active]:text-primary cursor-pointer -mb-px shrink-0"
          >
            <Activity class="size-3.5" aria-hidden="true" />
            <span>{{ t("patient.tab_overview") }}</span>
          </TabsTrigger>
          <TabsTrigger
            value="demographics"
            class="h-8 gap-1.5 rounded-none border-b-2 border-transparent px-2 text-xs font-semibold data-[state=active]:border-primary data-[state=active]:bg-transparent data-[state=active]:text-primary cursor-pointer -mb-px shrink-0"
          >
            <Contact class="size-3.5" aria-hidden="true" />
            <span>{{ t("patient.tab_demographics") }}</span>
          </TabsTrigger>
          <TabsTrigger
            value="appointments"
            class="h-8 gap-1.5 rounded-none border-b-2 border-transparent px-2 text-xs font-semibold data-[state=active]:border-primary data-[state=active]:bg-transparent data-[state=active]:text-primary cursor-pointer -mb-px shrink-0"
          >
            <CalendarClock class="size-3.5" aria-hidden="true" />
            <span>{{ t("patient.tab_appointments") }}</span>
            <Badge
              v-if="profile.upcomingAppointments.value.length > 0"
              variant="secondary"
              class="ml-0.5 px-1.5 py-0 text-[10px] font-mono tabular-nums transition-colors"
              :class="activeProfileTab === 'appointments' ? 'bg-primary/15 text-primary font-semibold' : 'text-muted-foreground'"
              :aria-label="
                t('appointment.count_sr', {
                  count: profile.upcomingAppointments.value.length,
                })
              "
            >
              {{ profile.upcomingAppointments.value.length }}
            </Badge>
          </TabsTrigger>
          <TabsTrigger
            value="audit"
            class="h-8 gap-1.5 rounded-none border-b-2 border-transparent px-2 text-xs font-semibold data-[state=active]:border-primary data-[state=active]:bg-transparent data-[state=active]:text-primary cursor-pointer -mb-px shrink-0"
          >
            <ScrollText class="size-3.5" aria-hidden="true" />
            <span>{{ t("patient.tab_audit") }}</span>
            <Badge
              v-if="profile.auditFeed.value.length > 0"
              variant="secondary"
              class="ml-0.5 px-1.5 py-0 text-[10px] font-mono tabular-nums transition-colors"
              :class="activeProfileTab === 'audit' ? 'bg-primary/15 text-primary font-semibold' : 'text-muted-foreground'"
              :aria-label="
                t('patient.count_sr', {
                  count: profile.auditFeed.value.length,
                })
              "
            >
              {{ profile.auditFeed.value.length }}
            </Badge>
          </TabsTrigger>
        </TabsList>
      </div>

      <!-- Tab 1: Overview & Active Visit -->
      <TabsContent value="overview" class="flex-1 overflow-y-auto p-3.5 space-y-3">
        <!-- High-Visibility Insurance Clearance Notice -->
        <div
          v-if="insuranceNeedsVerification && profile.profileSummary.value?.insurance"
          class="rounded-lg border border-warning/40 bg-warning/10 p-2.5 flex flex-wrap items-center justify-between gap-3"
        >
          <div class="flex items-start gap-2.5">
            <TriangleAlert class="size-4 text-warning shrink-0 mt-0.5" />
            <div>
              <p class="text-xs font-bold text-foreground">
                {{ t("insurance.unverified_warning_title") }}
              </p>
              <p class="text-[11.5px] text-muted-foreground mt-0.5">
                {{ profile.profileSummary.value.insurance.insuranceProvider }} · Member ID:
                <strong class="text-foreground font-mono">{{ profile.profileSummary.value.insurance.memberId }}</strong>
                · {{ t("insurance.unverified_warning_desc") }}
              </p>
            </div>
          </div>
          <div class="flex items-center gap-2">
            <Button
              v-if="profile.profileSummary.value.insurance.id"
              size="sm"
              class="h-7 text-xs px-2.5 gap-1 shadow-xs cursor-pointer"
              @click="insuranceForm.verifyInsurance(patient.id, profile.profileSummary.value.insurance.id!)"
            >
              <ShieldCheck class="size-3.5" />
              {{ t("insurance.verify_clearance") }}
            </Button>
            <Button
              variant="outline"
              size="sm"
              class="h-7 text-xs px-2.5 gap-1 cursor-pointer"
              @click="insuranceForm.openInsuranceForm(patient.id, profile.profileSummary.value.insurance)"
            >
              <Pencil class="size-3" />
              {{ t("insurance.edit_coverage") }}
            </Button>
          </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-2.5">
          <!-- Active Visit Section -->
          <div class="rounded-lg border border-border/70 bg-card/60 p-3 space-y-2">
            <div class="flex flex-row items-center justify-between pb-1.5 border-b border-border/50">
              <span class="text-[11px] font-bold uppercase tracking-wider text-foreground flex items-center gap-1.5">
                <Activity class="size-3.5 text-primary" />
                <span>{{ t("patient.current_visit") }}</span>
              </span>
              <StatusBadge
                v-if="activeAppointment"
                :status="currentVisitStatusType"
                :label="currentVisitStatusLabel"
                class="shrink-0"
              />
            </div>
            <div class="space-y-1.5">
              <div v-if="profile.isSummaryLoading.value" class="space-y-2 animate-pulse">
                <div class="h-4 w-32 rounded bg-secondary/80" />
                <div class="h-3 w-48 rounded bg-secondary/60" />
              </div>
              <div v-else-if="currentVisitIsEmpty" class="text-xs text-muted-foreground/70 py-2">
                {{ t("patient.no_active_visit") }}
              </div>
              <div v-else class="space-y-1.5 text-xs">
                <!--
                  Guarded, not asserted. This block renders whenever *either* an
                  appointment or a live encounter exists (see currentVisitIsEmpty),
                  so activeAppointment can legitimately be null here — a
                  direct-service walk-in has an encounter and no appointment. The
                  `!` that used to be on these two lines silenced the compiler on
                  a case that genuinely occurs and crashed the panel at runtime.
                -->
                <template v-if="activeAppointment">
                  <div class="flex items-center justify-between py-0.5">
                    <span class="text-muted-foreground">{{ t("appointment.department") }}:</span>
                    <span class="font-medium text-foreground">{{ activeAppointment.department ?? "General OPD" }}</span>
                  </div>
                  <div class="flex items-center justify-between py-0.5">
                    <span class="text-muted-foreground">{{ t("appointment.scheduled") }}:</span>
                    <span class="font-mono text-foreground">{{ formatClinicalDate(activeAppointment.scheduledAt) }}</span>
                  </div>
                </template>
                <div v-if="currentVisitClinicianName" class="flex items-center justify-between py-0.5">
                  <span class="text-muted-foreground">{{ t("appointment.attending") }}:</span>
                  <span class="font-medium text-foreground">{{ currentVisitClinicianName }}</span>
                </div>
                <div class="pt-2">
                  <Button size="sm" variant="outline" class="w-full text-xs gap-1 h-7 font-medium" @click="emit('view-in-queue')">
                    <ArrowRight class="size-3.5" />
                    {{ t("arrival.view_in_queue") }}
                  </Button>
                </div>
              </div>
            </div>
          </div>

          <!-- Allergies & Safety Alerts Section -->
          <div class="rounded-lg border border-border/70 bg-card/60 p-3 space-y-2">
            <div class="flex items-center justify-between pb-1.5 border-b border-border/50">
              <span class="text-[11px] font-bold uppercase tracking-wider text-foreground flex items-center gap-1.5">
                <TriangleAlert class="size-3.5 text-amber-500" />
                <span>{{ t("patient.allergies") }}</span>
              </span>
            </div>
            <div>
              <div v-if="profile.isSummaryLoading.value" class="h-6 w-32 rounded bg-secondary/60 animate-pulse" />
              <div v-else-if="(profile.profileSummary.value?.alerts.length ?? 0) > 0" class="flex flex-wrap gap-1.5">
                <Badge
                  v-for="allergy in profile.profileSummary.value?.alerts"
                  :key="allergy.id"
                  :variant="allergy.severity === 'severe' ? 'critical' : 'warning'"
                  class="inline-flex items-center gap-1 text-xs"
                >
                  <TriangleAlert class="size-3" aria-hidden="true" />
                  {{ allergy.substanceName }}
                </Badge>
              </div>
              <div v-else class="flex items-center gap-1.5 py-1">
                <Badge variant="success" class="inline-flex items-center gap-1 text-xs">
                  <CircleCheck class="size-3" aria-hidden="true" />
                  {{ t("patient.no_allergies") }}
                </Badge>
              </div>
            </div>
          </div>
        </div>

        <!-- Quick Summary Strip: Next Upcoming Appointment -->
        <div v-if="!upcomingAppointmentsIsEmpty && profile.upcomingAppointments.value[0]" class="rounded-lg border border-primary/25 bg-primary/5 p-3 flex flex-wrap items-center justify-between gap-3">
          <div class="flex items-center gap-3">
            <div class="flex size-8 shrink-0 items-center justify-center rounded-md bg-primary/20 text-primary">
              <CalendarClock class="size-4.5" />
            </div>
            <div>
              <p class="text-[11px] font-semibold text-primary uppercase tracking-wider">{{ t("appointment.next_upcoming") }}</p>
              <p class="text-xs font-medium text-foreground mt-0.5">
                {{ formatClinicalDate(profile.upcomingAppointments.value[0].scheduledAt) }} · {{ profile.upcomingAppointments.value[0].department ?? profile.upcomingAppointments.value[0].reason ?? "General Follow-up" }}
              </p>
            </div>
          </div>
          <Button
            size="sm"
            class="h-7 gap-1 text-xs px-2.5"
            @click="arrivalIntake.checkInAppointment(profile.upcomingAppointments.value[0].id, patient.id)"
          >
            <LogIn class="size-3.5" />
            {{ t("arrival.check_in") }}
          </Button>
        </div>
      </TabsContent>

      <!-- Tab 2: Demographics & Payer Coverage -->
      <TabsContent value="demographics" class="flex-1 overflow-y-auto p-3.5 space-y-3">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-2.5">
          <!-- Contact Details Section -->
          <div class="rounded-lg border border-border/70 bg-card/60 p-3 space-y-2">
            <div class="flex flex-row items-center justify-between pb-1.5 border-b border-border/50">
              <span class="text-[11px] font-bold uppercase tracking-wider text-foreground flex items-center gap-1.5">
                <Contact class="size-3.5 text-primary" />
                <span>{{ t("patient.contact_and_identity") }}</span>
              </span>
              <Button variant="ghost" size="sm" class="h-6 px-2 text-xs gap-1 text-primary cursor-pointer" @click="openEditDemographics">
                <Pencil class="size-3" />
                {{ t("common.edit") }}
              </Button>
            </div>
            <div class="space-y-1.5 text-xs">
              <div class="flex items-center justify-between py-0.5">
                <span class="text-muted-foreground flex items-center gap-1.5"><Phone class="size-3 text-muted-foreground" /> {{ t("patient.phone") }}</span>
                <span class="font-medium text-foreground">{{ patient.telecom.find((t2) => t2.system === "phone")?.value ?? "—" }}</span>
              </div>
              <div class="flex items-center justify-between py-0.5">
                <span class="text-muted-foreground flex items-center gap-1.5"><Mail class="size-3 text-muted-foreground" /> {{ t("patient.email") }}</span>
                <span class="font-medium text-foreground">{{ profile.profileSummary.value?.contact.email ?? "—" }}</span>
              </div>
              <div class="flex items-center justify-between py-0.5">
                <span class="text-muted-foreground flex items-center gap-1.5"><MapPin class="size-3 text-muted-foreground" /> {{ t("patient.address") }}</span>
                <span class="font-medium text-foreground truncate max-w-[200px]">{{ profile.contactAddress.value ?? "—" }}</span>
              </div>
              <div v-if="profile.profileSummary.value?.contact.nextOfKinName" class="flex items-center justify-between py-0.5">
                <span class="text-muted-foreground flex items-center gap-1.5"><Users class="size-3 text-muted-foreground" /> {{ t("patient.next_of_kin") }}</span>
                <span class="font-medium text-foreground text-right">
                  {{ profile.profileSummary.value.contact.nextOfKinName }}
                  <span v-if="profile.profileSummary.value.contact.nextOfKinPhone" class="block text-[11px] font-mono text-muted-foreground">
                    {{ profile.profileSummary.value.contact.nextOfKinPhone }}
                  </span>
                </span>
              </div>
            </div>
          </div>

          <!-- Insurance & Payer Section -->
          <div class="rounded-lg border border-border/70 bg-card/60 p-3 space-y-2">
            <div class="flex flex-row items-center justify-between pb-1.5 border-b border-border/50">
              <span class="text-[11px] font-bold uppercase tracking-wider text-foreground flex items-center gap-1.5">
                <ShieldCheck class="size-3.5 text-emerald-500" />
                <span>{{ t("patient.insurance") }}</span>
              </span>
              <Button
                variant="ghost"
                size="sm"
                class="h-6 px-2 text-xs gap-1 text-primary cursor-pointer"
                @click="insuranceForm.openInsuranceForm(patient.id, profile.profileSummary.value?.insurance)"
              >
                <Pencil v-if="!insuranceIsEmpty" class="size-3" />
                <Plus v-else class="size-3" />
                {{ insuranceIsEmpty ? t("insurance.add_title") : t("common.edit") }}
              </Button>
            </div>
            <div>
              <div v-if="profile.isSummaryLoading.value" class="space-y-2 animate-pulse">
                <div class="h-4 w-32 rounded bg-secondary/80" />
                <div class="h-4 w-40 rounded bg-secondary/60" />
              </div>
              <div v-else-if="insuranceIsEmpty" class="text-xs text-muted-foreground/70 py-3 text-center">
                <p>{{ t("patient.no_insurance") }}</p>
                <Button
                  size="sm"
                  variant="outline"
                  class="mt-2 text-xs gap-1 h-7"
                  @click="insuranceForm.openInsuranceForm(patient.id, profile.profileSummary.value?.insurance)"
                >
                  <Plus class="size-3" />
                  {{ t("insurance.add_title") }}
                </Button>
              </div>
              <div v-else class="space-y-1.5 text-xs">
                <div class="flex items-center justify-between py-0.5">
                  <span class="text-muted-foreground">{{ t("patient.insurance_provider") }}</span>
                  <span class="font-medium text-foreground">{{ profile.profileSummary.value!.insurance!.insuranceProvider ?? "—" }}</span>
                </div>
                <div class="flex items-center justify-between py-0.5">
                  <span class="text-muted-foreground">{{ t("patient.insurance_member_id") }}</span>
                  <span class="font-mono font-medium text-foreground">{{ profile.profileSummary.value!.insurance!.memberId ?? "—" }}</span>
                </div>
                <div class="flex items-center justify-between py-0.5">
                  <span class="text-muted-foreground">{{ t("patient.insurance_status") }}</span>
                  <span class="font-medium text-foreground">{{ insuranceStatusLabel(profile.profileSummary.value!.insurance!.status) }}</span>
                </div>
                <div class="flex items-center justify-between py-0.5">
                  <span class="text-muted-foreground">{{ t("insurance.verification_status") }}</span>
                  <div class="flex items-center gap-1.5">
                    <Badge
                      :variant="profile.profileSummary.value!.insurance!.verificationStatus === 'verified' ? 'success' : 'warning'"
                      class="text-[11px]"
                    >
                      {{ insuranceVerificationLabel(profile.profileSummary.value!.insurance!.verificationStatus) }}
                    </Badge>
                    <Button
                      v-if="profile.profileSummary.value!.insurance!.verificationStatus !== 'verified' && profile.profileSummary.value!.insurance!.id"
                      size="sm"
                      variant="outline"
                      class="h-6 text-[10.5px] px-2 gap-1 text-primary cursor-pointer"
                      @click="insuranceForm.verifyInsurance(patient.id, profile.profileSummary.value!.insurance!.id!)"
                    >
                      <CircleCheck class="size-3" />
                      {{ t("insurance.verify") }}
                    </Button>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </TabsContent>

      <!-- Tab 3: Appointments & History -->
      <TabsContent value="appointments" class="flex-1 overflow-y-auto p-3.5 space-y-3">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-2.5">
          <!-- Upcoming Appointments List -->
          <div class="rounded-lg border border-border/70 bg-card/60 p-3 space-y-2">
            <div class="flex flex-row items-center justify-between pb-1.5 border-b border-border/50">
              <span class="text-[11px] font-bold uppercase tracking-wider text-foreground flex items-center gap-1.5">
                <CalendarClock class="size-3.5 text-primary" />
                <span>{{ t("patient.upcoming_appointments") }}</span>
              </span>
              <Button size="sm" variant="ghost" class="h-6 px-2 text-xs gap-1 text-primary" @click="scheduling.openScheduleDialogForPatient(patient)">
                <Plus class="size-3" />
                {{ t("appointment.book") }}
              </Button>
            </div>
            <div>
              <div v-if="profile.isSummaryLoading.value" class="space-y-2 animate-pulse">
                <div class="h-10 w-full rounded bg-secondary/60" />
              </div>
              <p v-else-if="upcomingAppointmentsIsEmpty" class="text-xs text-muted-foreground/70 py-2">
                {{ t("patient.no_upcoming_appointments") }}
              </p>
              <ul v-else class="space-y-1.5 text-xs">
                <li
                  v-for="appt in profile.upcomingAppointments.value"
                  :key="appt.id"
                  class="flex items-center justify-between gap-2 p-2 rounded-md border border-border/60 bg-surface/50"
                >
                  <div class="min-w-0">
                    <span class="block font-semibold text-foreground text-xs">{{ formatClinicalDate(appt.scheduledAt) }}</span>
                    <span class="block truncate text-[11px] text-muted-foreground">{{ appt.department ?? appt.reason ?? "Consultation" }}</span>
                  </div>
                  <Button
                    size="sm"
                    class="h-7 shrink-0 gap-1 px-2.5 text-xs"
                    @click="arrivalIntake.checkInAppointment(appt.id, patient.id)"
                  >
                    <LogIn class="size-3" aria-hidden="true" />
                    {{ t("arrival.check_in") }}
                  </Button>
                </li>
              </ul>
            </div>
          </div>

          <!-- Recent Past Visits -->
          <div class="rounded-lg border border-border/70 bg-card/60 p-3 space-y-2">
            <div class="flex items-center justify-between pb-1.5 border-b border-border/50">
              <span class="text-[11px] font-bold uppercase tracking-wider text-foreground flex items-center gap-1.5">
                <History class="size-3.5 text-muted-foreground" />
                <span>{{ t("patient.recent_visits") }}</span>
              </span>
            </div>
            <div>
              <div v-if="profile.isSummaryLoading.value" class="space-y-2 animate-pulse">
                <div class="h-10 w-full rounded bg-secondary/60" />
              </div>
              <p v-else-if="recentVisitIsEmpty" class="text-xs text-muted-foreground/70 py-2">
                {{ t("patient.no_visits") }}
              </p>
              <div v-else class="p-2.5 rounded-md border border-border/60 bg-surface/50 flex items-center justify-between text-xs">
                <div>
                  <p class="font-semibold text-foreground text-xs">
                    {{ formatClinicalDate(profile.profileSummary.value!.latestEncounter!.openedAt) }}
                  </p>
                  <p v-if="profile.profileSummary.value!.latestEncounter!.primaryClinicianName" class="text-xs text-muted-foreground">
                    {{ profile.profileSummary.value!.latestEncounter!.primaryClinicianName }}
                  </p>
                </div>
                <StatusBadge
                  v-if="profile.profileSummary.value!.latestEncounter!.status"
                  :status="latestVisitStatus(profile.profileSummary.value!.latestEncounter!.status)"
                />
              </div>
            </div>
          </div>
        </div>
      </TabsContent>

      <!-- Tab 4: Audit Trail -->
      <TabsContent value="audit" class="flex-1 overflow-y-auto p-3.5">
        <div class="rounded-lg border border-border/70 bg-card/60 p-3 space-y-2">
          <div class="flex items-center justify-between pb-1.5 border-b border-border/50">
            <span class="text-[11px] font-bold uppercase tracking-wider text-foreground flex items-center gap-1.5">
              <ScrollText class="size-3.5 text-muted-foreground" />
              <span>{{ t("patient.audit_trail") }}</span>
            </span>
          </div>
          <div>
            <div v-if="profile.isSummaryLoading.value" class="space-y-2 animate-pulse">
              <div v-for="n in 3" :key="n" class="h-6 w-full rounded bg-secondary/60" />
            </div>
            <p v-else-if="auditTrailIsEmpty" class="text-xs text-muted-foreground/70 py-2">
              {{ t("patient.no_audit_activity") }}
            </p>
            <ul v-else class="space-y-1.5 text-xs text-muted-foreground divide-y divide-border/40">
              <li
                v-for="entry in profile.auditFeed.value"
                :key="entry.id"
                class="flex items-center justify-between pt-1.5 first:pt-0"
              >
                <span class="font-medium text-foreground">
                  {{ profile.auditActionLabel(entry) }}
                </span>
                <span class="font-mono text-muted-foreground text-[11px]">
                  {{ formatClinicalDate(entry.occurredAt) }}
                </span>
              </li>
            </ul>
          </div>
        </div>
      </TabsContent>
    </Tabs>

    <!-- Visit Communication Notes Dialog -->
    <VisitNotesDialog
      v-if="activeAppointment"
      v-model:open="showNotesDialog"
      :appointment-id="activeAppointment.id"
      :initial-notes="currentVisitNotes"
      @saved="onNotesUpdated"
    />
  </div>
</template>
