/**
 * AwaitingPaymentPanel — context-pane Awaiting Payment tab content
 * ================================================================
 * Displays patients whose consultation is awaiting cashier payment before service.
 * Supports call-patient and cancel-appointment actions, with capacity load strip and empty state.
 */

<script setup lang="ts">
import { Megaphone, X } from "lucide-vue-next";
import { useI18n } from "vue-i18n";
import Queue, { type QueueItem } from "@/components/common/Queue.vue";
import { Button } from "@/components/ui/button";
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from "@/components/ui/tooltip";
import QueueLoadStrip from "@/pages/reception/components/QueueLoadStrip.vue";
import { useSyncStore } from "@/stores/syncStore";
import type { useQueueActions } from "../composables/useQueueActions";

defineProps<{
  queueActions: ReturnType<typeof useQueueActions>;
}>();

const { t } = useI18n();
const syncStore = useSyncStore();
</script>

<template>
  <div class="flex min-h-0 flex-1 flex-col overflow-hidden">
    <!-- Wait Times / Capacity Load Strip -->
    <QueueLoadStrip :items="queueActions.awaitingPaymentQueue.value" />

    <!-- Queue Table -->
    <div class="min-h-0 flex-1">
      <Queue
        :items="queueActions.awaitingPaymentQueue.value"
        :loading="queueActions.isAwaitingPaymentLoading.value"
        :error="queueActions.error.value"
        :offline="!syncStore.isOnline"
        persistence-key="afyanova:reception:queue-filters:awaiting_payment"
        :empty-title="t('queue.empty_awaiting_payment_title')"
        :empty-description="t('queue.empty_awaiting_payment_desc')"
        empty-illustration="users"
        :empty-badge="t('queue.stage_awaiting_payment')"
        default-sort="incoming"
        group-by-category
        hide-priority-chips
        @open="queueActions.handleQueueOpen"
        @reorder="queueActions.handleAwaitingPaymentReorder"
        @retry="queueActions.fetchAwaitingPayment"
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
