/** * RadiologyStageBar — Imaging Bench Progression & Guidance Toolbar (2027
Standard) *
=================================================================================
* Horizontal single-row workflow bar: * - 4-Step Stage Tracker: Order Received →
Scheduled → In Examination → Verified & Released * - Dynamic Contextual Guidance
next to the steps */

<script setup lang="ts">
import {
  CalendarClock,
  Check,
  CheckCircle2,
  Clock,
  FileCheck2,
  FileEdit,
  PlayCircle,
  ScanLine,
  ShieldAlert,
  ShieldCheck,
  Sparkles,
} from "lucide-vue-next";
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import type { RadiologyOrder } from "../composables/useRadiologyOrders";

const props = defineProps<{
  order: RadiologyOrder;
}>();

const { t } = useI18n({ useScope: "global" });

type BenchStage = "ordered" | "scheduled" | "in_progress" | "completed";

const currentStage = computed<BenchStage>(() => {
  const s = props.order.status;
  if (s === "in_progress") return "in_progress";
  if (s === "completed") return "completed";
  if (s === "scheduled") return "scheduled";
  return "ordered";
});

const isVerified = computed(() => Boolean(props.order.verifiedAt));

const STAGES = computed<Array<{ id: BenchStage; label: string; icon: any }>>(
  () => [
    {
      id: "ordered",
      label: t("radiology.stage_request", "Request"),
      icon: FileEdit,
    },
    {
      id: "scheduled",
      label: t("radiology.stage_booked", "Booked"),
      icon: CalendarClock,
    },
    {
      id: "in_progress",
      label: t("radiology.stage_scanning", "Scanning"),
      icon: ScanLine,
    },
    {
      id: "completed",
      label: t("radiology.stage_released", "Released"),
      icon: ShieldCheck,
    },
  ],
);

function stageIndex(stage: BenchStage): number {
  switch (stage) {
    case "ordered":
      return 0;
    case "scheduled":
      return 1;
    case "in_progress":
      return 2;
    case "completed":
      return 3;
  }
}

const currentIdx = computed(() => stageIndex(currentStage.value));

function getStepState(idx: number): "complete" | "current" | "upcoming" {
  if (idx < currentIdx.value) return "complete";
  if (idx === currentIdx.value) return "current";
  return "upcoming";
}

const contextualGuidance = computed<{
  text: string;
  alert?: boolean;
  success?: boolean;
}>(() => {
  if (props.order.status === "ordered") {
    return {
      text: t(
        "radiology.guide_ordered",
        "Walk-in ready: Start scanning now, or select a scheduled slot.",
      ),
    };
  }
  if (props.order.status === "scheduled") {
    return {
      text: t(
        "radiology.guide_scheduled",
        "Patient booked: Call patient to the imaging room and start scan.",
      ),
    };
  }
  if (props.order.status === "in_progress") {
    return {
      text: t(
        "radiology.guide_in_progress",
        "Patient on table: Perform scan, then record technique and findings.",
      ),
      alert: true,
    };
  }
  if (props.order.status === "completed" && !isVerified.value) {
    return {
      text: t(
        "radiology.guide_awaiting_verification",
        "Report saved: Awaiting second radiologist verification & chart release.",
      ),
      alert: true,
    };
  }
  if (isVerified.value) {
    return {
      text: t(
        "radiology.guide_verified",
        "Report authenticated and released to doctor chart.",
      ),
      success: true,
    };
  }
  return {
    text: t("radiology.guide_default", "Diagnostic imaging order active."),
  };
});
</script>

<template>
  <div
    class="flex items-center justify-between border-b border-border/80 bg-muted/20 px-3.5 py-1.5 text-xs"
  >
    <!-- Left: Bench Progression Steps -->
    <div class="flex items-center gap-1.5 sm:gap-2">
      <template v-for="(stage, idx) in STAGES" :key="stage.id">
        <!-- Step Pill -->
        <div
          class="flex items-center gap-1 px-1.5 py-0.5 rounded transition-all font-mono text-[10.5px]"
          :class="[
            getStepState(idx) === 'complete'
              ? 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300 font-semibold'
              : getStepState(idx) === 'current'
                ? 'bg-primary text-primary-foreground font-bold shadow-2xs'
                : 'text-muted-foreground bg-muted/40 font-normal',
          ]"
        >
          <Check
            v-if="getStepState(idx) === 'complete'"
            class="size-3 stroke-[2.5]"
          />
          <component :is="stage.icon" v-else class="size-3" />
          <span class="capitalize">{{ stage.label }}</span>
        </div>

        <!-- Arrow divider -->
        <span
          v-if="idx < STAGES.length - 1"
          class="text-muted-foreground/40 text-[10px] font-mono select-none"
        >
          →
        </span>
      </template>
    </div>

    <!-- Right: Contextual Next Action -->
    <div class="flex items-center gap-1.5 text-[11px] truncate pl-3">
      <span
        class="font-bold text-foreground shrink-0 uppercase text-[9.5px] font-mono tracking-wider text-muted-foreground"
      >
        {{ t("common.next", "Next:") }}
      </span>
      <span
        class="truncate font-medium"
        :class="[
          contextualGuidance.success
            ? 'text-emerald-700 dark:text-emerald-300 font-semibold'
            : contextualGuidance.alert
              ? 'text-primary font-semibold'
              : 'text-muted-foreground',
        ]"
      >
        {{ contextualGuidance.text }}
      </span>
    </div>
  </div>
</template>
