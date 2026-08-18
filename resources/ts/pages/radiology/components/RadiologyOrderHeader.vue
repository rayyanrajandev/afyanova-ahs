/** * RadiologyOrderHeader — Diagnostic Imaging Patient Header (2027 Standard) *
========================================================================= *
Clean, clinical header presenting: * - Patient Name, Hospital MRN, Age/Gender *
- Modality Badge & Study Description * - Ordering Clinician & Clinical
Indication preview * - Acuity / Status Badges & Scheduled Slot Time * - Header
Actions: Start Examination (Primary CTA) & Cancel Study modal dialog */

<script setup lang="ts">
import {
  AlertCircle,
  CalendarClock,
  Clock,
  FileText,
  Play,
  ScanLine,
  ShieldCheck,
  Stethoscope,
  User,
  X,
  XCircle,
  Zap,
} from "lucide-vue-next";
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import { stepBadgeStatus, stepLabelKey } from "@/composables/patientFlowStep";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Textarea } from "@/components/ui/textarea";
import type {
  RadiologyOrder,
  UseRadiologyOrders,
} from "../composables/useRadiologyOrders";

const props = defineProps<{
  order: RadiologyOrder;
  patientOrders?: RadiologyOrder[];
  onSelectOrder?: (orderId: string) => void;
  onStartStudy?: () => void;
  radiology?: UseRadiologyOrders;
}>();

const emit = defineEmits<{
  (e: "start-study"): void;
}>();

const { t } = useI18n({ useScope: "global" });

const showCancelModal = ref(false);
const cancelReason = ref("");

const canStart = computed(
  () =>
    Boolean(props.radiology) &&
    ["ordered", "scheduled"].includes(props.order.status),
);

const canCancel = computed(
  () =>
    Boolean(props.radiology) &&
    ["ordered", "scheduled"].includes(props.order.status) &&
    !props.order.verifiedAt,
);

const CANCEL_PRESETS = [
  "Patient declined examination",
  "Duplicate imaging request",
  "Medically contraindicated",
  "Patient referred to higher center",
];

function applyCancelPreset(preset: string) {
  cancelReason.value = preset;
}

async function handleStartStudy() {
  if (!props.radiology) return;
  await props.radiology.startStudy(props.order.id);
  emit("start-study");
  props.onStartStudy?.();
}

async function handleConfirmCancel() {
  if (!cancelReason.value.trim() || !props.radiology) return;
  await props.radiology.cancelStudy(props.order.id, cancelReason.value.trim());
  cancelReason.value = "";
  showCancelModal.value = false;
}

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
        <div
          class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary border border-primary/20 shadow-2xs"
        >
          <ScanLine class="size-5" />
        </div>

        <div class="min-w-0 space-y-0.5">
          <!-- Row 1: Patient Name & Core Badges -->
          <div class="flex flex-wrap items-center gap-2">
            <h2
              class="truncate text-sm font-bold text-foreground tracking-tight"
            >
              {{
                props.order.patientName ||
                t("radiology.unknown_patient", "Patient")
              }}
            </h2>
            <span
              class="rounded bg-primary/10 px-1.5 py-0 font-mono text-[11px] font-bold text-primary"
            >
              {{ props.order.patientMrn }}
            </span>
            <span
              v-if="props.order.patientGender || props.order.patientAge"
              class="font-mono text-xs text-muted-foreground"
            >
              {{ props.order.patientGender
              }}<template v-if="props.order.patientAge">
                · {{ props.order.patientAge }}</template
              >
            </span>

            <!-- Acuity Badges -->
            <Badge
              v-if="props.order.priority === 'stat'"
              variant="outline"
              class="animate-pulse border-rose-500/50 bg-rose-500/15 px-1.5 py-0 font-mono text-[9.5px] font-bold uppercase text-rose-600 dark:text-rose-400"
            >
              {{ t("radiology.priority_stat", "STAT") }}
            </Badge>
            <Badge
              v-else-if="props.order.priority === 'urgent'"
              variant="outline"
              class="border-amber-500/50 bg-amber-500/15 px-1.5 py-0 font-mono text-[9.5px] font-bold uppercase text-amber-600 dark:text-amber-400"
            >
              {{ t("radiology.priority_urgent", "URGENT") }}
            </Badge>
          </div>

          <!-- Row 2: Modality, Study Description & Ordering Doctor -->
          <div
            class="flex flex-wrap items-center gap-2 text-xs text-muted-foreground"
          >
            <span
              class="px-1.5 py-0 rounded font-mono text-[10px] font-extrabold uppercase"
              :class="modalityBadgeClass(props.order.modality)"
            >
              {{ props.order.modality }}
            </span>
            <span class="font-bold text-foreground">{{
              props.order.studyDescription
            }}</span>
            <span
              v-if="props.order.orderingClinician"
              class="inline-flex items-center gap-1 text-[11px] text-muted-foreground"
            >
              <Stethoscope class="size-3 text-primary/70" />
              {{ props.order.orderingClinician }}
            </span>
          </div>
        </div>
      </div>

      <!-- Right: Status / Slot Badges & Primary Actions -->
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
          {{ t("radiology.verified_released", "Released") }}
        </Badge>

        <!-- Cancel Study Button -->
        <Button
          v-if="canCancel"
          type="button"
          size="sm"
          variant="ghost"
          class="h-7 gap-1 px-2 text-xs font-semibold text-rose-600 hover:bg-rose-500/10 hover:text-rose-700 cursor-pointer border border-rose-500/30"
          @click="showCancelModal = true"
        >
          <XCircle class="size-3.5" />
          <span>{{ t("radiology.cancel_study", "Cancel Study") }}</span>
        </Button>

        <!-- Primary Start Now / Start Examination CTA -->
        <Button
          v-if="canStart"
          type="button"
          size="sm"
          class="h-7 gap-1 px-3 text-xs font-bold shadow-xs cursor-pointer disabled:opacity-60 bg-primary text-primary-foreground hover:bg-primary/90"
          :disabled="props.radiology?.isUpdatingOrder.value"
          @click="handleStartStudy"
        >
          <Play class="size-3 fill-current" />
          <span>{{
            props.order.status === "scheduled"
              ? t("radiology.call_patient_start", "Call & Start Scan")
              : t("radiology.start_now", "Start Examination")
          }}</span>
        </Button>
      </div>
    </div>
  </div>

  <!-- Safe Cancellation Modal Dialog -->
  <div
    v-if="showCancelModal"
    class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-xs p-4"
    @click.self="showCancelModal = false"
  >
    <div
      class="w-full max-w-md rounded-xl border border-rose-500/30 bg-popover p-5 shadow-2xl space-y-4 text-xs"
    >
      <!-- Modal Header -->
      <div
        class="flex items-center justify-between border-b border-border pb-3"
      >
        <div
          class="flex items-center gap-2 text-rose-600 dark:text-rose-400 font-bold text-sm"
        >
          <AlertCircle class="size-4.5" />
          <span>{{ t("radiology.cancel_study", "Cancel Imaging Order") }}</span>
        </div>
        <button
          type="button"
          class="text-muted-foreground hover:text-foreground cursor-pointer p-1 rounded"
          @click="showCancelModal = false"
        >
          <X class="size-4" />
        </button>
      </div>

      <p class="text-xs text-muted-foreground">
        Are you sure you want to cancel the
        <strong class="text-foreground">{{
          props.order.studyDescription
        }}</strong>
        for
        <strong class="text-foreground">{{ props.order.patientName }}</strong
        >?
      </p>

      <!-- Reason Presets -->
      <div class="space-y-1.5">
        <span class="text-[10.5px] font-semibold text-muted-foreground block">
          {{ t("radiology.cancel_reason_title", "Reason for Cancellation") }}:
        </span>
        <div class="flex flex-wrap gap-1">
          <button
            v-for="preset in CANCEL_PRESETS"
            :key="preset"
            type="button"
            class="px-2 py-0.5 rounded border border-border text-[10.5px] text-muted-foreground hover:border-primary/40 hover:text-foreground cursor-pointer bg-muted/30"
            @click="applyCancelPreset(preset)"
          >
            {{ preset }}
          </button>
        </div>
      </div>

      <Textarea
        v-model="cancelReason"
        rows="2"
        class="text-xs resize-none bg-background"
        :placeholder="
          t(
            'radiology.cancel_reason_placeholder',
            'e.g. Patient declined, duplicate request, contraindicated...',
          )
        "
      />

      <!-- Modal Actions -->
      <div
        class="flex items-center justify-end gap-2 pt-2 border-t border-border"
      >
        <Button
          type="button"
          size="sm"
          variant="outline"
          class="h-8 text-xs cursor-pointer"
          @click="showCancelModal = false"
        >
          {{ t("common.cancel", "Back") }}
        </Button>

        <Button
          type="button"
          size="sm"
          class="h-8 text-xs font-semibold bg-rose-600 hover:bg-rose-700 text-white cursor-pointer disabled:opacity-60"
          :disabled="
            !cancelReason.trim() || props.radiology?.isUpdatingOrder.value
          "
          @click="handleConfirmCancel"
        >
          {{ t("radiology.confirm_cancel", "Confirm Cancellation") }}
        </Button>
      </div>
    </div>
  </div>
</template>
