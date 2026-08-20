/**
 * LabOrderHeader — Laboratory Main-Pane Patient & Investigation Banner (Volume 2.4)
 * =================================================================================
 * 2027 Modern Enterprise Clinical LIS Header:
 * - Band 1 (Patient & Priority): Patient Demographics (Name, MRN, Age/Gender) + STAT/Routine + Workflow Status + Print
 * - Band 2 (Specimen & Clinical Context): Test Name + Sample Tube Type + Barcode Accession # + Ordering Clinician
 * - Clear Visual Hierarchy (No disconnected dots, no badge clutter, instant recognition)
 * - Full Internationalization (i18n) Support
 */

<script setup lang="ts">
import {
  AlertTriangle,
  Barcode,
  Clock,
  FlaskConical,
  Printer,
  Stethoscope,
  TestTube2,
  User,
} from "lucide-vue-next";
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import { stepBadgeStatus, stepLabelKey } from "@/composables/patientFlowStep";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { printConsolidatedLaboratoryReport } from "../laboratoryReportPrint";
import {
  labStageOf,
  type LabStage,
  type LaboratoryOrder,
} from "../composables/useLaboratoryOrders";

const props = defineProps<{
  order: LaboratoryOrder;
  patientOrders?: LaboratoryOrder[];
  onSelectOrder?: (orderId: string) => void;
}>();

const { t } = useI18n({ useScope: "global" });

const stage = computed<LabStage>(() => labStageOf(props.order));

function handlePrintReport() {
  const testsToPrint =
    props.patientOrders && props.patientOrders.length > 0
      ? props.patientOrders
      : [props.order];
  printConsolidatedLaboratoryReport(testsToPrint);
}

const STAGE_LABELS: Record<LabStage, { key: string; fallback: string }> = {
  awaiting_specimen: {
    key: "laboratory.awaiting_sample",
    fallback: "Awaiting Sample",
  },
  ready_for_analysis: {
    key: "laboratory.specimen_received",
    fallback: "Specimen Received",
  },
  in_analysis: { key: "laboratory.in_analysis", fallback: "In Analysis" },
  awaiting_release: {
    key: "laboratory.draft_unreleased",
    fallback: "Draft — Not Released",
  },
  released: {
    key: "laboratory.verified_released",
    fallback: "Verified & Released",
  },
  rejected: { key: "laboratory.sample_rejected", fallback: "Sample Rejected" },
};

const stageLabel = computed(() =>
  t(STAGE_LABELS[stage.value].key, STAGE_LABELS[stage.value].fallback),
);

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
</script>

<template>
  <header class="flex shrink-0 flex-col border-b border-border bg-surface px-4 py-2 rounded-t-lg divide-y divide-border/40">
    <!-- Band 1: Patient Identity & Priority Row -->
    <div class="flex flex-wrap items-center justify-between gap-2 pb-1.5">
      <!-- Patient Demographics -->
      <div class="flex items-center gap-2.5 min-w-0">
        <div class="flex size-7 shrink-0 items-center justify-center rounded-md bg-primary/10 text-primary font-bold">
          <User class="size-4" />
        </div>
        <div class="flex items-center gap-2 flex-wrap min-w-0">
          <h2 class="text-xs font-bold text-foreground truncate">
            {{ order.patientName }}
          </h2>
          <span class="font-mono text-[11px] font-semibold text-primary bg-primary/10 px-1.5 py-0.2 rounded">
            MRN: {{ order.patientMrn }}
          </span>
          <span class="text-[11px] text-muted-foreground font-mono">
            {{ order.patientGender }} · {{ order.patientAge }}
          </span>
        </div>
      </div>

      <!-- Priority, Stage & Print Action -->
      <div class="flex items-center gap-2 shrink-0">
        <!-- Priority Flag -->
        <Badge
          v-if="order.priority === 'stat'"
          variant="outline"
          class="bg-rose-500/15 border-rose-500/50 text-rose-600 font-mono font-bold text-[9.5px] uppercase px-1.5 py-0.2 animate-pulse gap-1"
        >
          <AlertTriangle class="size-3" />
          {{ t("laboratory.stat_priority", "STAT") }}
        </Badge>
        <Badge
          v-else-if="order.priority === 'urgent'"
          variant="outline"
          class="bg-amber-500/15 border-amber-500/50 text-amber-600 font-mono font-bold text-[9.5px] uppercase px-1.5 py-0.2 gap-1"
        >
          <Clock class="size-3" />
          {{ t("laboratory.urgent_priority", "URGENT") }}
        </Badge>
        <span
          v-else
          class="text-[9.5px] font-mono uppercase px-1.5 py-0.2 rounded bg-muted text-muted-foreground font-medium"
        >
          {{ t("laboratory.routine_priority", "ROUTINE") }}
        </span>

        <!-- Visit Stage -->
        <Badge
          v-if="visitStageLabel"
          variant="outline"
          class="text-[9.5px] font-medium uppercase px-1.5 py-0.2"
          :class="visitStageClass"
        >
          {{ visitStageLabel }}
        </Badge>

        <!-- Bench Stage -->
        <span
          class="text-[9.5px] font-mono uppercase px-1.5 py-0.2 rounded font-semibold"
          :class="{
            'text-amber-700 dark:text-amber-300 bg-amber-500/15': stage === 'awaiting_specimen',
            'text-blue-700 dark:text-blue-300 bg-blue-500/15': stage === 'ready_for_analysis',
            'text-purple-700 dark:text-purple-300 bg-purple-500/15': stage === 'in_analysis',
            'text-sky-700 dark:text-sky-300 bg-sky-500/15': stage === 'awaiting_release',
            'text-emerald-700 dark:text-emerald-300 bg-emerald-500/15': stage === 'released',
            'text-rose-700 dark:text-rose-300 bg-rose-500/15': stage === 'rejected',
          }"
        >
          {{ stageLabel }}
        </span>

        <!-- Print Action -->
        <Button
          type="button"
          variant="outline"
          size="sm"
          class="h-6.5 text-[11px] font-medium gap-1 px-2 border-border/80 hover:bg-muted cursor-pointer ml-1"
          :title="t('laboratory.print_report_tooltip', 'Print diagnostic laboratory report')"
          @click="handlePrintReport"
        >
          <Printer class="size-3 text-primary" />
          <span>{{ t("laboratory.print_report", "Print") }}</span>
        </Button>
      </div>
    </div>

    <!-- Band 2: Specimen & Clinical Investigation Context Row -->
    <div class="flex flex-wrap items-center justify-between gap-2 pt-1.5 text-xs">
      <!-- Test Name, Specimen Type & Barcode -->
      <div class="flex items-center gap-2.5 min-w-0 flex-wrap">
        <div class="flex items-center gap-1.5">
          <FlaskConical class="size-3.5 text-primary shrink-0" />
          <span class="font-bold text-foreground text-[12px]">{{ order.testName }}</span>
        </div>

        <div class="flex items-center gap-1 text-[11px] text-muted-foreground font-medium bg-muted/40 px-1.5 py-0.2 rounded">
          <TestTube2 class="size-3 text-muted-foreground" />
          <span>{{ order.sampleType || "Specimen" }}</span>
        </div>

        <div class="flex items-center gap-1 font-mono text-[11px] text-foreground bg-muted/40 px-1.5 py-0.2 rounded">
          <Barcode class="size-3 text-muted-foreground" />
          <span>{{ order.orderNumber }}</span>
        </div>
      </div>

      <!-- Ordering Clinician & Clinical Indication -->
      <div class="flex items-center gap-2 text-[11px] text-muted-foreground min-w-0">
        <div class="flex items-center gap-1 shrink-0">
          <Stethoscope class="size-3 text-primary shrink-0" />
          <span class="font-medium text-foreground">{{ order.orderingClinician }}</span>
        </div>
        <span
          v-if="order.clinicalIndication"
          class="text-muted-foreground/80 truncate max-w-xs"
          :title="order.clinicalIndication"
        >
          · {{ order.clinicalIndication }}
        </span>
      </div>
    </div>
  </header>
</template>

