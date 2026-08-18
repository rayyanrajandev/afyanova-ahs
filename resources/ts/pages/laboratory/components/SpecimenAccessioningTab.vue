/** * SpecimenAccessioningTab — Specimen Receipt & Integrity Validation (Volume
2.4 §5) *
=================================================================================
* 2027 Modern Enterprise Hospital LIS Accessioning Station: * - Specimen Barcode
Identifier & Tube Type/Color Guidance * - Specimen Integrity Checks (Adequate,
Hemolyzed, Clotted, Lipemic, QNS) * - Sample Acceptance & Rejection Workflow
with Mandatory Audited Reasons * - Full Internationalization (i18n) Support */

<script setup lang="ts">
import {
  AlertTriangle,
  Barcode,
  Check,
  CheckCircle2,
  Clock,
  FlaskConical,
  Printer,
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
import {
  labStageOf,
  type LabStage,
  type LaboratoryOrder,
  type UseLaboratoryOrders,
} from "../composables/useLaboratoryOrders";

const props = defineProps<{
  order: LaboratoryOrder;
  laboratory: UseLaboratoryOrders;
}>();

const { t } = useI18n({ useScope: "global" });

const accessionNotes = ref("");
const rejectReason = ref("");
const showRejectModal = ref(false);

const stage = computed<LabStage>(() => labStageOf(props.order));

/**
 * Rejection is a pre-analytical decision. Once the analyser has run, discarding
 * the order destroys work and hides a result that was actually produced — the
 * right move there is to report it, not to cancel. The button used to stay live
 * all the way through result entry.
 */
const canReject = computed(
  () =>
    stage.value === "awaiting_specimen" || stage.value === "ready_for_analysis",
);

function handleAcceptSample() {
  void props.laboratory.acceptSpecimen(props.order.id, accessionNotes.value);
}

function handleStartTesting() {
  void props.laboratory.startAnalysis(props.order.id);
}

async function handleConfirmRejection() {
  if (!rejectReason.value.trim()) return;

  const ok = await props.laboratory.rejectSpecimen(
    props.order.id,
    rejectReason.value,
  );
  if (ok) {
    showRejectModal.value = false;
    rejectReason.value = "";
  }
}
</script>

<template>
  <div class="space-y-3.5 p-3.5 w-full">
    <!-- Status Overview Alert -->
    <div
      v-if="stage === 'awaiting_specimen'"
      class="rounded-md border border-amber-500/30 bg-amber-500/10 px-3 py-2 text-xs text-amber-900 dark:text-amber-200 flex items-center justify-between gap-2.5"
    >
      <div class="flex items-center gap-2 min-w-0">
        <Clock class="size-4 text-amber-600 dark:text-amber-400 shrink-0" />
        <div class="flex flex-wrap items-center gap-x-2 gap-y-0.5 min-w-0">
          <p class="font-bold text-xs">
            {{
              t(
                "laboratory.specimen_pending_banner",
                "Specimen Pending Receipt in Laboratory",
              )
            }}
          </p>
          <span class="hidden sm:inline text-amber-500/60">·</span>
          <p
            class="text-[11px] text-amber-800/85 dark:text-amber-300/85 truncate"
          >
            {{
              t("laboratory.specimen_pending_desc", {
                doctor: order.orderingClinician,
              })
            }}
          </p>
        </div>
      </div>

      <Badge
        variant="outline"
        class="border-amber-500/40 text-amber-700 dark:text-amber-300 bg-amber-500/15 font-mono text-[9.5px] uppercase px-1.5 py-0 shrink-0"
      >
        {{ t("laboratory.status_awaiting_specimen", "Awaiting Receipt") }}
      </Badge>
    </div>

    <div
      v-else-if="stage === 'ready_for_analysis'"
      class="rounded-md border border-blue-500/30 bg-blue-500/10 px-3 py-2 text-xs text-blue-900 dark:text-blue-200 flex items-center justify-between gap-2.5"
    >
      <div class="flex items-center gap-2 min-w-0">
        <TestTube2 class="size-4 text-blue-600 dark:text-blue-400 shrink-0" />
        <div class="flex flex-wrap items-center gap-x-2 gap-y-0.5 min-w-0">
          <p class="font-bold text-xs">
            {{
              t(
                "laboratory.specimen_in_lab_banner",
                "Specimen Accessioned & Ready for Analysis",
              )
            }}
          </p>
          <span class="hidden sm:inline text-blue-500/60">·</span>
          <p
            class="text-[11px] text-blue-800/85 dark:text-blue-300/85 truncate"
          >
            {{
              t(
                "laboratory.specimen_in_lab_hint",
                "Review container integrity below and start analysis to begin result entry.",
              )
            }}
          </p>
        </div>
      </div>

      <Badge
        variant="outline"
        class="border-blue-500/40 text-blue-700 dark:text-blue-300 bg-blue-500/15 font-mono text-[9.5px] uppercase px-1.5 py-0 shrink-0"
      >
        {{ t("laboratory.status_ready_analysis", "Ready on Bench") }}
      </Badge>
    </div>

    <!-- 1. Specimen Protocol & Container Details Card -->
    <section
      class="rounded-lg border border-border bg-surface p-3.5 shadow-2xs space-y-3"
    >
      <div
        class="flex items-center justify-between border-b border-border/80 pb-2"
      >
        <div class="flex items-center gap-2">
          <div
            class="flex size-6 items-center justify-center rounded-md bg-primary/10 text-primary"
          >
            <TestTube2 class="size-3.5" aria-hidden="true" />
          </div>
          <h3
            class="text-xs font-bold uppercase tracking-wider text-foreground flex items-center gap-2"
          >
            <span>{{
              t(
                "laboratory.specimen_protocol_title",
                "Specimen Accessioning & Collection Protocol",
              )
            }}</span>
            <Badge
              variant="outline"
              class="text-[9px] font-mono px-1 py-0 uppercase"
              >{{ t("laboratory.pre_analytical", "Pre-Analytical") }}</Badge
            >
          </h3>
        </div>

        <span class="text-[11px] font-mono text-muted-foreground"
          >CLSI GP41-A6</span
        >
      </div>

      <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-3 text-xs">
        <!-- Sample Type -->
        <div
          class="space-y-1 p-2 rounded-md border border-border/60 bg-muted/20"
        >
          <span class="text-[10.5px] text-muted-foreground block">{{
            t("laboratory.specimen_medium", "Specimen Medium")
          }}</span>
          <span class="font-bold text-foreground">{{ order.sampleType }}</span>
        </div>

        <!-- Collection Container / Tube Type -->
        <div
          class="space-y-1 p-2 rounded-md border border-border/60 bg-muted/20"
        >
          <span class="text-[10.5px] text-muted-foreground block">{{
            t("laboratory.recommended_container", "Recommended Container")
          }}</span>
          <span class="font-bold text-primary">{{
            order.tubeType || "Standard EDTA / SST Tube"
          }}</span>
        </div>

        <!-- Barcode Identifier & Tube Label Printing -->
        <div
          class="space-y-1 p-2 rounded-md border border-border/60 bg-muted/20 flex flex-col justify-between"
        >
          <div class="flex items-center justify-between">
            <span class="text-[10.5px] text-muted-foreground block">{{
              t("laboratory.specimen_barcode", "Specimen Barcode")
            }}</span>
            <Button
              variant="ghost"
              size="sm"
              class="h-4.5 px-1.5 text-[10px] text-primary hover:bg-primary/10 gap-1 cursor-pointer font-medium"
              :title="
                t('laboratory.print_tube_label', 'Print Thermal Tube Barcode')
              "
              @click="() => window.print()"
            >
              <Printer class="size-2.5" />
              <span>{{ t("laboratory.print_label", "Print") }}</span>
            </Button>
          </div>
          <span class="font-mono font-bold text-foreground">{{
            order.orderNumber
          }}</span>
        </div>

        <!-- Specimen Integrity Status -->
        <div
          class="space-y-1 p-2 rounded-md border border-border/60 bg-muted/20"
        >
          <span class="text-[10.5px] text-muted-foreground block">{{
            t("laboratory.specimen_quality", "Specimen Quality")
          }}</span>
          <Badge
            variant="outline"
            class="text-[9.5px] font-mono uppercase px-1.5 py-0"
            :class="
              order.specimenIntegrity === 'adequate'
                ? 'border-emerald-500/40 text-emerald-600 bg-emerald-500/10'
                : 'border-rose-500/40 text-rose-600 bg-rose-500/10'
            "
          >
            {{
              order.specimenIntegrity === "adequate"
                ? t("laboratory.quality_adequate", "Adequate")
                : order.specimenIntegrity
            }}
          </Badge>
        </div>
      </div>
    </section>

    <!-- 2. Accessioning Actions & Rejection Control -->
    <section
      class="rounded-lg border border-border bg-surface p-3.5 shadow-2xs space-y-3"
    >
      <div
        class="flex items-center justify-between border-b border-border/80 pb-2"
      >
        <h4 class="text-xs font-bold uppercase tracking-wider text-foreground">
          {{
            t(
              "laboratory.custody_title",
              "Specimen Acceptance & Chain of Custody",
            )
          }}
        </h4>
      </div>

      <div class="space-y-3 text-xs">
        <div class="space-y-1">
          <Label class="text-xs font-semibold text-foreground">
            {{
              t(
                "laboratory.accession_remarks",
                "Accessioning Remarks & Observations",
              )
            }}
          </Label>
          <Textarea
            v-model="accessionNotes"
            rows="2"
            class="text-xs resize-none"
            :disabled="stage !== 'awaiting_specimen'"
            :placeholder="
              stage === 'awaiting_specimen'
                ? t(
                    'laboratory.accession_remarks_placeholder',
                    'Optional specimen condition notes (e.g. Received intact at room temperature)...',
                  )
                : t(
                    'laboratory.accession_remarks_closed',
                    'Recorded at the time the specimen was accepted.',
                  )
            "
          />
        </div>

        <!--
          Exactly one forward action is ever live here, chosen by stage. Both
          "Accept" and "Start Analysis" used to be reachable together, and
          "Reject" stayed clickable long after the analyser had run.
        -->
        <div
          class="flex flex-wrap items-center justify-between gap-2 pt-2 border-t border-border/60"
        >
          <Button
            v-if="canReject"
            variant="outline"
            size="sm"
            class="h-8 text-xs text-rose-600 border-rose-500/40 hover:bg-rose-500/10 cursor-pointer gap-1"
            :disabled="laboratory.isUpdatingOrder.value"
            @click="showRejectModal = true"
          >
            <XCircle class="size-3.5" />
            <span>{{
              t("laboratory.reject_specimen", "Reject Specimen")
            }}</span>
          </Button>
          <span v-else class="text-[11px] text-muted-foreground">
            {{
              t(
                "laboratory.reject_closed",
                "Rejection is only possible before analysis begins.",
              )
            }}
          </span>

          <div class="flex items-center gap-2">
            <Button
              v-if="stage === 'awaiting_specimen'"
              size="sm"
              class="h-8 text-xs font-semibold gap-1.5 px-4 cursor-pointer shadow-xs bg-emerald-600 hover:bg-emerald-700 text-white"
              :disabled="laboratory.isUpdatingOrder.value"
              @click="handleAcceptSample"
            >
              <Check class="size-3.5" />
              <span>{{
                t("laboratory.accept_specimen", "Accept Specimen")
              }}</span>
            </Button>

            <Button
              v-else-if="stage === 'ready_for_analysis'"
              size="sm"
              class="h-8 text-xs font-semibold gap-1.5 px-4 cursor-pointer shadow-xs"
              :disabled="laboratory.isUpdatingOrder.value"
              @click="handleStartTesting"
            >
              <FlaskConical class="size-3.5" />
              <span>{{
                t("laboratory.start_analysis", "Start Test Analysis")
              }}</span>
            </Button>

            <span v-else class="text-[11px] font-medium text-muted-foreground">
              {{
                t(
                  "laboratory.accessioning_done",
                  "Accessioning complete for this specimen.",
                )
              }}
            </span>
          </div>
        </div>
      </div>
    </section>

    <!-- Specimen Rejection Dialog -->
    <div
      v-if="showRejectModal"
      class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-xs p-4"
    >
      <div
        class="w-full max-w-md rounded-lg border border-border bg-popover p-4 shadow-xl space-y-3 text-xs"
      >
        <div
          class="flex items-center justify-between border-b border-border pb-2"
        >
          <div class="flex items-center gap-2 text-rose-600 font-bold">
            <AlertTriangle class="size-4" />
            <h3 class="text-sm">
              {{
                t("laboratory.reject_modal_title", "Reject Laboratory Specimen")
              }}
            </h3>
          </div>
          <button
            type="button"
            class="text-muted-foreground hover:text-foreground cursor-pointer"
            @click="showRejectModal = false"
          >
            ✕
          </button>
        </div>

        <p class="text-muted-foreground">
          {{
            t(
              "laboratory.reject_modal_desc",
              "Rejecting this specimen will cancel the order and trigger an immediate notification to the ordering clinician with your reason.",
            )
          }}
        </p>

        <div class="space-y-1.5">
          <Label required class="text-xs font-semibold text-foreground">
            {{ t("laboratory.rejection_reason", "Reason for Rejection") }}
          </Label>
          <select
            v-model="rejectReason"
            class="w-full h-8 rounded border border-border bg-background px-2 text-xs font-medium"
          >
            <option value="">
              {{
                t(
                  "laboratory.select_rejection_reason",
                  "Select rejection reason...",
                )
              }}
            </option>
            <option
              value="Hemolyzed Specimen (Unsuitable for biochemistry/potassium testing)"
            >
              Hemolyzed Specimen
            </option>
            <option
              value="Clotted Blood (Unsuitable for hematology CBC/platelets)"
            >
              Clotted Blood
            </option>
            <option value="Insufficient Volume (QNS — Quantity Not Sufficient)">
              Insufficient Volume (QNS)
            </option>
            <option value="Incorrect Container / Anticoagulant Tube">
              Incorrect Container / Tube
            </option>
            <option value="Mislabeled / Unlabeled Specimen Container">
              Mislabeled / Unlabeled Specimen
            </option>
            <option value="Specimen Leaked / Compromised in Transit">
              Specimen Leaked / Compromised
            </option>
          </select>
        </div>

        <div
          class="flex items-center justify-end gap-2 pt-2 border-t border-border"
        >
          <Button
            variant="secondary"
            size="sm"
            class="h-8 text-xs cursor-pointer"
            @click="showRejectModal = false"
          >
            {{ t("common.cancel", "Cancel") }}
          </Button>
          <Button
            size="sm"
            class="h-8 text-xs font-semibold bg-rose-600 hover:bg-rose-700 text-white cursor-pointer shadow-xs"
            :disabled="!rejectReason.trim()"
            @click="handleConfirmRejection"
          >
            {{ t("laboratory.confirm_rejection", "Confirm Rejection") }}
          </Button>
        </div>
      </div>
    </div>
  </div>
</template>
