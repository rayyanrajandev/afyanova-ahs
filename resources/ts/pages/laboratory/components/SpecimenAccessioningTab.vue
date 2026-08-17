/**
 * SpecimenAccessioningTab — Specimen Receipt & Integrity Validation (Volume 2.4 §5)
 * =================================================================================
 * 2027 Modern Enterprise Hospital LIS Accessioning Station:
 * - Specimen Barcode Identifier & Tube Type/Color Guidance
 * - Specimen Integrity Checks (Adequate, Hemolyzed, Clotted, Lipemic, QNS)
 * - Sample Acceptance & Rejection Workflow with Mandatory Audited Reasons
 * - Full Internationalization (i18n) Support
 */

<script setup lang="ts">
import {
  AlertTriangle,
  Barcode,
  Check,
  CheckCircle2,
  Clock,
  FlaskConical,
  RotateCcw,
  Send,
  Sparkles,
  TestTube2,
  XCircle,
} from "lucide-vue-next";
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import type { LaboratoryOrder, UseLaboratoryOrders } from "../composables/useLaboratoryOrders";

const props = defineProps<{
  order: LaboratoryOrder;
  laboratory: UseLaboratoryOrders;
}>();

const { t } = useI18n({ useScope: "global" });

const accessionNotes = ref("");
const rejectReason = ref("");
const showRejectModal = ref(false);

const isSampleReceived = computed(() => {
  return props.order.status !== "ordered";
});

function handleAcceptSample() {
  props.laboratory.acceptSpecimen(props.order.id, accessionNotes.value);
}

function handleStartTesting() {
  props.laboratory.startAnalysis(props.order.id);
}

function handleConfirmRejection() {
  if (!rejectReason.value.trim()) return;
  props.laboratory.rejectSpecimen(props.order.id, rejectReason.value);
  showRejectModal.value = false;
}
</script>

<template>
  <div class="space-y-3.5 p-3.5 w-full">
    
    <!-- Status Overview Alert -->
    <div
      v-if="order.status === 'ordered'"
      class="rounded-lg border border-amber-500/30 bg-amber-500/10 p-3 text-xs text-amber-900 dark:text-amber-200 flex items-center justify-between gap-3"
    >
      <div class="flex items-center gap-2.5">
        <Clock class="size-4.5 text-amber-600 dark:text-amber-400 shrink-0" />
        <div>
          <p class="font-bold text-xs">{{ t('laboratory.specimen_pending_banner', 'Specimen Pending Receipt in Laboratory') }}</p>
          <p class="text-[11px] text-amber-800/90 dark:text-amber-300/90 mt-0.5">
            {{ t('laboratory.specimen_pending_desc', { doctor: order.orderingClinician }) }}
          </p>
        </div>
      </div>

      <div class="flex items-center gap-2 shrink-0">
        <Button
          size="sm"
          class="h-7.5 text-xs font-semibold gap-1.5 px-3 cursor-pointer shadow-xs bg-emerald-600 hover:bg-emerald-700 text-white"
          :disabled="laboratory.isUpdatingOrder.value"
          @click="handleAcceptSample"
        >
          <Check class="size-3.5" />
          <span>{{ t('laboratory.accept_specimen', 'Accept Specimen') }}</span>
        </Button>
      </div>
    </div>

    <div
      v-else-if="order.status === 'sample_collected'"
      class="rounded-lg border border-blue-500/30 bg-blue-500/10 p-3 text-xs text-blue-900 dark:text-blue-200 flex items-center justify-between gap-3"
    >
      <div class="flex items-center gap-2.5">
        <TestTube2 class="size-4.5 text-blue-600 dark:text-blue-400 shrink-0" />
        <div>
          <p class="font-bold text-xs">{{ t('laboratory.specimen_in_lab_banner', 'Specimen Accessioned & Ready for Analysis') }}</p>
          <p class="text-[11px] text-blue-800/90 dark:text-blue-300/90 mt-0.5">
            {{ t('laboratory.specimen_in_lab_desc', { time: order.collectedAt ? new Date(order.collectedAt).toLocaleTimeString() : 'Recent', user: order.collectedBy || 'Lab Team' }) }}
          </p>
        </div>
      </div>

      <Button
        size="sm"
        class="h-7.5 text-xs font-semibold gap-1.5 px-3.5 cursor-pointer shadow-xs"
        :disabled="laboratory.isUpdatingOrder.value"
        @click="handleStartTesting"
      >
        <FlaskConical class="size-3.5" />
        <span>{{ t('laboratory.start_analysis', 'Start Test Analysis') }}</span>
      </Button>
    </div>

    <!-- 1. Specimen Protocol & Container Details Card -->
    <section class="rounded-lg border border-border bg-surface p-3.5 shadow-2xs space-y-3">
      <div class="flex items-center justify-between border-b border-border/80 pb-2">
        <div class="flex items-center gap-2">
          <div class="flex size-6 items-center justify-center rounded-md bg-primary/10 text-primary">
            <TestTube2 class="size-3.5" aria-hidden="true" />
          </div>
          <h3 class="text-xs font-bold uppercase tracking-wider text-foreground flex items-center gap-2">
            <span>{{ t('laboratory.specimen_protocol_title', 'Specimen Accessioning & Collection Protocol') }}</span>
            <Badge variant="outline" class="text-[9px] font-mono px-1 py-0 uppercase">{{ t('laboratory.pre_analytical', 'Pre-Analytical') }}</Badge>
          </h3>
        </div>

        <span class="text-[11px] font-mono text-muted-foreground">CLSI GP41-A6</span>
      </div>

      <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-3 text-xs">
        <!-- Sample Type -->
        <div class="space-y-1 p-2 rounded-md border border-border/60 bg-muted/20">
          <span class="text-[10.5px] text-muted-foreground block">{{ t('laboratory.specimen_medium', 'Specimen Medium') }}</span>
          <span class="font-bold text-foreground">{{ order.sampleType }}</span>
        </div>

        <!-- Collection Container / Tube Type -->
        <div class="space-y-1 p-2 rounded-md border border-border/60 bg-muted/20">
          <span class="text-[10.5px] text-muted-foreground block">{{ t('laboratory.recommended_container', 'Recommended Container') }}</span>
          <span class="font-bold text-primary">{{ order.tubeType || 'Standard EDTA / SST Tube' }}</span>
        </div>

        <!-- Barcode Identifier -->
        <div class="space-y-1 p-2 rounded-md border border-border/60 bg-muted/20">
          <span class="text-[10.5px] text-muted-foreground block">{{ t('laboratory.specimen_barcode', 'Specimen Barcode') }}</span>
          <span class="font-mono font-bold text-foreground">{{ order.orderNumber }}</span>
        </div>

        <!-- Specimen Integrity Status -->
        <div class="space-y-1 p-2 rounded-md border border-border/60 bg-muted/20">
          <span class="text-[10.5px] text-muted-foreground block">{{ t('laboratory.specimen_quality', 'Specimen Quality') }}</span>
          <Badge
            variant="outline"
            class="text-[9.5px] font-mono uppercase px-1.5 py-0"
            :class="order.specimenIntegrity === 'adequate' ? 'border-emerald-500/40 text-emerald-600 bg-emerald-500/10' : 'border-rose-500/40 text-rose-600 bg-rose-500/10'"
          >
            {{ order.specimenIntegrity === 'adequate' ? t('laboratory.quality_adequate', 'Adequate') : order.specimenIntegrity }}
          </Badge>
        </div>
      </div>
    </section>

    <!-- 2. Accessioning Actions & Rejection Control -->
    <section class="rounded-lg border border-border bg-surface p-3.5 shadow-2xs space-y-3">
      <div class="flex items-center justify-between border-b border-border/80 pb-2">
        <h4 class="text-xs font-bold uppercase tracking-wider text-foreground">
          {{ t('laboratory.custody_title', 'Specimen Acceptance & Chain of Custody') }}
        </h4>
      </div>

      <div class="space-y-3 text-xs">
        <div class="space-y-1">
          <Label class="text-xs font-semibold text-foreground">
            {{ t('laboratory.accession_remarks', 'Accessioning Remarks & Observations') }}
          </Label>
          <Textarea
            v-model="accessionNotes"
            rows="2"
            class="text-xs resize-none"
            :placeholder="t('laboratory.accession_remarks_placeholder', 'Optional specimen condition notes (e.g. Received intact at room temperature)...')"
          />
        </div>

        <div class="flex flex-wrap items-center justify-between gap-2 pt-2 border-t border-border/60">
          <Button
            variant="outline"
            size="sm"
            class="h-8 text-xs text-rose-600 border-rose-500/40 hover:bg-rose-500/10 cursor-pointer gap-1"
            :disabled="order.status === 'completed' || order.status === 'cancelled'"
            @click="showRejectModal = true"
          >
            <XCircle class="size-3.5" />
            <span>{{ t('laboratory.reject_specimen', 'Reject Specimen') }}</span>
          </Button>

          <div class="flex items-center gap-2">
            <Button
              v-if="order.status === 'ordered'"
              size="sm"
              class="h-8 text-xs font-semibold gap-1.5 px-4 cursor-pointer shadow-xs bg-emerald-600 hover:bg-emerald-700 text-white"
              :disabled="laboratory.isUpdatingOrder.value"
              @click="handleAcceptSample"
            >
              <Check class="size-3.5" />
              <span>{{ t('laboratory.accept_specimen', 'Accept Specimen') }}</span>
            </Button>

            <Button
              v-else-if="order.status === 'sample_collected'"
              size="sm"
              class="h-8 text-xs font-semibold gap-1.5 px-4 cursor-pointer shadow-xs"
              :disabled="laboratory.isUpdatingOrder.value"
              @click="handleStartTesting"
            >
              <FlaskConical class="size-3.5" />
              <span>{{ t('laboratory.transfer_to_entry', 'Transfer to Result Entry') }}</span>
            </Button>
          </div>
        </div>
      </div>
    </section>

    <!-- Specimen Rejection Dialog -->
    <div
      v-if="showRejectModal"
      class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-xs p-4"
    >
      <div class="w-full max-w-md rounded-lg border border-border bg-popover p-4 shadow-xl space-y-3 text-xs">
        <div class="flex items-center justify-between border-b border-border pb-2">
          <div class="flex items-center gap-2 text-rose-600 font-bold">
            <AlertTriangle class="size-4" />
            <h3 class="text-sm">{{ t('laboratory.reject_modal_title', 'Reject Laboratory Specimen') }}</h3>
          </div>
          <button type="button" class="text-muted-foreground hover:text-foreground cursor-pointer" @click="showRejectModal = false">
            ✕
          </button>
        </div>

        <p class="text-muted-foreground">
          {{ t('laboratory.reject_modal_desc', 'Rejecting this specimen will cancel the order and trigger an immediate notification to the ordering clinician with your reason.') }}
        </p>

        <div class="space-y-1.5">
          <Label required class="text-xs font-semibold text-foreground">
            {{ t('laboratory.rejection_reason', 'Reason for Rejection') }}
          </Label>
          <select
            v-model="rejectReason"
            class="w-full h-8 rounded border border-border bg-background px-2 text-xs font-medium"
          >
            <option value="">{{ t('laboratory.select_rejection_reason', 'Select rejection reason...') }}</option>
            <option value="Hemolyzed Specimen (Unsuitable for biochemistry/potassium testing)">Hemolyzed Specimen</option>
            <option value="Clotted Blood (Unsuitable for hematology CBC/platelets)">Clotted Blood</option>
            <option value="Insufficient Volume (QNS — Quantity Not Sufficient)">Insufficient Volume (QNS)</option>
            <option value="Incorrect Container / Anticoagulant Tube">Incorrect Container / Tube</option>
            <option value="Mislabeled / Unlabeled Specimen Container">Mislabeled / Unlabeled Specimen</option>
            <option value="Specimen Leaked / Compromised in Transit">Specimen Leaked / Compromised</option>
          </select>
        </div>

        <div class="flex items-center justify-end gap-2 pt-2 border-t border-border">
          <Button variant="secondary" size="sm" class="h-8 text-xs cursor-pointer" @click="showRejectModal = false">
            {{ t('common.cancel', 'Cancel') }}
          </Button>
          <Button
            size="sm"
            class="h-8 text-xs font-semibold bg-rose-600 hover:bg-rose-700 text-white cursor-pointer shadow-xs"
            :disabled="!rejectReason.trim()"
            @click="handleConfirmRejection"
          >
            {{ t('laboratory.confirm_rejection', 'Confirm Rejection') }}
          </Button>
        </div>
      </div>
    </div>
  </div>
</template>
