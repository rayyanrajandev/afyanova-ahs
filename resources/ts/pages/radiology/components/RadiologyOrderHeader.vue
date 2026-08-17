/**
 * RadiologyOrderHeader — Diagnostic Imaging Patient Header (2027 Standard)
 * =========================================================================
 * Clean, clinical header presenting:
 * - Patient Name, Hospital MRN, Age/Gender
 * - Modality Badge & Study Description
 * - Ordering Clinician & Clinical Indication preview
 * - Acuity / Status Badges & Scheduled Slot Time
 */

<script setup lang="ts">
import {
  CalendarClock,
  Clock,
  FileText,
  ScanLine,
  ShieldCheck,
  Stethoscope,
  User,
} from "lucide-vue-next";
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import { stepBadgeStatus, stepLabelKey } from "@/composables/patientFlowStep";
import { Badge } from "@/components/ui/badge";
import type { RadiologyOrder } from "../composables/useRadiologyOrders";

const props = defineProps<{
  order: RadiologyOrder;
  patientOrders?: RadiologyOrder[];
  onSelectOrder?: (orderId: string) => void;
}>();

const { t } = useI18n({ useScope: "global" });

const visitStageLabel = computed<string | null>(() => {
  const key = stepLabelKey(props.order.visitStage);
  return key ? t(key) : null;
});

const visitStageClass = computed<string>(() => {
  switch (stepBadgeStatus(props.order.visitStage)) {
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

function modalityBadgeClass(modality: string): string {
  switch (modality?.toLowerCase()) {
    case "ultrasound":
    case "us":
      return "border-sky-500/40 text-sky-700 dark:text-sky-300 bg-sky-500/10";
    case "xray":
    case "xr":
      return "border-indigo-500/40 text-indigo-700 dark:text-indigo-300 bg-indigo-500/10";
    case "ct":
      return "border-purple-500/40 text-purple-700 dark:text-purple-300 bg-purple-500/10";
    case "mri":
    case "mr":
      return "border-emerald-500/40 text-emerald-700 dark:text-emerald-300 bg-emerald-500/10";
    case "mammography":
    case "mammo":
      return "border-pink-500/40 text-pink-700 dark:text-pink-300 bg-pink-500/10";
    default:
      return "border-border text-foreground bg-muted";
  }
}

const scheduledLabel = computed<string | null>(() => {
  if (!props.order.scheduledFor) return null;
  try {
    return new Date(props.order.scheduledFor).toLocaleString([], {
      dateStyle: "short",
      timeStyle: "short",
    });
  } catch {
    return null;
  }
});
</script>

<template>
  <div class="shrink-0 border-b border-border bg-surface px-4 py-2.5">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <!-- Left: Patient Avatar, Demographics & Study Info -->
      <div class="flex min-w-0 items-center gap-3">
        <div class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary border border-primary/20 shadow-2xs">
          <ScanLine class="size-5" />
        </div>

        <div class="min-w-0 space-y-0.5">
          <!-- Row 1: Patient Name & Core Badges -->
          <div class="flex flex-wrap items-center gap-2">
            <h2 class="truncate text-sm font-bold text-foreground tracking-tight">
              {{ props.order.patientName || t('radiology.unknown_patient', 'Patient') }}
            </h2>
            <span class="rounded bg-primary/10 px-1.5 py-0 font-mono text-[11px] font-bold text-primary">
              {{ props.order.patientMrn }}
            </span>
            <span v-if="props.order.patientGender || props.order.patientAge" class="font-mono text-xs text-muted-foreground">
              {{ props.order.patientGender }}<template v-if="props.order.patientAge"> · {{ props.order.patientAge }}</template>
            </span>

            <!-- Acuity Badges -->
            <Badge
              v-if="props.order.priority === 'stat'"
              variant="outline"
              class="animate-pulse border-rose-500/50 bg-rose-500/15 px-1.5 py-0 font-mono text-[9.5px] font-bold uppercase text-rose-600 dark:text-rose-400"
            >
              {{ t('radiology.priority_stat', 'STAT') }}
            </Badge>
            <Badge
              v-else-if="props.order.priority === 'urgent'"
              variant="outline"
              class="border-amber-500/50 bg-amber-500/15 px-1.5 py-0 font-mono text-[9.5px] font-bold uppercase text-amber-600 dark:text-amber-400"
            >
              {{ t('radiology.priority_urgent', 'URGENT') }}
            </Badge>
          </div>

          <!-- Row 2: Modality, Study Description & Ordering Doctor -->
          <div class="flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
            <span
              class="px-1.5 py-0 rounded font-mono text-[10px] font-extrabold uppercase"
              :class="modalityBadgeClass(props.order.modality)"
            >
              {{ props.order.modality }}
            </span>
            <span class="font-bold text-foreground">{{ props.order.studyDescription }}</span>
            <span v-if="props.order.orderingClinician" class="inline-flex items-center gap-1 text-[11px] text-muted-foreground">
              <Stethoscope class="size-3 text-primary/70" />
              {{ props.order.orderingClinician }}
            </span>
          </div>
        </div>
      </div>

      <!-- Right: Status / Slot Badges -->
      <div class="flex shrink-0 flex-wrap items-center gap-2">
        <!-- Scheduled Time Slot -->
        <Badge
          v-if="scheduledLabel"
          variant="outline"
          class="gap-1 border-blue-500/40 bg-blue-500/10 px-2 py-0.5 font-mono text-[10.5px] text-blue-600 dark:text-blue-400"
        >
          <Clock class="size-3" />
          {{ scheduledLabel }}
        </Badge>

        <!-- Visit Stage -->
        <Badge
          v-if="visitStageLabel"
          variant="outline"
          class="px-2 py-0.5 text-[10px] font-semibold uppercase"
          :class="visitStageClass"
        >
          {{ visitStageLabel }}
        </Badge>

        <!-- Released Badge -->
        <Badge
          v-if="props.order.verifiedAt"
          variant="outline"
          class="gap-1 border-emerald-500/40 bg-emerald-500/10 px-2 py-0.5 text-[10px] font-semibold uppercase text-emerald-700 dark:text-emerald-300"
        >
          <ShieldCheck class="size-3" />
          {{ t('radiology.verified_released', 'Released') }}
        </Badge>
      </div>
    </div>
  </div>
</template>
