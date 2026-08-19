/**
 * PatientHeader — Nursing Main-Pane Patient Header (Volume 2.3 §4.2)
 * =========================================================================
 * Upgraded to 2027 Enterprise Clinical Standard:
 * - High-contrast patient context banner (MRN, Age/Gender, Contact, Journey stage)
 * - Pinned safety & payer clearance indicators (Critical Allergies, Insurance, Notes)
 * - Anti-bloat Action Hierarchy (1 Primary Action + Actions Hub Menu + Close)
 */

<script setup lang="ts">
import {
  Activity,
  ArrowLeft,
  Building2,
  CalendarOff,
  CheckCircle2,
  ChevronDown,
  CircleCheck,
  ClipboardList,
  FileText,
  HandHeart,
  Phone,
  Pill,
  ShieldCheck,
  TriangleAlert,
  User,
  X,
} from "lucide-vue-next";
import { PopoverClose } from "reka-ui";
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import { Avatar, AvatarFallback } from "@/components/ui/avatar";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from "@/components/ui/popover";
import type { Patient, PatientAllergySummary } from "@/stores/patientStore";
import type { ReadinessContext, VisitContext } from "@/stores/queueStore";
import VisitNotesDialog from "./VisitNotesDialog.vue";

const props = defineProps<{
  patient: Patient;
  encounterId: string | null;
  allergies: PatientAllergySummary[];
  isLoadingAllergies: boolean;
  hasEncounter: boolean;
  visit: VisitContext | null;
  readiness?: ReadinessContext | null;
  displayName: (patient: Patient) => string;
  initials: (name: string) => string;
  /** True while this nurse has the patient picked up (2026-08-16 flow audit). */
  hasPatientInContact?: boolean;
  isUpdatingContact?: boolean;
}>();

const emit = defineEmits<{
  (e: "openVitals"): void;
  (e: "openAssessment"): void;
  (e: "openNotes"): void;
  (e: "openAdmission"): void;
  (e: "returnToReception"): void;
  (e: "toggleMar"): void;
  (e: "deselect"): void;
  (e: "claimPatient"): void;
  (e: "releasePatient"): void;
}>();

const { t } = useI18n();
const showNotesDialog = ref(false);

function onNotesUpdated(updatedNotes: string) {
  if (props.readiness) {
    props.readiness.verificationNotes = updatedNotes;
  }
}

/**
 * Whether triage vitals have been recorded for THIS visit.
 *
 * Read from the appointment's own status rather than from the vitals store:
 * `vitalsStore.latest` is the newest set for the *patient*, which may be from a
 * previous visit entirely, and would wrongly retire the "Record Vitals" action
 * for someone who has not been triaged today.
 *
 * Recording triage vitals is exactly what advances the appointment past
 * waiting_triage (PatientVitalSetController hands off through
 * RecordAppointmentTriageUseCase), so the status is a precise, server-owned
 * answer. It also cannot be confused by the nursing pickup: opening the vitals
 * form claims the patient and moves the *step* to with_nurse, but leaves the
 * status at waiting_triage until vitals are actually saved.
 */
const hasRecordedTriageVitals = computed<boolean>(() => {
  const status = props.visit?.appointmentStatus;
  if (!status) return false;

  return status !== "waiting_triage" && status !== "scheduled";
});

/**
 * Whether this patient is actually on a visit right now.
 *
 * The distinction this component was missing. Selecting someone from the
 * Patients tab resolves their active visit through
 * `GET nursing/active-visit/{id}`, and for a patient who is not here today
 * that resolves to null — at which point `hasRecordedTriageVitals` is false
 * for the same reason it is false for someone standing in the triage queue.
 *
 * Those are opposite situations. One means act now; the other means this
 * person is not in the building. Reading them as the same is why the Tasks
 * tab could be empty while every clinical action sat live on the header of a
 * patient with no visit at all.
 */
const hasActiveVisit = computed<boolean>(() => props.visit !== null);

// Patient's journey context (e.g. "Walk-in OPD · In Triage")
const visitLabel = computed<string | null>(() => {
  const visit = props.visit;
  if (!visit) return null;

  const parts: string[] = [];
  const category = visit.visitCategory
    ? t(`nursing.visit_category_${visit.visitCategory}`)
    : visit.arrivalMode
      ? t(`nursing.arrival_${visit.arrivalMode}`)
      : null;
  if (category) parts.push(category);
  if (visit.stage) parts.push(t(`nursing.stage_${visit.stage}`));

  return parts.length > 0 ? parts.join(" · ") : null;
});

const patientPhone = computed(() => {
  return props.patient.telecom?.find((t2) => t2.system === "phone")?.value ?? null;
});
</script>

<template>
  <header class="border-b border-border bg-surface px-4 py-2 shrink-0 flex items-center justify-between gap-3 overflow-hidden rounded-t-lg">
    <!-- Left Identity & Safety Block -->
    <div class="flex items-center gap-3 min-w-0 flex-1 overflow-hidden">
      <Avatar class="size-9 shrink-0 border border-primary/20 bg-primary/5">
        <AvatarFallback class="text-xs font-bold text-primary">
          {{ initials(displayName(patient)) }}
        </AvatarFallback>
      </Avatar>

      <div class="min-w-0 space-y-0.5 flex-1 overflow-hidden">
        <!-- Row 1: Name, MRN, Active Encounter Dot, Journey Badge -->
        <div class="flex items-center gap-2 min-w-0 overflow-hidden">
          <h1 class="truncate text-sm font-bold tracking-tight text-foreground">
            {{ displayName(patient) }}
          </h1>
          <span class="font-mono text-xs font-medium text-muted-foreground bg-secondary px-1.5 py-0.2 rounded border border-border/60 shrink-0">
            {{ patient.identifier[0]?.value }}
          </span>
          <span
            class="size-1.5 shrink-0 rounded-full"
            :class="encounterId ? 'bg-success' : 'bg-muted-foreground/50'"
            :title="encounterId ? 'Active Encounter' : 'No Active Encounter'"
            aria-hidden="true"
          />

          <!-- Journey Stage Badge -->
          <Badge
            v-if="visitLabel"
            variant="secondary"
            class="gap-1 text-[10.5px] px-2 py-0.2 font-medium border border-border shrink-0"
          >
            {{ visitLabel }}
          </Badge>
        </div>

        <!-- Row 2: Demographics, Phone, Insurance Status, Allergy Safety Flag, Communication Notes -->
        <div class="flex items-center gap-2.5 text-xs text-muted-foreground min-w-0 overflow-hidden">
          <span class="shrink-0">{{ t("patient.age_display", { age: patient.meta.extension.age }) }} · {{ t(`patient.gender_${patient.gender}`) }}</span>
          <span v-if="patientPhone" class="font-mono shrink-0 hidden sm:inline">
            {{ patientPhone }}
          </span>

          <!-- Payer & Insurance Status -->
          <div class="flex items-center gap-1 border-l border-border/80 pl-2 min-w-0 shrink-0">
            <template v-if="readiness?.insuranceVerified === false">
              <span class="inline-flex items-center gap-1 text-[11px] font-semibold text-warning max-w-[180px] truncate">
                <TriangleAlert class="size-3 shrink-0" aria-hidden="true" />
                <span class="truncate">{{ readiness.insuranceProvider ? t("nursing.insurance_unverified_with_provider", { provider: readiness.insuranceProvider }) : t("nursing.insurance_unverified") }}</span>
              </span>
            </template>
            <template v-else-if="readiness?.coverageType === 'self_pay'">
              <span class="inline-flex items-center gap-1 text-[11px] font-medium text-muted-foreground shrink-0">
                <User class="size-3 shrink-0" />
                {{ t("nursing.self_pay") }}
              </span>
            </template>
            <template v-else-if="readiness?.insuranceVerified === true">
              <span class="inline-flex items-center gap-1 text-[11px] font-semibold text-emerald-600 dark:text-emerald-400 max-w-[180px] truncate">
                <ShieldCheck class="size-3.5 shrink-0" aria-hidden="true" />
                <span class="truncate">{{ readiness.insuranceProvider || t("insurance.insurance") }} ({{ t("insurance.verified") }})</span>
              </span>
            </template>
          </div>

          <!-- Critical Allergy Safety Indicator -->
          <div class="flex items-center gap-1 border-l border-border/80 pl-2 shrink-0">
            <span
              v-if="allergies.length > 0"
              class="inline-flex items-center gap-1 rounded bg-critical/15 px-1.5 py-0.2 text-[10.5px] font-bold text-critical"
            >
              <TriangleAlert class="size-3" />
              {{ t("patient.allergies_count", { count: allergies.length }) }}
            </span>
            <span
              v-else
              class="inline-flex items-center gap-1 text-[10.5px] font-medium text-emerald-600 dark:text-emerald-400"
            >
              <CheckCircle2 class="size-3" />
              {{ t("patient.no_allergies") }}
            </span>
          </div>

          <!-- Notes Trigger -->
          <div class="flex items-center gap-1 border-l border-border/80 pl-2 min-w-0 shrink-0">
            <button
              type="button"
              class="inline-flex items-center gap-1 text-[11px] text-primary hover:underline cursor-pointer max-w-[140px] truncate"
              :title="t('nursing.visit_communication_notes')"
              @click="showNotesDialog = true"
            >
              <FileText class="size-3 shrink-0" />
              <span class="truncate">{{ readiness?.verificationNotes ? readiness.verificationNotes : `💬 ${t('nursing.visit_notes')}` }}</span>
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Right Action Block (1 Primary + Actions Hub + Close) -->
    <div class="flex items-center gap-1.5 shrink-0 ml-auto">
      <!--
        One advancing primary action.

        Before triage vitals exist it is "Record Vitals" — the single thing this
        queue is asking for. Once they are recorded it becomes "Done With
        Patient", because the completed step must stop occupying the slot that
        answers "what now?".

        It does NOT force a sequence: continuing with an assessment, a note, the
        MAR or a retake are all one click away in the Actions hub beside it, and
        stay equally available either way. The primary only ever expresses the
        most common completion, never the only permitted path.
      -->
      <Button
        v-if="!hasRecordedTriageVitals && hasActiveVisit"
        size="sm"
        class="h-7 inline-flex items-center gap-1.5 shadow-xs font-medium cursor-pointer text-xs"
        @click="emit('openVitals')"
      >
        <Activity class="size-3.5" aria-hidden="true" />
        <span>{{ t("nursing.record_vitals") }}</span>
      </Button>

      <!--
        No visit today. Vitals stay reachable and nothing else does: a nurse
        taking observations on someone who collapsed in the corridor, or before
        registration has finished, is exactly the moment not to be blocked, and
        the API allows it deliberately. Everything else on this header belongs
        to a visit that does not exist.
      -->
      <Button
        v-else-if="!hasActiveVisit"
        variant="outline"
        size="sm"
        class="h-7 inline-flex items-center gap-1.5 text-xs font-medium cursor-pointer"
        @click="emit('openVitals')"
      >
        <Activity class="size-3.5" aria-hidden="true" />
        <span>{{ t("nursing.record_vitals") }}</span>
      </Button>
      <Button
        v-else-if="hasActiveVisit && hasEncounter && hasPatientInContact"
        size="sm"
        :disabled="isUpdatingContact"
        class="h-7 inline-flex items-center gap-1.5 shadow-xs font-medium cursor-pointer text-xs disabled:opacity-60"
        @click="emit('releasePatient')"
      >
        <HandHeart class="size-3.5" aria-hidden="true" />
        <span>{{ t("nursing.release_patient") }}</span>
      </Button>
      <Button
        v-else
        variant="outline"
        size="sm"
        class="h-7 inline-flex items-center gap-1.5 text-xs font-medium cursor-pointer"
        @click="emit('openVitals')"
      >
        <Activity class="size-3.5" aria-hidden="true" />
        <span>{{ t("nursing.retake_vitals") }}</span>
      </Button>

      <!-- Says why the rest of the header is missing, rather than leaving a gap. -->
      <span
        v-if="!hasActiveVisit"
        class="inline-flex items-center gap-1.5 rounded-md border border-border/70 px-2 py-1 text-xs text-muted-foreground"
      >
        <CalendarOff class="size-3.5" aria-hidden="true" />
        {{ t("nursing.no_active_visit") }}
      </span>

      <!--
        Hand the patient back. There is deliberately no matching "start"
        button: claiming happens automatically when the nurse opens the vitals
        or assessment form (see beginNursingContact in nursing/Index.vue).
        Offering both left a nurse choosing between two unranked primary
        actions — "Record Vitals" and "Start With Patient" — with nothing
        saying which came first, and made it possible to do the work without
        ever recording that a nurse was with the patient.

        Ending contact keeps an explicit control because nothing else marks it:
        the nurse walking away leaves no signal for the system to observe.
      -->
      <Button
        v-if="hasActiveVisit && hasEncounter && hasPatientInContact && !hasRecordedTriageVitals"
        variant="outline"
        size="sm"
        :disabled="isUpdatingContact"
        class="h-7 inline-flex items-center gap-1.5 text-xs font-medium cursor-pointer border-success/40 bg-success/10 text-success disabled:opacity-60"
        @click="emit('releasePatient')"
      >
        <HandHeart class="size-3.5" aria-hidden="true" />
        <span>{{ t("nursing.release_patient") }}</span>
      </Button>

      <!-- Actions Dropdown Hub — every item inside belongs to a live visit. -->
      <Popover v-if="hasActiveVisit">
        <PopoverTrigger as-child>
          <Button
            variant="outline"
            size="sm"
            class="h-7 inline-flex items-center gap-1 text-xs text-muted-foreground hover:text-foreground cursor-pointer font-medium"
          >
            <span>{{ t('common.actions') }}</span>
            <ChevronDown class="size-3 opacity-70" aria-hidden="true" />
          </Button>
        </PopoverTrigger>
        <PopoverContent class="w-56 p-1" align="end">
          <!--
            Retaking vitals is demoted here once triage vitals exist, never
            removed: a deteriorating patient is a real reason to take them
            again, it simply is not the *next* step, and the primary slot is
            scarce. This keeps it reachable when the Recent Vitals panel is not
            on screen.
          -->
          <PopoverClose v-if="hasRecordedTriageVitals" as-child>
            <button
              type="button"
              class="focus-ring flex w-full items-center gap-2 rounded-sm px-2.5 py-2 text-left text-xs text-foreground transition-colors hover:bg-muted cursor-pointer"
              @click="emit('openVitals')"
            >
              <Activity class="size-3.5 text-muted-foreground" aria-hidden="true" />
              {{ t("nursing.retake_vitals") }}
            </button>
          </PopoverClose>
          <PopoverClose as-child>
            <button
              type="button"
              class="focus-ring flex w-full items-center gap-2 rounded-sm px-2.5 py-2 text-left text-xs text-foreground transition-colors hover:bg-muted cursor-pointer"
              @click="emit('openAssessment')"
            >
              <ClipboardList class="size-3.5 text-muted-foreground" aria-hidden="true" />
              {{ t("nursing.new_assessment") }}
            </button>
          </PopoverClose>
          <PopoverClose as-child>
            <button
              type="button"
              class="focus-ring flex w-full items-center gap-2 rounded-sm px-2.5 py-2 text-left text-xs text-foreground transition-colors hover:bg-muted cursor-pointer"
              @click="emit('openNotes')"
            >
              <FileText class="size-3.5 text-muted-foreground" aria-hidden="true" />
              {{ t("nursing.new_note") }}
            </button>
          </PopoverClose>
          <PopoverClose as-child>
            <button
              type="button"
              class="focus-ring flex w-full items-center gap-2 rounded-sm px-2.5 py-2 text-left text-xs text-foreground transition-colors hover:bg-muted cursor-pointer"
              @click="emit('toggleMar')"
            >
              <Pill class="size-3.5 text-muted-foreground" aria-hidden="true" />
              {{ t("nursing.mar") }}
            </button>
          </PopoverClose>
          <PopoverClose v-if="!visit?.isAdmitted" as-child>
            <button
              type="button"
              class="focus-ring flex w-full items-center gap-2 rounded-sm px-2.5 py-2 text-left text-xs text-foreground transition-colors hover:bg-muted cursor-pointer"
              @click="emit('openAdmission')"
            >
              <Building2 class="size-3.5 text-muted-foreground" aria-hidden="true" />
              {{ t("nursing.escalate_admission") }}
            </button>
          </PopoverClose>
          <div class="my-1 border-t border-border" />
          <PopoverClose as-child>
            <button
              type="button"
              class="focus-ring flex w-full items-center gap-2 rounded-sm px-2.5 py-2 text-left text-xs text-foreground transition-colors hover:bg-muted cursor-pointer"
              @click="showNotesDialog = true"
            >
              <FileText class="size-3.5 text-primary" aria-hidden="true" />
              {{ t("nursing.visit_communication_notes") }}
            </button>
          </PopoverClose>
          <PopoverClose as-child>
            <button
              type="button"
              class="focus-ring flex w-full items-center gap-2 rounded-sm px-2.5 py-2 text-left text-xs text-foreground transition-colors hover:bg-muted cursor-pointer"
              @click="emit('returnToReception')"
            >
              <ArrowLeft class="size-3.5 text-muted-foreground" aria-hidden="true" />
              {{ t("nursing.return_to_reception") }}
            </button>
          </PopoverClose>
        </PopoverContent>
      </Popover>

      <span class="mx-0.5 h-4 w-px bg-border shrink-0" aria-hidden="true" />

      <!-- Close/Deselect -->
      <Button
        variant="ghost"
        size="icon"
        class="size-7 text-muted-foreground hover:text-foreground cursor-pointer shrink-0"
        :title="t('common.close')"
        @click="emit('deselect')"
      >
        <X class="size-3.5" aria-hidden="true" />
      </Button>
    </div>
  </header>

  <!-- Visit Communication Notes Dialog -->
  <VisitNotesDialog
    v-model:open="showNotesDialog"
    :appointment-id="visit?.appointmentId ?? null"
    :verification-notes="readiness?.verificationNotes"
    @note-added="onNotesUpdated"
  />
</template>
