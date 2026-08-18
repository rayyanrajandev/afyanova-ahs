/** * LabStageBar — The Bench Workflow Tracker (Volume 2.4) *
====================================================== * The workspace
previously had no visible notion of "which step am I on". * Every action button
was live at once, so a technician could release a report * for a specimen that
had never arrived. This bar is the answer to the only * question a lab tech has
when they open an order: *what do I do next?* * * It renders the four bench
steps in order, marks exactly one as current, and * states the next action in
plain language. It is display-only — the buttons * live in the tab that owns
each step, so there is never more than one place to * click for a given step. */

<script setup lang="ts">
import {
  Check,
  CircleDot,
  FlaskConical,
  Lock,
  ShieldCheck,
  TestTube2,
  XCircle,
} from "lucide-vue-next";
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import { Badge } from "@/components/ui/badge";
import {
  LAB_STAGE_SEQUENCE,
  benchStepIndex,
  labStageOf,
  missingParameters,
  type LabBenchStep,
  type LabStage,
  type LaboratoryOrder,
} from "../composables/useLaboratoryOrders";

const props = defineProps<{
  order: LaboratoryOrder;
}>();

const { t } = useI18n({ useScope: "global" });

const stage = computed<LabStage>(() => labStageOf(props.order));

const STEP_META: Record<
  LabBenchStep,
  { icon: typeof TestTube2; labelKey: string; fallback: string }
> = {
  awaiting_specimen: {
    icon: TestTube2,
    labelKey: "laboratory.step_receive",
    fallback: "Receive Specimen",
  },
  ready_for_analysis: {
    icon: FlaskConical,
    labelKey: "laboratory.step_analyse",
    fallback: "Start Analysis",
  },
  in_analysis: {
    icon: CircleDot,
    labelKey: "laboratory.step_results",
    fallback: "Enter Results",
  },
  awaiting_release: {
    icon: ShieldCheck,
    labelKey: "laboratory.step_release",
    fallback: "Review & Release",
  },
};

/** Index of the step the technician is standing on right now. */
const currentIndex = computed(() => {
  if (stage.value === "released") return LAB_STAGE_SEQUENCE.length;
  if (stage.value === "rejected") return -1;

  return benchStepIndex(stage.value);
});

function stepState(index: number): "done" | "current" | "todo" {
  if (stage.value === "rejected") return "todo";
  if (index < currentIndex.value) return "done";

  return index === currentIndex.value ? "current" : "todo";
}

/**
 * The single sentence that tells the technician what to do now, and — when the
 * step is blocked — precisely what is blocking it.
 */
const nextAction = computed<{ text: string; blocked: boolean }>(() => {
  switch (stage.value) {
    case "awaiting_specimen":
      return {
        text: t(
          "laboratory.next_receive",
          "The specimen has not reached the bench yet. Accept it below to begin, or reject it if it is unusable.",
        ),
        blocked: false,
      };
    case "ready_for_analysis":
      return {
        text: t(
          "laboratory.next_analyse",
          "Specimen received. Start analysis to unlock the result entry sheet.",
        ),
        blocked: false,
      };
    case "in_analysis": {
      const missing = missingParameters(props.order);
      if (missing.length > 0) {
        return {
          text: t("laboratory.next_results_missing", {
            count: missing.length,
            names: missing.map((p) => p.name).join(", "),
          }),
          blocked: true,
        };
      }

      return {
        text: t(
          "laboratory.next_results_ready",
          "All parameters entered. Save the results to produce a draft report.",
        ),
        blocked: false,
      };
    }
    case "awaiting_release":
      return {
        text: t(
          "laboratory.next_release",
          "Draft report saved — no clinician can see it yet. Review it and release it to the patient chart.",
        ),
        blocked: false,
      };
    case "released":
      return {
        text: t(
          "laboratory.next_released",
          "This report is final and visible on the patient chart. Nothing further is required.",
        ),
        blocked: false,
      };
    default:
      return {
        text: t(
          "laboratory.next_rejected",
          "This specimen was rejected and the order is closed. A fresh specimen needs a new order from the clinician.",
        ),
        blocked: true,
      };
  }
});

const isTerminal = computed(
  () => stage.value === "released" || stage.value === "rejected",
);
</script>

<template>
  <div
    class="shrink-0 border-b border-border bg-muted/20 px-4 py-2 flex flex-wrap items-center justify-between gap-3"
    role="group"
    :aria-label="
      t('laboratory.stage_bar_label', 'Laboratory bench workflow progress')
    "
  >
    <!-- Left: The four bench steps -->
    <ol class="flex items-center gap-1 overflow-x-auto no-scrollbar shrink-0">
      <li
        v-for="(step, index) in LAB_STAGE_SEQUENCE"
        :key="step"
        class="flex items-center gap-1 shrink-0"
      >
        <div
          class="flex items-center gap-1.5 rounded-md border px-2 py-1 text-[11px] font-semibold transition-colors"
          :class="{
            'border-emerald-500/40 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300':
              stepState(index) === 'done',
            'border-primary bg-primary text-primary-foreground shadow-2xs':
              stepState(index) === 'current',
            'border-border/70 bg-surface text-muted-foreground':
              stepState(index) === 'todo',
          }"
          :aria-current="stepState(index) === 'current' ? 'step' : undefined"
        >
          <Check v-if="stepState(index) === 'done'" class="size-3.5 shrink-0" />
          <component
            :is="STEP_META[step].icon"
            v-else
            class="size-3.5 shrink-0"
          />

          <span class="whitespace-nowrap">
            {{ index + 1 }}.
            {{ t(STEP_META[step].labelKey, STEP_META[step].fallback) }}
          </span>
        </div>

        <span
          v-if="index < LAB_STAGE_SEQUENCE.length - 1"
          class="h-px w-3 shrink-0"
          :class="
            stepState(index) === 'done' ? 'bg-emerald-500/50' : 'bg-border'
          "
          aria-hidden="true"
        />
      </li>

      <!-- Terminal outcome, shown instead of a fifth step -->
      <li v-if="isTerminal" class="flex items-center gap-1 shrink-0 ml-1">
        <span class="h-px w-3 shrink-0 bg-border" aria-hidden="true" />
        <Badge
          variant="outline"
          class="gap-1 px-2 py-1 text-[11px] font-bold uppercase"
          :class="
            stage === 'released'
              ? 'border-emerald-500/50 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300'
              : 'border-rose-500/50 bg-rose-500/10 text-rose-600'
          "
        >
          <ShieldCheck v-if="stage === 'released'" class="size-3.5" />
          <XCircle v-else class="size-3.5" />
          {{
            stage === "released"
              ? t("laboratory.stage_released", "Released")
              : t("laboratory.stage_rejected", "Rejected")
          }}
        </Badge>
      </li>
    </ol>

    <!-- Right: What to do now (Next step guidance on same horizontal bar) -->
    <div
      class="flex items-center gap-1.5 text-xs min-w-0 max-w-xl"
      :class="
        nextAction.blocked
          ? 'text-amber-700 dark:text-amber-300'
          : 'text-muted-foreground'
      "
    >
      <Lock v-if="nextAction.blocked" class="size-3.5 shrink-0" />
      <CircleDot v-else class="size-3.5 shrink-0 text-primary" />
      <p class="truncate text-[11.5px]">
        <strong class="text-foreground font-semibold">{{
          t("laboratory.next_step_label", "Next:")
        }}</strong>
        {{ nextAction.text }}
      </p>
    </div>
  </div>
</template>
