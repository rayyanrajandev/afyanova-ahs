/** * PharmacyStageBar — Dispensing Workflow Tracker (Volume 2.6) *
============================================================= * Styled
identically to LabStageBar: * - Sequential connected stages with numbered
indicators * - Visual status (done, current, todo, verified) * - Contextual
guidance text on what to do next */

<script setup lang="ts">
import {
  Check,
  CircleDot,
  FileCheck,
  Lock,
  Pill,
  ShieldAlert,
  ShieldCheck,
  XCircle,
} from "lucide-vue-next";
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import { Badge } from "@/components/ui/badge";
import {
  pharmacyStageOf,
  type PharmacyOrder,
  type PharmacyStage,
} from "../composables/usePharmacyOrders";

const props = defineProps<{
  order: PharmacyOrder;
}>();

const { t } = useI18n({ useScope: "global" });

const stage = computed<PharmacyStage>(() => pharmacyStageOf(props.order));

const PHARMACY_STAGES = [
  "pending_review",
  "ready_for_dispense",
  "dispensed_unverified",
] as const;

const STEP_META: Record<
  (typeof PHARMACY_STAGES)[number],
  { icon: typeof Pill; label: string }
> = {
  pending_review: {
    icon: ShieldAlert,
    label: "1. Safety & Stock",
  },
  ready_for_dispense: {
    icon: Pill,
    label: "2. Fill & Label",
  },
  dispensed_unverified: {
    icon: ShieldCheck,
    label: "3. Verify & Release",
  },
};

function stepState(index: number): "done" | "current" | "todo" {
  if (stage.value === "verified_completed") return "done";

  const stageIndexMap: Record<PharmacyStage, number> = {
    pending_review: 0,
    ready_for_dispense: 1,
    dispensed_unverified: 2,
    verified_completed: 3,
    cancelled: -1,
  };

  const currentIndex = stageIndexMap[stage.value] ?? 0;
  if (index < currentIndex) return "done";
  if (index === currentIndex) return "current";
  return "todo";
}

const nextAction = computed<{ text: string; blocked: boolean }>(() => {
  switch (stage.value) {
    case "pending_review":
      return {
        text: "Review patient drug allergies and stock on hand. Accept order to begin filling.",
        blocked: false,
      };
    case "ready_for_dispense":
      return {
        text: "Select inventory batch, package medication, and print thermal label.",
        blocked: false,
      };
    case "dispensed_unverified":
      return {
        text: "Medication physically prepared. Senior pharmacist must review and sign off.",
        blocked: false,
      };
    case "verified_completed":
      return {
        text: "Dispensation finalized and released to patient EMR and billing ledger.",
        blocked: false,
      };
    default:
      return {
        text: "Prescription was cancelled or discontinued by provider.",
        blocked: true,
      };
  }
});
</script>

<template>
  <div
    class="shrink-0 border-b border-border bg-muted/20 px-4 py-2 flex flex-wrap items-center justify-between gap-3 w-full min-w-0"
    role="group"
    aria-label="Pharmacy dispensing workflow progress"
  >
    <!-- Left: The three dispensing steps -->
    <ol class="flex items-center gap-1 overflow-x-auto no-scrollbar shrink-0">
      <li
        v-for="(stepKey, index) in PHARMACY_STAGES"
        :key="stepKey"
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
        >
          <Check v-if="stepState(index) === 'done'" class="size-3.5 shrink-0" />
          <component
            :is="STEP_META[stepKey].icon"
            v-else
            class="size-3.5 shrink-0"
          />

          <span class="whitespace-nowrap">
            {{ STEP_META[stepKey].label }}
          </span>
        </div>

        <span
          v-if="index < PHARMACY_STAGES.length - 1"
          class="h-px w-3 shrink-0"
          :class="
            stepState(index) === 'done' ? 'bg-emerald-500/50' : 'bg-border'
          "
          aria-hidden="true"
        />
      </li>

      <!-- Terminal Verified / Cancelled Outcome -->
      <li
        v-if="stage === 'verified_completed' || stage === 'cancelled'"
        class="flex items-center gap-1 shrink-0 ml-1"
      >
        <span class="h-px w-3 shrink-0 bg-border" aria-hidden="true" />
        <Badge
          variant="outline"
          class="gap-1 px-2 py-1 text-[11px] font-bold uppercase"
          :class="
            stage === 'verified_completed'
              ? 'border-emerald-500/50 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300'
              : 'border-rose-500/50 bg-rose-500/10 text-rose-600'
          "
        >
          <ShieldCheck v-if="stage === 'verified_completed'" class="size-3.5" />
          <XCircle v-else class="size-3.5" />
          {{ stage === "verified_completed" ? "Verified" : "Cancelled" }}
        </Badge>
      </li>
    </ol>

    <!-- Right: Next Step Guidance -->
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
        <strong class="text-foreground font-semibold">Next:</strong>
        {{ nextAction.text }}
      </p>
    </div>
  </div>
</template>
