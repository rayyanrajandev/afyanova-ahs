/** * PharmacyOrderHeader — Patient & Prescription Encounter Banner (Volume 2.6)
* =========================================================================== *
Styled identically to LabOrderHeader & RadiologyOrderHeader: * - Patient
Demographics & Identification * - Multi-Item Prescription Switcher for the
Active Patient * - Priority Badge & Dispensing Stage Status Tracker * - Ordering
Clinician & Clinical Indication Callout * - Print triggers for Thermal Label &
Consolidated Dispensing Slip */

<script setup lang="ts">
import {
  AlertTriangle,
  Barcode,
  CheckCircle2,
  Clock,
  FileCheck,
  FileText,
  HeartPulse,
  Pill,
  Printer,
  ShieldCheck,
  Stethoscope,
  User,
} from "lucide-vue-next";
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import { stepBadgeStatus, stepLabelKey } from "@/composables/patientFlowStep";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import {
  pharmacyStageOf,
  type PharmacyOrder,
  type PharmacyStage,
  type UsePharmacyOrders,
} from "../composables/usePharmacyOrders";
import {
  printPharmacyLabel,
  printConsolidatedPrescription,
} from "../pharmacyLabelPrint";

const props = defineProps<{
  order: PharmacyOrder;
  patientOrders: PharmacyOrder[];
  pharmacy: UsePharmacyOrders;
}>();

const { t } = useI18n({ useScope: "global" });

const stage = computed<PharmacyStage>(() => pharmacyStageOf(props.order));

const STAGE_LABELS: Record<PharmacyStage, string> = {
  pending_review: "Pending Review",
  ready_for_dispense: "In Preparation",
  dispensed_unverified: "Dispensed — Awaiting Sign-Off",
  verified_completed: "Verified & Released",
  cancelled: "Cancelled",
};

const stageLabel = computed(() => STAGE_LABELS[stage.value]);

const visitStageLabel = computed<string | null>(() => {
  const key = stepLabelKey(props.order.visitStage);
  return key ? t(key) : props.order.visitStage || null;
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
const formattedPatientAge = computed(() => {
  if (!props.order.patientAge || props.order.patientAge === "—") return "";
  const ageStr = String(props.order.patientAge);
  return ageStr.toLowerCase().includes("yr") ? ageStr : `${ageStr} yrs`;
});
</script>

<template>
  <header
    class="flex shrink-0 flex-col gap-2 border-b border-border bg-surface px-4 py-2.5 rounded-t-lg"
  >
    <!-- Top Row: Patient & Medication Info + Badges + Print Actions -->
    <div class="flex flex-wrap items-center justify-between gap-3">
      <!-- Patient & Medication Info -->
      <div class="flex items-center gap-3 min-w-0">
        <div
          class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary font-bold text-sm"
        >
          <Pill class="size-5" />
        </div>

        <div class="min-w-0">
          <div class="flex items-center gap-2 flex-wrap">
            <h2 class="text-sm font-bold text-foreground truncate">
              {{
                order.patientName || t("pharmacy.unknown_patient", "Patient")
              }}
            </h2>
            <span
              class="font-mono text-xs font-semibold text-primary bg-primary/10 px-1.5 py-0.2 rounded"
            >
              {{ order.patientMrn || "—" }}
            </span>
            <span class="text-xs text-muted-foreground font-mono">
              {{ order.patientGender || "—"
              }}<template v-if="formattedPatientAge">
                · {{ formattedPatientAge }}</template
              >
            </span>
          </div>

          <div
            class="flex items-center gap-2 mt-0.5 text-xs text-muted-foreground"
          >
            <span class="font-bold text-foreground">{{
              order.medicationName
            }}</span>
            <span
              class="font-mono text-[11px] bg-secondary px-1.5 py-0 rounded text-muted-foreground"
              >{{ order.medicationCode }}</span
            >
            <span>·</span>
            <span class="text-[11.5px] font-semibold text-primary"
              >Qty: {{ order.quantityPrescribed }}
              {{ order.prescribedUnit || "units" }}</span
            >
          </div>
        </div>
      </div>

      <!-- Badges & Action Buttons -->
      <div class="flex items-center gap-2 shrink-0 flex-wrap">
        <!-- Patient Encounter Visit Stage -->
        <Badge
          v-if="visitStageLabel"
          variant="outline"
          class="text-[10px] font-semibold uppercase px-2 py-0.5"
          :class="visitStageClass"
        >
          {{ visitStageLabel }}
        </Badge>

        <!-- Dispensing Stage Badge -->
        <Badge
          variant="outline"
          class="text-[10px] font-mono uppercase px-2 py-0.5"
          :class="{
            'border-amber-500/40 text-amber-600 bg-amber-500/10':
              stage === 'pending_review',
            'border-blue-500/40 text-blue-600 bg-blue-500/10':
              stage === 'ready_for_dispense',
            'border-sky-500/40 text-sky-600 bg-sky-500/10':
              stage === 'dispensed_unverified',
            'border-emerald-500/40 text-emerald-600 bg-emerald-500/10':
              stage === 'verified_completed',
            'border-rose-500/40 text-rose-600 bg-rose-500/10':
              stage === 'cancelled',
          }"
        >
          {{ stageLabel }}
        </Badge>

        <!-- Print Actions -->
        <div class="flex items-center gap-1 ml-1">
          <Button
            variant="outline"
            size="sm"
            class="h-7 px-2 text-[11px] gap-1 cursor-pointer"
            @click="() => printPharmacyLabel(order)"
          >
            <Printer class="size-3 text-muted-foreground" />
            <span>Thermal Label</span>
          </Button>

          <Button
            variant="outline"
            size="sm"
            class="h-7 px-2 text-[11px] gap-1 cursor-pointer"
            @click="() => printConsolidatedPrescription(patientOrders)"
          >
            <FileText class="size-3 text-muted-foreground" />
            <span>Dispensing Slip</span>
          </Button>
        </div>
      </div>
    </div>

    <!-- Bottom Detail Strip: Clinician Indication & Order Number -->
    <div
      class="flex flex-wrap items-center justify-between gap-2 pt-1 border-t border-border/60 text-xs"
    >
      <div class="flex items-center gap-2 min-w-0 text-muted-foreground">
        <Stethoscope class="size-3.5 text-primary shrink-0" />
        <span class="font-medium text-foreground"
          >Dr.
          {{
            order.orderingClinician || order.orderedBy?.name || "Clinician"
          }}</span
        >
        <span>·</span>
        <span class="italic text-[11px] truncate"
          >"{{
            order.clinicalIndication || "Routine prescription order"
          }}"</span
        >
      </div>

      <div
        class="flex items-center gap-2 font-mono text-[11px] text-muted-foreground"
      >
        <Barcode class="size-3.5 text-muted-foreground/70" />
        <span class="font-semibold text-foreground">{{
          order.orderNumber || order.id.substring(0, 8)
        }}</span>
        <span class="text-[10px]">({{ order.frequency || "Daily" }})</span>
      </div>
    </div>
  </header>
</template>
