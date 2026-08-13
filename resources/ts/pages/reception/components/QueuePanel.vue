/**
 * QueuePanel — context-pane Queue tab content (Volume 2.1 §10)
 * =================================================================
 * Extracted from reception/Index.vue (2026-08-10, component-library audit).
 * Pure template extraction — the Queue composite plus its Cancel row
 * action, unchanged.
 *
 * `default-sort="incoming"` added 2026-08-10 (Volume 3.7 T5.1) — trusts
 * `GetReceptionQueueUseCase`'s own emergency > scheduled > walk-in,
 * oldest-wait-first ordering (Volume 2.1 §10.2) instead of Queue.vue's
 * generic wait-derived `priority` default, which is a different axis (see
 * `useQueueActions.ts`'s `tierLabel` doc comment). Staff can still switch
 * to Priority/Wait/Name via the Filters popover — this only changes what
 * they see before touching it.
 *
 * `@reorder` wired 2026-08-10 (Volume 3.7 T5.5) — persists a drag-to-reorder
 * via `useQueueActions.ts`'s `handleQueueReorder`, which refetches either
 * way (success or a tier-floor rejection) rather than trusting Queue.vue's
 * own optimistic local order.
 *
 * `group-by-category` added 2026-08-10 (component-library audit, following
 * the SplitPane resizable-context-pane work) — groups rows under
 * Emergency/Scheduled/Walk-in section headers instead of a small text label
 * per row, now that the wider pane has room for it.
 *
 * `hide-priority-chips` added 2026-08-12 (direct user feedback: "seems like
 * they are useless" on the Critical/Urgent/Normal chips) — this queue is
 * `stage=waiting_triage`, i.e. every row is a patient who hasn't been
 * triaged yet, so there's no clinical acuity for Queue.vue's generic
 * wait-time-bucketed `priority` to reflect. It was actively misleading: a
 * just-arrived Emergency patient (0 min wait) showed "Normal", the same
 * bucket as a routine walk-in. The real urgency signal here is arrival-mode
 * tiering, which `group-by-category`/`default-sort="incoming"` above
 * already surface correctly — still reachable as a Category filter inside
 * the Filters popover, which this prop leaves untouched.
 */

<script setup lang="ts">
import { Megaphone, X } from "lucide-vue-next";
import { useI18n } from "vue-i18n";
import Queue, { type QueueItem } from "@/components/common/Queue.vue";
import { Button } from "@/components/ui/button";
import { Tooltip, TooltipContent, TooltipTrigger } from "@/components/ui/tooltip";
import type { useQueueActions } from "../composables/useQueueActions";

defineProps<{
  queueActions: ReturnType<typeof useQueueActions>;
}>();

const { t } = useI18n();
</script>

<template>
  <Queue
    :items="queueActions.queue.value"
    default-sort="incoming"
    group-by-category
    hide-priority-chips
    @open="queueActions.handleQueueOpen"
    @reorder="queueActions.handleQueueReorder"
  >
    <!-- Cancel (Volume 2.1 §10.3) — the only status-CHANGING action valid
         on this queue view; see queueStore.ts for why. Tooltip, not the
         native `title` this used to carry (workspace tooltip audit,
         2026-08-11) — same design-system component AppShell.vue's own
         collapsed-nav tooltips use, not the browser default.

         Call (§10.3, §16 #3, 2026-08-11) — an ephemeral broadcast, not a
         status change (see AppointmentCalled's docblock); sits alongside
         Cancel as a second row action, not a replacement for it. No
         confirm dialog, unlike Cancel — calling a patient forward isn't a
         destructive/hard-to-reverse action the way removing them from the
         queue is. -->
    <template #row-actions="{ item }: { item: QueueItem }">
      <Tooltip>
        <TooltipTrigger as-child>
          <Button
            variant="ghost"
            size="sm"
            class="h-6 w-6 shrink-0 p-0 text-muted-foreground hover:text-foreground"
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
            class="h-6 w-6 shrink-0 p-0 text-muted-foreground hover:text-critical"
            :aria-label="t('queue.cancel_appointment')"
            @click="queueActions.openCancelDialog(item)"
          >
            <X class="h-3.5 w-3.5" aria-hidden="true" />
          </Button>
        </TooltipTrigger>
        <TooltipContent>{{ t("queue.cancel_appointment") }}</TooltipContent>
      </Tooltip>
    </template>
  </Queue>
</template>
