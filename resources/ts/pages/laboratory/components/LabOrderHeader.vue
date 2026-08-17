/**
 * LabOrderHeader — Laboratory Main-Pane Patient & Investigation Banner (Volume 2.4)
 * =================================================================================
 * 2027 Modern Enterprise Clinical LIS Header:
 * - Patient Demographics & Identification
 * - Multi-Test Encounter Switcher for the Active Patient
 * - Specimen Barcode & Accession Identifier
 * - Priority Badge (STAT / Urgent / Routine) & Order Status Tracker
 * - Ordering Clinician & Clinical Indication Callout
 * - Full Internationalization (i18n) Support
 */

<script setup lang="ts">
import {
  AlertTriangle,
  Barcode,
  Clock,
  FlaskConical,
  HeartPulse,
  Send,
  Sparkles,
  Stethoscope,
  TestTube2,
  User,
} from "lucide-vue-next";
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import { stepBadgeStatus, stepLabelKey } from "@/composables/patientFlowStep";
import { Badge } from "@/components/ui/badge";
import { labStageOf, type LabStage, type LaboratoryOrder } from "../composables/useLaboratoryOrders";

const props = defineProps<{
  order: LaboratoryOrder;
  patientOrders?: LaboratoryOrder[];
  onSelectOrder?: (orderId: string) => void;
}>();

const { t } = useI18n({ useScope: "global" });

const stage = computed<LabStage>(() => labStageOf(props.order));

const STAGE_LABELS: Record<LabStage, { key: string; fallback: string }> = {
  awaiting_specimen: { key: "laboratory.awaiting_sample", fallback: "Awaiting Sample" },
  ready_for_analysis: { key: "laboratory.specimen_received", fallback: "Specimen Received" },
  in_analysis: { key: "laboratory.in_analysis", fallback: "In Analysis" },
  awaiting_release: { key: "laboratory.draft_unreleased", fallback: "Draft — Not Released" },
  released: { key: "laboratory.verified_released", fallback: "Verified & Released" },
  rejected: { key: "laboratory.sample_rejected", fallback: "Sample Rejected" },
};

const stageLabel = computed(() =>
  t(STAGE_LABELS[stage.value].key, STAGE_LABELS[stage.value].fallback),
);

/**
 * Where the patient stands in the whole visit — separate from this order's own
 * lifecycle status beside it. A technician looking at a completed result needs
 * to know whether the doctor is still holding a consultation open for it or the
 * patient went home. Read from the shared step vocabulary, never a local rule.
 */
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
  <header class="flex shrink-0 flex-col gap-2 border-b border-border bg-surface px-4 py-2.5 rounded-t-lg">
    <div class="flex flex-wrap items-center justify-between gap-3">
      
      <!-- Patient & Test Info -->
      <div class="flex items-center gap-3 min-w-0">
        <div class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary font-bold text-sm">
          <FlaskConical class="size-5" />
        </div>

        <div class="min-w-0">
          <div class="flex items-center gap-2 flex-wrap">
            <h2 class="text-sm font-bold text-foreground truncate">
              {{ order.patientName }}
            </h2>
            <span class="font-mono text-xs font-semibold text-primary bg-primary/10 px-1.5 py-0.2 rounded">
              {{ order.patientMrn }}
            </span>
            <span class="text-xs text-muted-foreground font-mono">
              {{ order.patientGender }} · {{ order.patientAge }}
            </span>
          </div>

          <div class="flex items-center gap-2 mt-0.5 text-xs text-muted-foreground">
            <span class="font-bold text-foreground">{{ order.testName }}</span>
            <span class="font-mono text-[11px] bg-secondary px-1.5 py-0 rounded text-muted-foreground">{{ order.testCode }}</span>
            <span>·</span>
            <span class="text-[11.5px]">{{ order.department }}</span>
          </div>
        </div>
      </div>

      <!-- Priority & Status Badges & Quick Action -->
      <div class="flex items-center gap-2 shrink-0 flex-wrap">
        <!-- Priority Flag -->
        <Badge
          v-if="order.priority === 'stat'"
          variant="outline"
          class="bg-rose-500/15 border-rose-500/50 text-rose-600 font-mono font-bold text-[10px] uppercase px-2 py-0.5 animate-pulse gap-1"
        >
          <AlertTriangle class="size-3" />
          {{ t('laboratory.stat_priority', 'STAT (CRITICAL)') }}
        </Badge>
        <Badge
          v-else-if="order.priority === 'urgent'"
          variant="outline"
          class="bg-amber-500/15 border-amber-500/50 text-amber-600 font-mono font-bold text-[10px] uppercase px-2 py-0.5 gap-1"
        >
          <Clock class="size-3" />
          {{ t('laboratory.urgent_priority', 'URGENT') }}
        </Badge>
        <Badge
          v-else
          variant="outline"
          class="bg-secondary text-muted-foreground font-mono text-[10px] uppercase px-2 py-0.5"
        >
          {{ t('laboratory.routine_priority', 'ROUTINE') }}
        </Badge>

        <!-- Where the patient is in the visit, distinct from this order's status -->
        <Badge
          v-if="visitStageLabel"
          variant="outline"
          class="text-[10px] font-semibold uppercase px-2 py-0.5"
          :class="visitStageClass"
        >
          {{ visitStageLabel }}
        </Badge>

        <!--
          Bench stage badge. This reads from labStageOf(), so "Verified &
          Released" can no longer appear on an order that merely has results
          typed into it — `completed` alone never meant released.
        -->
        <Badge
          variant="outline"
          class="text-[10px] font-mono uppercase px-2 py-0.5"
          :class="{
            'border-amber-500/40 text-amber-600 bg-amber-500/10': stage === 'awaiting_specimen',
            'border-blue-500/40 text-blue-600 bg-blue-500/10': stage === 'ready_for_analysis',
            'border-purple-500/40 text-purple-600 bg-purple-500/10': stage === 'in_analysis',
            'border-sky-500/40 text-sky-600 bg-sky-500/10': stage === 'awaiting_release',
            'border-emerald-500/40 text-emerald-600 bg-emerald-500/10': stage === 'released',
            'border-rose-500/40 text-rose-600 bg-rose-500/10': stage === 'rejected',
          }"
        >
          {{ stageLabel }}
        </Badge>
      </div>
    </div>



    <!-- Bottom Detail Strip: Clinician Indication & Barcode -->
    <div class="flex flex-wrap items-center justify-between gap-2 pt-1.5 border-t border-border/60 text-xs">
      <div class="flex items-center gap-2 min-w-0 text-muted-foreground">
        <Stethoscope class="size-3.5 text-primary shrink-0" />
        <span class="font-medium text-foreground">{{ order.orderingClinician }}</span>
        <span>·</span>
        <span class="italic text-[11px] truncate">"{{ order.clinicalIndication || t('clinician.clinical_indication', 'Routine diagnostic order') }}"</span>
      </div>

      <div class="flex items-center gap-2 font-mono text-[11px] text-muted-foreground">
        <Barcode class="size-3.5 text-muted-foreground/70" />
        <span class="font-semibold text-foreground">{{ order.orderNumber }}</span>
        <span class="text-[10px]">({{ order.sampleType }})</span>
      </div>
    </div>
  </header>
</template>
