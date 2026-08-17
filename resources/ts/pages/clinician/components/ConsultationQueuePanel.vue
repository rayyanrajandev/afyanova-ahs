/**
 * ConsultationQueuePanel — Clinician Work Queue (Volume 2.2 §4.1)
 * ================================================================
 * Context-pane Queue tab content for the Clinician workstation.
 * Features 4 clinical stages: Waiting Doctor -> In Consult -> Admitted Review -> Completed.
 */

<script setup lang="ts">
import {
  BedDouble,
  CheckCircle2,
  Clock,
  RefreshCw,
  Stethoscope,
  UserCheck,
} from "lucide-vue-next";
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import Queue, { type QueueItem } from "@/components/common/Queue.vue";
import { Button } from "@/components/ui/button";
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from "@/components/ui/tooltip";
import type { useClinicianQueue } from "../composables/useClinicianQueue";

const props = defineProps<{
  queueActions: ReturnType<typeof useClinicianQueue>;
}>();

const { t } = useI18n({ useScope: "global" });

const emptyStateConfig = computed(() => {
  switch (props.queueActions.selectedStage.value) {
    case "waiting_provider":
      return {
        title: t("clinician.empty_waiting_title"),
        description: t("clinician.empty_waiting_desc"),
        illustration: "stethoscope" as const,
        badge: t("clinician.stage_waiting_doctor"),
      };
    case "in_consultation":
      return {
        title: t("clinician.empty_in_consult_title"),
        description: t("clinician.empty_in_consult_desc"),
        illustration: "stethoscope" as const,
        badge: t("clinician.stage_in_consult"),
      };
    case "admitted":
      return {
        title: t("clinician.empty_admitted_title"),
        description: t("clinician.empty_admitted_desc"),
        illustration: "clipboard" as const,
        badge: t("clinician.stage_admitted_review"),
      };
    case "completed":
      return {
        title: t("clinician.empty_completed_title"),
        description: t("clinician.empty_completed_desc"),
        illustration: "clipboard" as const,
        badge: t("clinician.stage_completed"),
      };
    default:
      return {
        title: t("queue.empty_no_patients_title"),
        description: t("queue.empty_no_patients_hint"),
        illustration: "stethoscope" as const,
        badge: undefined,
      };
  }
});
</script>

<template>
  <div class="flex min-h-0 flex-1 flex-col overflow-hidden">
    <!-- Stage Switcher Segmented Toolbar -->
    <div class="border-b border-border/80 bg-surface px-2 py-1.5 shrink-0">
      <div class="grid grid-cols-4 gap-1 rounded-lg bg-muted/70 p-0.5 text-xs font-medium">
        <!-- 1. Waiting Doctor -->
        <button
          type="button"
          class="flex items-center justify-center gap-1 rounded-md py-1 px-1 transition-all cursor-pointer"
          :class="
            queueActions.selectedStage.value === 'waiting_provider'
              ? 'bg-card text-foreground font-semibold'
              : 'text-muted-foreground hover:text-foreground'
          "
          @click="queueActions.setStage('waiting_provider')"
        >
          <UserCheck class="size-3 text-emerald-600 dark:text-emerald-400 shrink-0" />
          <span class="truncate text-[10.5px]">{{ t("clinician.stage_waiting_doctor") }}</span>
          <span
            class="rounded-full px-1.5 py-0 text-[9.5px]"
            :class="
              queueActions.selectedStage.value === 'waiting_provider'
                ? 'bg-emerald-500/15 text-emerald-600 dark:text-emerald-400 font-bold'
                : 'bg-secondary text-muted-foreground'
            "
          >
            {{ queueActions.stageCounts.value.waiting_provider ?? 0 }}
          </span>
        </button>

        <!-- 2. In Consult -->
        <button
          type="button"
          class="flex items-center justify-center gap-1 rounded-md py-1 px-1 transition-all cursor-pointer"
          :class="
            queueActions.selectedStage.value === 'in_consultation'
              ? 'bg-card text-foreground font-semibold'
              : 'text-muted-foreground hover:text-foreground'
          "
          @click="queueActions.setStage('in_consultation')"
        >
          <Stethoscope class="size-3 text-blue-600 dark:text-blue-400 shrink-0" />
          <span class="truncate text-[10.5px]">{{ t("clinician.stage_in_consult") }}</span>
          <span
            class="rounded-full px-1.5 py-0 text-[9.5px]"
            :class="
              queueActions.selectedStage.value === 'in_consultation'
                ? 'bg-blue-500/15 text-blue-600 dark:text-blue-400 font-bold'
                : 'bg-secondary text-muted-foreground'
            "
          >
            {{ queueActions.stageCounts.value.in_consultation ?? 0 }}
          </span>
        </button>

        <!-- 3. Admitted Review -->
        <button
          type="button"
          class="flex items-center justify-center gap-1 rounded-md py-1 px-1 transition-all cursor-pointer"
          :class="
            queueActions.selectedStage.value === 'admitted'
              ? 'bg-card text-foreground font-semibold'
              : 'text-muted-foreground hover:text-foreground'
          "
          @click="queueActions.setStage('admitted')"
        >
          <BedDouble class="size-3 text-indigo-600 dark:text-indigo-400 shrink-0" />
          <span class="truncate text-[10.5px]">{{ t("clinician.stage_admitted_review") }}</span>
          <span
            class="rounded-full px-1.5 py-0 text-[9.5px]"
            :class="
              queueActions.selectedStage.value === 'admitted'
                ? 'bg-indigo-500/15 text-indigo-600 dark:text-indigo-400 font-bold'
                : 'bg-secondary text-muted-foreground'
            "
          >
            {{ queueActions.stageCounts.value.admitted ?? 0 }}
          </span>
        </button>

        <!-- 4. Completed -->
        <button
          type="button"
          class="flex items-center justify-center gap-1 rounded-md py-1 px-1 transition-all cursor-pointer"
          :class="
            queueActions.selectedStage.value === 'completed'
              ? 'bg-card text-foreground font-semibold'
              : 'text-muted-foreground hover:text-foreground'
          "
          @click="queueActions.setStage('completed')"
        >
          <CheckCircle2 class="size-3 text-muted-foreground shrink-0" />
          <span class="truncate text-[10.5px]">{{ t("clinician.stage_completed") }}</span>
          <span
            class="rounded-full px-1.5 py-0 text-[9.5px]"
            :class="
              queueActions.selectedStage.value === 'completed'
                ? 'bg-muted text-foreground font-bold'
                : 'bg-secondary text-muted-foreground'
            "
          >
            {{ queueActions.stageCounts.value.completed ?? 0 }}
          </span>
        </button>
      </div>
    </div>

    <!-- Quick Refresh Bar -->
    <div class="flex items-center justify-between border-b border-border/60 bg-surface/50 px-3 py-1.5 text-xs text-muted-foreground">
      <div class="flex items-center gap-1.5">
        <Clock class="size-3" />
        <span class="font-mono text-[11px]">
          {{ queueActions.queueItems.value.length }} {{ t("clinician.patients") }}
        </span>
      </div>
      <TooltipProvider :delay-duration="200">
        <Tooltip>
          <TooltipTrigger as-child>
            <Button
              variant="ghost"
              size="sm"
              class="h-6 w-6 p-0 cursor-pointer text-muted-foreground hover:text-foreground"
              @click="queueActions.refreshQueue"
            >
              <RefreshCw class="size-3" :class="{ 'animate-spin': queueActions.isLoading.value }" />
            </Button>
          </TooltipTrigger>
          <TooltipContent>{{ t("common.retry") }}</TooltipContent>
        </Tooltip>
      </TooltipProvider>
    </div>

    <!-- Shared Queue Component (Volume 1.2 §9) -->
    <div class="flex-1 min-h-0 overflow-hidden">
      <Queue
        :items="queueActions.queueItems.value"
        :loading="queueActions.isLoading.value"
        :error="queueActions.error.value"
        :persistence-key="`afyanova:clinician:queue:${queueActions.selectedStage.value}`"
        :empty-title="emptyStateConfig.title"
        :empty-description="emptyStateConfig.description"
        :empty-illustration="emptyStateConfig.illustration"
        :empty-badge="emptyStateConfig.badge"
        hide-priority-chips
        default-sort="wait"
        @open="queueActions.handleOpenItem"
        @retry="queueActions.refreshQueue"
      />
    </div>
  </div>
</template>
