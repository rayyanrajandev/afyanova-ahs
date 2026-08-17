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
  CheckCircle2,
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
import { Button } from "@/components/ui/button";
import type { LaboratoryOrder } from "../composables/useLaboratoryOrders";

const props = defineProps<{
  order: LaboratoryOrder;
  patientOrders?: LaboratoryOrder[];
  onSelectOrder?: (orderId: string) => void;
  onVerify?: () => void;
  isVerifying?: boolean;
}>();

const { t } = useI18n({ useScope: "global" });

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
      <div class="flex items-center gap-2 shrink-0">
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

        <!-- Lifecycle Status Badge -->
        <Badge
          variant="outline"
          class="text-[10px] font-mono uppercase px-2 py-0.5"
          :class="{
            'border-amber-500/40 text-amber-600 bg-amber-500/10': order.status === 'ordered',
            'border-blue-500/40 text-blue-600 bg-blue-500/10': order.status === 'sample_collected',
            'border-purple-500/40 text-purple-600 bg-purple-500/10': order.status === 'in_progress',
            'border-emerald-500/40 text-emerald-600 bg-emerald-500/10': order.status === 'completed',
            'border-rose-500/40 text-rose-600 bg-rose-500/10': order.status === 'cancelled',
          }"
        >
          <span v-if="order.status === 'ordered'">{{ t('laboratory.awaiting_sample', 'Awaiting Sample') }}</span>
          <span v-else-if="order.status === 'sample_collected'">{{ t('laboratory.specimen_received', 'Specimen Received') }}</span>
          <span v-else-if="order.status === 'in_progress'">{{ t('laboratory.in_analysis', 'In Analysis') }}</span>
          <span v-else-if="order.status === 'completed'">{{ t('laboratory.verified_released', 'Verified & Released') }}</span>
          <span v-else-if="order.status === 'cancelled'">{{ t('laboratory.sample_rejected', 'Sample Rejected') }}</span>
        </Badge>

        <Button
          v-if="order.status !== 'completed' && order.status !== 'cancelled' && onVerify"
          size="sm"
          class="h-7 text-xs font-semibold gap-1 px-3 cursor-pointer shadow-xs"
          :disabled="isVerifying"
          @click="onVerify"
        >
          <CheckCircle2 class="size-3.5" />
          <span>{{ isVerifying ? t('laboratory.verifying', 'Verifying...') : t('laboratory.verify_action', 'Verify & Release') }}</span>
        </Button>
      </div>
    </div>

    <!-- Patient Multi-Test Selector Switcher (if patient has multiple orders) -->
    <div
      v-if="patientOrders && patientOrders.length > 1"
      class="flex items-center gap-1.5 overflow-x-auto py-1 px-2 rounded-md bg-muted/30 border border-border/70 text-xs no-scrollbar"
    >
      <span class="text-[10.5px] font-semibold text-muted-foreground uppercase tracking-wider shrink-0 mr-1">
        {{ t('laboratory.patient_tests', 'Patient Tests') }} ({{ patientOrders.length }}):
      </span>
      <button
        v-for="pOrder in patientOrders"
        :key="pOrder.id"
        type="button"
        class="flex items-center gap-1.5 px-2 py-0.5 rounded border text-[11px] font-medium transition-all cursor-pointer shrink-0"
        :class="[
          pOrder.id === order.id
            ? 'border-primary bg-primary text-primary-foreground font-bold shadow-2xs'
            : 'border-border/80 bg-surface hover:bg-muted text-foreground',
        ]"
        @click="onSelectOrder && onSelectOrder(pOrder.id)"
      >
        <span
          class="size-1.5 rounded-full shrink-0"
          :class="{
            'bg-amber-500': pOrder.status === 'ordered',
            'bg-blue-500': pOrder.status === 'sample_collected',
            'bg-purple-500': pOrder.status === 'in_progress',
            'bg-emerald-500': pOrder.status === 'completed',
          }"
        />
        <span>{{ pOrder.testName }}</span>
      </button>
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
