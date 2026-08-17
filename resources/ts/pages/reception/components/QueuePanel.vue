/**
 * QueuePanel — context-pane Queue tab content (Volume 2.1 §10)
 * =================================================================
 * Extracted from reception/Index.vue (2026-08-10, component-library audit).
 * Pure template extraction — the Queue composite plus its Cancel row action.
 *
 * Enhanced 2026-08-14: Multi-stage patient journey flow switcher
 * (Triage -> Waiting Doctor -> In Consultation) with live badge counters.
 */

<script setup lang="ts">
import { Activity, BedDouble, Megaphone, Stethoscope, UserCheck, X } from "lucide-vue-next";
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import Queue, { type QueueItem } from "@/components/common/Queue.vue";
import { Button } from "@/components/ui/button";
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from "@/components/ui/tooltip";
import QueueLoadStrip from "@/pages/reception/components/QueueLoadStrip.vue";
import { useSyncStore } from "@/stores/syncStore";
import type { useQueueActions } from "../composables/useQueueActions";

const props = defineProps<{
  queueActions: ReturnType<typeof useQueueActions>;
}>();

const { t } = useI18n();
const syncStore = useSyncStore();

const emptyStateConfig = computed(() => {
  switch (props.queueActions.selectedStage.value) {
    case "waiting_triage":
      return {
        title: t("queue.empty_triage_title"),
        description: t("queue.empty_triage_desc"),
        illustration: "users" as const,
        badge: t("queue.stage_triage"),
      };
    case "waiting_provider":
      return {
        title: t("queue.empty_provider_title"),
        description: t("queue.empty_provider_desc"),
        illustration: "stethoscope" as const,
        badge: t("queue.stage_wait_doctor"),
      };
    case "in_consultation":
      return {
        title: t("queue.empty_in_consult_title"),
        description: t("queue.empty_in_consult_desc"),
        illustration: "stethoscope" as const,
        badge: t("queue.stage_in_consult"),
      };
    case "admitted":
      return {
        title: t("queue.empty_admitted_title"),
        description: t("queue.empty_admitted_desc"),
        illustration: "clipboard" as const,
        badge: t("queue.stage_admitted"),
      };
    default:
      return {
        title: t("queue.empty_no_patients_title"),
        description: t("queue.empty_no_patients_hint"),
        illustration: "users" as const,
        badge: undefined,
      };
  }
});
</script>

<template>
  <div class="flex min-h-0 flex-1 flex-col overflow-hidden">
    <!-- 2027 Patient Flow Stage Switcher (Triage -> Wait Doctor -> In Consult -> Admitted) -->
    <div class="border-b border-border/80 bg-surface px-3 py-2 shrink-0">
      <div class="grid grid-cols-4 gap-1 rounded-lg bg-muted/70 p-1 text-xs font-medium">
        <button
          type="button"
          class="flex items-center justify-center gap-1.5 rounded-md py-1.5 px-2 transition-all cursor-pointer"
          :class="
            queueActions.selectedStage.value === 'waiting_triage'
              ? 'bg-card text-foreground font-semibold shadow-2xs'
              : 'text-muted-foreground hover:text-foreground'
          "
          @click="queueActions.setStage('waiting_triage')"
        >
          <Activity class="size-3.5 text-primary shrink-0" />
          <span class="truncate">{{ t("queue.stage_triage") }}</span>
          <span
            class="rounded-full px-1.5 py-0.2 text-[10px]"
            :class="
              queueActions.selectedStage.value === 'waiting_triage'
                ? 'bg-primary/15 text-primary font-bold'
                : 'bg-secondary text-muted-foreground'
            "
          >
            {{ queueActions.stageCounts.value.waiting_triage ?? 0 }}
          </span>
        </button>

        <button
          type="button"
          class="flex items-center justify-center gap-1.5 rounded-md py-1.5 px-2 transition-all cursor-pointer"
          :class="
            queueActions.selectedStage.value === 'waiting_provider'
              ? 'bg-card text-foreground font-semibold shadow-2xs'
              : 'text-muted-foreground hover:text-foreground'
          "
          @click="queueActions.setStage('waiting_provider')"
        >
          <UserCheck class="size-3.5 text-emerald-600 dark:text-emerald-400 shrink-0" />
          <span class="truncate">{{ t("queue.stage_wait_doctor") }}</span>
          <span
            class="rounded-full px-1.5 py-0.2 text-[10px]"
            :class="
              queueActions.selectedStage.value === 'waiting_provider'
                ? 'bg-emerald-500/15 text-emerald-600 dark:text-emerald-400 font-bold'
                : 'bg-secondary text-muted-foreground'
            "
          >
            {{ queueActions.stageCounts.value.waiting_provider ?? 0 }}
          </span>
        </button>

        <button
          type="button"
          class="flex items-center justify-center gap-1.5 rounded-md py-1.5 px-2 transition-all cursor-pointer"
          :class="
            queueActions.selectedStage.value === 'in_consultation'
              ? 'bg-card text-foreground font-semibold shadow-2xs'
              : 'text-muted-foreground hover:text-foreground'
          "
          @click="queueActions.setStage('in_consultation')"
        >
          <Stethoscope class="size-3.5 text-blue-600 dark:text-blue-400 shrink-0" />
          <span class="truncate">{{ t("queue.stage_in_consult") }}</span>
          <span
            class="rounded-full px-1.5 py-0.2 text-[10px]"
            :class="
              queueActions.selectedStage.value === 'in_consultation'
                ? 'bg-blue-500/15 text-blue-600 dark:text-blue-400 font-bold'
                : 'bg-secondary text-muted-foreground'
            "
          >
            {{ queueActions.stageCounts.value.in_consultation ?? 0 }}
          </span>
        </button>

        <button
          type="button"
          class="flex items-center justify-center gap-1.5 rounded-md py-1.5 px-2 transition-all cursor-pointer"
          :class="
            queueActions.selectedStage.value === 'admitted'
              ? 'bg-card text-foreground font-semibold shadow-2xs'
              : 'text-muted-foreground hover:text-foreground'
          "
          @click="queueActions.setStage('admitted')"
        >
          <BedDouble class="size-3.5 text-indigo-600 dark:text-indigo-400 shrink-0" />
          <span class="truncate">{{ t("queue.stage_admitted") }}</span>
          <span
            class="rounded-full px-1.5 py-0.2 text-[10px]"
            :class="
              queueActions.selectedStage.value === 'admitted'
                ? 'bg-indigo-500/15 text-indigo-600 dark:text-indigo-400 font-bold'
                : 'bg-secondary text-muted-foreground'
            "
          >
            {{ queueActions.stageCounts.value.admitted ?? 0 }}
          </span>
        </button>
      </div>
    </div>

    <!-- Wait Times / Capacity Load Strip -->
    <QueueLoadStrip :items="queueActions.queue.value" />

    <!-- Queue Table -->
    <div class="min-h-0 flex-1">
      <Queue
        :items="queueActions.queue.value"
        :loading="queueActions.isLoading.value"
        :error="queueActions.error.value"
        :offline="!syncStore.isOnline"
        :persistence-key="`afyanova:reception:queue-filters:${queueActions.selectedStage.value}`"
        :empty-title="emptyStateConfig.title"
        :empty-description="emptyStateConfig.description"
        :empty-illustration="emptyStateConfig.illustration"
        :empty-badge="emptyStateConfig.badge"
        default-sort="incoming"
        group-by-category
        hide-priority-chips
        @open="queueActions.handleQueueOpen"
        @reorder="queueActions.handleQueueReorder"
        @retry="queueActions.refetchQueue"
      >
        <template #row-actions="{ item }: { item: QueueItem }">
          <TooltipProvider :delay-duration="200">
            <Tooltip>
              <TooltipTrigger as-child>
                <Button
                  variant="ghost"
                  size="sm"
                  class="h-6 w-6 shrink-0 p-0 text-muted-foreground hover:text-foreground cursor-pointer"
                  :aria-label="t('queue.call_patient')"
                  @click="queueActions.callQueueItem(item)"
                >
                  <Megaphone class="h-3.5 w-3.5" aria-hidden="true" />
                </Button>
              </TooltipTrigger>
              <TooltipContent>{{ t("queue.call_patient") }}</TooltipContent>
            </Tooltip>
            <Tooltip>
              <TooltipTrigger as-child>
                <Button
                  variant="ghost"
                  size="sm"
                  class="h-6 w-6 shrink-0 p-0 text-muted-foreground hover:text-critical cursor-pointer"
                  :aria-label="t('queue.cancel_appointment')"
                  @click="queueActions.openCancelDialog(item)"
                >
                  <X class="h-3.5 w-3.5" aria-hidden="true" />
                </Button>
              </TooltipTrigger>
              <TooltipContent>{{ t("queue.cancel_appointment") }}</TooltipContent>
            </Tooltip>
          </TooltipProvider>
        </template>
      </Queue>
    </div>
  </div>
</template>
