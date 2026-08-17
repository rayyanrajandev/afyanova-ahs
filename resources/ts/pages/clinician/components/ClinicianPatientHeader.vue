/**
 * ClinicianPatientHeader — High-Density Patient Banner & Action Bar (Volume 1.1 §7, Volume 2.2 §4.2)
 * =================================================================================================
 * Displays patient identity, clinical triage acuity, insurance clearance,
 * allergy flags, and primary clinical completion & admission actions.
 */

<script setup lang="ts">
import {
  Activity,
  BedDouble,
  CheckCircle2,
  Clock,
  Eye,
  FileCheck,
  FlaskConical,
  HeartPulse,
  ShieldAlert,
  ShieldCheck,
  Stethoscope,
  TriangleAlert,
  UserCheck,
  UserRound,
  Zap,
} from "lucide-vue-next";
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import { Avatar, AvatarFallback } from "@/components/ui/avatar";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { stepBadgeStatus, stepLabelKey } from "@/composables/patientFlowStep";
import { patientInitials } from "@/pages/reception/receptionFormatters";
import type { Patient } from "@/stores/patientStore";
import type { ReadinessContext, VisitContext } from "@/stores/queueStore";

const props = defineProps<{
  patient: Patient;
  encounterId: string | null;
  visit: VisitContext | null;
  readiness: ReadinessContext | null;
  allergies?: string[];
  isLoadingVisit?: boolean;
  clinicalMode?: "active" | "awaiting_start" | "triage_pending" | "read_only" | "completed";
  isSigning?: boolean;
  isStartingConsultation?: boolean;
  isSendingForDiagnostics?: boolean;
  onSignComplete?: () => void;
  onOpenAdmissionDialog?: () => void;
  /**
   * Starts (or takes over) the consultation. Replaces the former
   * `onBypassTriage`, which only ever mutated local component state — the
   * badge changed in one browser tab and the patient stayed "Waiting for
   * Doctor" for everybody else.
   */
  onStartConsultation?: () => void;
  /**
   * Provided only when this consultation has outstanding lab or imaging work —
   * "send the patient out" is meaningless otherwise.
   */
  onSendForDiagnostics?: () => void;
}>();

const { t } = useI18n({ useScope: "global" });

const fullName = computed(() => {
  return `${props.patient.name[0]?.given?.join(" ") ?? ""} ${props.patient.name[0]?.family ?? ""}`.trim();
});

/**
 * The visit is waiting for a doctor and nobody has started seeing the patient
 * yet — the state the flow ticket was raised for. There was no action here at
 * all: a doctor could call the patient in, begin the consultation, and the
 * board would still read "Waiting for Doctor" for every other member of staff.
 */
const isAwaitingConsultationStart = computed(() => {
  const stage = props.visit?.appointmentStatus ?? props.visit?.stage;

  return (
    stage === "waiting_provider" ||
    stage === "waiting_clinician" ||
    stage === "waiting_clinician_review"
  );
});

/**
 * The badge beside the patient's name, resolved from the server's flow step.
 * Falls through to the coarse `visitStage` chain when no step is available —
 * an older visit predating the flow work, or an encounter with no appointment.
 */
const resolvedStepLabel = computed<string | null>(() => {
  const key = stepLabelKey(props.visit?.visitStage);

  return key ? t(key) : null;
});

const resolvedStepIcon = computed(() => {
  switch (props.visit?.visitStage) {
    case "with_clinician":
      return Stethoscope;
    case "with_nurse":
      return HeartPulse;
    case "in_triage":
      return Activity;
    case "admitted":
      return BedDouble;
    case "completed":
      return CheckCircle2;
    default:
      return Clock;
  }
});

/** Colour follows the shared step vocabulary: active contact vs waiting. */
const resolvedStepBadgeClass = computed<string>(() => {
  switch (stepBadgeStatus(props.visit?.visitStage)) {
    case "in_progress":
    case "info":
      return "border-primary/30 text-primary bg-primary/10";
    case "success":
    case "complete":
      return "border-success/40 text-success bg-success/10";
    default:
      return "border-warning/40 text-warning bg-warning/10";
  }
});

const isWithClinician = computed(() => {
  const stage = props.visit?.appointmentStatus ?? props.visit?.stage;

  return stage === "in_consultation" || stage === "with_clinician";
});

const mrn = computed(() => props.patient.identifier[0]?.value ?? "MRN-0000");
const age = computed(() => props.patient.meta.extension.age);
const gender = computed(() => props.patient.gender);
const phone = computed(() => props.patient.telecom?.find((t) => t.system === "phone")?.value ?? "—");

const isAdmitted = computed(() => {
  return (
    props.visit?.isAdmitted ||
    props.visit?.encounterType === "inpatient" ||
    props.visit?.stage === "admitted_inpatient" ||
    props.visit?.stage === "admitted"
  );
});

const visitStage = computed<
  "loading" | "admitted" | "waiting_triage" | "waiting_provider" | "in_consultation" | "completed" | "not_checked_in"
>(() => {
  if (props.isLoadingVisit && !props.visit) return "loading";
  if (isAdmitted.value) return "admitted";
  const stage = props.visit?.appointmentStatus ?? props.visit?.stage;

  if (stage === "waiting_triage" || stage === "in_triage") {
    return "waiting_triage";
  }
  if (
    stage === "waiting_provider" ||
    stage === "waiting_clinician" ||
    stage === "waiting_clinician_review" ||
    stage === "triaged"
  ) {
    return "waiting_provider";
  }
  if (stage === "in_consultation" || stage === "with_clinician" || stage === "in_progress") {
    return "in_consultation";
  }
  if (stage === "completed" || stage === "closed" || stage === "resolved") {
    return "completed";
  }
  if (stage === "open" || stage === "opened") {
    return "waiting_provider";
  }
  if (stage === "scheduled" || stage === "not_checked_in") {
    return "not_checked_in";
  }
  if (props.encounterId) {
    return "waiting_provider";
  }
  return "not_checked_in";
});

const isInsurance = computed(() => {
  return (
    props.readiness?.coverageType === "insurance" ||
    (props.patient as any).insurance?.coverageType === "insurance"
  );
});

const isInsuranceVerified = computed(() => {
  return (
    props.readiness?.insuranceVerified === true ||
    (props.patient as any).insurance?.verificationStatus === "verified"
  );
});
</script>

<template>
  <header class="border-b border-border bg-surface px-4 py-2 shrink-0 flex items-center justify-between gap-3 overflow-hidden rounded-t-lg">
    <!-- Left: Identity, Demographics, Safety Badges -->
    <div class="flex items-center gap-3 min-w-0 flex-1 overflow-hidden">
      <Avatar class="size-9 shrink-0 border border-primary/20 bg-primary/5">
        <AvatarFallback class="text-xs font-bold text-primary">
          {{ patientInitials(fullName) }}
        </AvatarFallback>
      </Avatar>

      <div class="min-w-0 space-y-0.5 flex-1 overflow-hidden">
        <div class="flex items-center gap-2 min-w-0 overflow-hidden">
          <h2 class="font-bold text-foreground text-sm tracking-tight truncate">
            {{ fullName }}
          </h2>
          <span class="font-mono text-xs font-medium text-muted-foreground bg-secondary px-1.5 py-0.2 rounded border border-border/60 shrink-0">
            {{ mrn }}
          </span>

          <!-- Active Journey Stage Badge -->
          <span
            v-if="visitStage === 'loading'"
            class="inline-flex items-center h-5 w-20 animate-pulse rounded bg-muted/60 shrink-0"
            aria-hidden="true"
          />
          <!--
            Server-resolved flow step (2026-08-16). The coarse chain below can
            only express which queue a visit sits in, so a patient a nurse had
            picked up read as "Waiting for Triage"/"Triaged · Waiting Doctor"
            here while the queues correctly showed "With Nurse". Rendered
            through the same shared mapping reception and both queues use.
          -->
          <Badge
            v-else-if="resolvedStepLabel"
            variant="outline"
            class="gap-1 text-[10.5px] px-2 py-0.2 font-medium shrink-0"
            :class="resolvedStepBadgeClass"
          >
            <component :is="resolvedStepIcon" class="size-3" />
            <span>{{ resolvedStepLabel }}</span>
          </Badge>
          <Badge
            v-else-if="visitStage === 'admitted'"
            variant="default"
            class="bg-indigo-600 hover:bg-indigo-600 text-white gap-1 text-[10.5px] px-2 py-0.2 font-medium shrink-0"
          >
            <BedDouble class="size-3" />
            <span>{{ t("patient.stage_admitted_inpatient") }}</span>
          </Badge>
          <Badge
            v-else-if="visitStage === 'waiting_triage'"
            variant="outline"
            class="gap-1 text-[10.5px] px-2 py-0.2 font-medium border-warning/40 text-warning bg-warning/10 shrink-0"
          >
            <Clock class="size-3" />
            <span>{{ t("patient.stage_waiting_triage") }}</span>
          </Badge>
          <Badge
            v-else-if="visitStage === 'waiting_provider'"
            variant="outline"
            class="gap-1 text-[10.5px] px-2 py-0.2 font-medium border-emerald-500/40 text-emerald-600 dark:text-emerald-400 bg-emerald-500/10 shrink-0"
          >
            <UserCheck class="size-3" />
            <span>{{ t("patient.stage_waiting_provider") }}</span>
          </Badge>
          <Badge
            v-else-if="visitStage === 'in_consultation'"
            variant="secondary"
            class="gap-1 text-[10.5px] px-2 py-0.2 font-medium border border-primary/30 text-primary bg-primary/10 shrink-0"
          >
            <Stethoscope class="size-3 text-primary" />
            <span>{{ t("patient.stage_in_consultation") }}</span>
          </Badge>
          <Badge
            v-else-if="visitStage === 'completed'"
            variant="secondary"
            class="gap-1 text-[10.5px] px-2 py-0.2 font-medium border border-border shrink-0"
          >
            <CheckCircle2 class="size-3 text-muted-foreground" />
            <span>{{ t("patient.stage_completed") }}</span>
          </Badge>
          <Badge
            v-else
            variant="secondary"
            class="gap-1 text-[10.5px] px-2 py-0.2 font-medium border border-border text-muted-foreground shrink-0"
          >
            <UserRound class="size-3 text-muted-foreground" />
            <span>{{ t("patient.stage_not_checked_in") }}</span>
          </Badge>
        </div>

        <div class="flex items-center gap-2.5 text-xs text-muted-foreground min-w-0 overflow-hidden">
          <span class="shrink-0">{{ age }}y · {{ t(`patient.gender_${gender}`) }}</span>
          <span v-if="phone" class="font-mono shrink-0 hidden sm:inline">{{ phone }}</span>

          <!-- Insurance / Payer Status Badge -->
          <div class="flex items-center gap-1 border-l border-border/80 pl-2.5 min-w-0 shrink-0">
            <template v-if="isInsurance">
              <span
                v-if="isInsuranceVerified"
                class="inline-flex items-center gap-1 text-[11px] font-semibold text-emerald-600 dark:text-emerald-400 max-w-[180px] truncate"
              >
                <ShieldCheck class="size-3.5 shrink-0" />
                <span class="truncate">{{ readiness?.insuranceProvider ?? t("insurance.insurance") }} ({{ t("insurance.verified") }})</span>
              </span>
              <span
                v-else
                class="inline-flex items-center gap-1 text-[11px] font-semibold text-warning max-w-[180px] truncate"
              >
                <ShieldAlert class="size-3.5 shrink-0" />
                <span class="truncate">{{ t("insurance.unverified_warning_title") }}</span>
              </span>
            </template>
            <template v-else>
              <span class="inline-flex items-center gap-1 text-[11px] font-medium text-muted-foreground shrink-0">
                <UserRound class="size-3 shrink-0" />
                {{ t("insurance.cash_self_pay") }}
              </span>
            </template>
          </div>

          <!-- Allergy Safety Badge -->
          <div class="flex items-center gap-1 border-l border-border/80 pl-2.5 shrink-0">
            <span
              v-if="allergies && allergies.length > 0"
              class="inline-flex items-center gap-1 rounded bg-critical/15 px-1.5 py-0.2 text-[10.5px] font-bold text-critical max-w-[180px] truncate"
            >
              <TriangleAlert class="size-3 shrink-0" />
              <span class="truncate">{{ t("patient.allergies_count", { count: allergies.length }) }} ({{ allergies.join(", ") }})</span>
            </span>
            <span
              v-else
              class="inline-flex items-center gap-1 text-[10.5px] font-medium text-emerald-600 dark:text-emerald-400"
            >
              <CheckCircle2 class="size-3" />
              {{ t("patient.no_allergies") }}
            </span>
          </div>
        </div>
      </div>
    </div>

    <!-- Right: Consultation Primary Actions (Gated by Clinical Mode) -->
    <div v-if="clinicalMode === 'read_only'" class="flex items-center gap-1.5 shrink-0 ml-auto">
      <div class="flex items-center gap-1 text-[11px] font-medium text-muted-foreground bg-muted/60 px-2 py-0.5 rounded border border-border/80">
        <Eye class="size-3 text-muted-foreground" />
        <span>{{ t("clinician.chart_review_mode") }}</span>
      </div>
    </div>

    <div v-else-if="clinicalMode === 'triage_pending'" class="flex items-center gap-1.5 shrink-0 ml-auto">
      <Button
        v-if="onStartConsultation"
        type="button"
        size="sm"
        :disabled="isStartingConsultation"
        class="h-6.5 px-2.5 gap-1 text-[11.5px] font-semibold bg-amber-600 hover:bg-amber-700 text-white cursor-pointer shadow-2xs disabled:opacity-60"
        @click="onStartConsultation"
      >
        <Zap class="size-3" />
        <span>{{ t("clinician.bypass_triage_action") }}</span>
      </Button>
      <div v-else class="flex items-center gap-1 text-[11px] font-medium text-amber-700 dark:text-amber-300 bg-amber-500/10 px-2 py-0.5 rounded border border-amber-500/30">
        <Clock class="size-3 text-amber-600 dark:text-amber-400" />
        <span>{{ t("clinician.triage_in_progress") }}</span>
      </div>
    </div>

    <!--
      Queued for a doctor, nobody has started. Exactly one action, because
      exactly one thing can legitimately happen next. "Admit to Ward" and
      "Sign & Complete" used to sit here alongside it, offering to conclude a
      consultation that had not begun.
    -->
    <div v-else-if="clinicalMode === 'awaiting_start'" class="flex items-center gap-1.5 shrink-0 ml-auto">
      <Button
        v-if="onStartConsultation"
        type="button"
        size="sm"
        :disabled="isStartingConsultation"
        class="h-6.5 px-2.5 gap-1 text-xs font-semibold bg-primary hover:bg-primary/90 text-primary-foreground cursor-pointer shadow-2xs disabled:opacity-60"
        @click="onStartConsultation"
      >
        <Stethoscope class="size-3" />
        <span>{{
          isStartingConsultation
            ? t("clinician.starting_consultation")
            : t("clinician.start_consultation_action")
        }}</span>
      </Button>
      <div
        v-else
        class="flex items-center gap-1 text-xs font-medium text-muted-foreground bg-muted/60 px-2 py-0.5 rounded border border-border/80"
      >
        <Eye class="size-3" />
        <span>{{ t("clinician.chart_review_mode") }}</span>
      </div>
    </div>

    <div v-else-if="clinicalMode === 'completed'" class="flex items-center gap-1.5 shrink-0 ml-auto">
      <div class="flex items-center gap-1 text-[11px] font-medium text-muted-foreground bg-secondary px-2 py-0.5 rounded border border-border/80">
        <CheckCircle2 class="size-3 text-emerald-600 dark:text-emerald-400" />
        <span>{{ t("clinician.encounter_signed_closed") }}</span>
      </div>
    </div>

    <div v-else class="flex items-center gap-1.5 shrink-0 ml-auto">
      <!--
        Call Patient In / Start Consultation — the action the flow ticket asked
        for. A visit sitting in "Waiting for Doctor" previously had no control
        here at all, so the only way to begin was to start documenting, and the
        patient's badge stayed on "waiting" for every other member of staff.
      -->
      <Button
        v-if="onStartConsultation && isAwaitingConsultationStart"
        type="button"
        size="sm"
        :disabled="isStartingConsultation"
        class="h-6.5 px-2.5 gap-1 text-xs font-semibold bg-primary hover:bg-primary/90 text-primary-foreground cursor-pointer shadow-2xs disabled:opacity-60"
        @click="onStartConsultation"
      >
        <Stethoscope class="size-3" />
        <span>{{
          isStartingConsultation
            ? t("clinician.starting_consultation")
            : t("clinician.start_consultation_action")
        }}</span>
      </Button>

      <!--
        The counterpart signal: once started, the header states plainly that
        this patient is with you, so "am I actually in this consultation?" is
        never something a doctor has to infer from the note being editable.
      -->
      <!--
        Send for Diagnostics — ordering a test and sending the patient to the lab
        are two different acts, and until now only the first had a control. The
        patient stayed "With Doctor" while standing in the lab queue, which kept
        the doctor's room reading occupied and hid the lab as the bottleneck.
      -->
      <Button
        v-if="isWithClinician && onSendForDiagnostics"
        type="button"
        size="sm"
        variant="outline"
        :disabled="isSendingForDiagnostics"
        class="h-6.5 px-2.5 gap-1 text-xs font-semibold cursor-pointer shadow-2xs disabled:opacity-60"
        @click="onSendForDiagnostics"
      >
        <FlaskConical class="size-3" />
        <span>{{
          isSendingForDiagnostics
            ? t("clinician.sending_for_diagnostics")
            : t("clinician.send_for_diagnostics_action")
        }}</span>
      </Button>

      <div
        v-else-if="isWithClinician"
        class="flex items-center gap-1 text-xs font-medium text-success bg-success/10 px-2 py-0.5 rounded border border-success/30"
      >
        <Stethoscope class="size-3 text-success" />
        <span>{{ t("clinician.with_you_now") }}</span>
      </div>

      <!-- Inpatient Admission Order -->
      <Button
        v-if="!isAdmitted"
        type="button"
        variant="secondary"
        size="sm"
        class="h-6.5 px-2 gap-1 text-[11.5px] font-medium text-indigo-700 dark:text-indigo-300 bg-indigo-500/10 hover:bg-indigo-500/20 border border-indigo-500/30 cursor-pointer hidden md:inline-flex"
        @click="onOpenAdmissionDialog"
      >
        <BedDouble class="size-3 text-indigo-600 dark:text-indigo-400" />
        <span>{{ t("clinician.admit_patient") }}</span>
      </Button>

      <!-- Sign & Complete Consultation -->
      <Button
        type="button"
        variant="default"
        size="sm"
        class="h-6.5 px-2.5 gap-1 text-[11.5px] font-semibold bg-emerald-600 hover:bg-emerald-700 text-white cursor-pointer shadow-2xs"
        :disabled="isSigning"
        @click="onSignComplete"
      >
        <FileCheck class="size-3" />
        <span>{{ isSigning ? t("common.loading") : t("clinician.sign_and_complete") }}</span>
      </Button>
    </div>
  </header>
</template>
