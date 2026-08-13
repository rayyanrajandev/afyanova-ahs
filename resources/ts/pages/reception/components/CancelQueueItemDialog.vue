/**
 * CancelQueueItemDialog — Cancel a queued appointment (Volume 2.1 §10.3)
 * ===========================================================================
 * Extracted from reception/Index.vue (2026-08-10, component-library audit).
 * Pure template extraction, no behavior change.
 *
 * `vue/no-mutating-props` is disabled — see ScheduleView.vue's docblock for
 * the full reasoning (same shared-composable-instance pattern).
 */

<script setup lang="ts">
/* eslint-disable vue/no-mutating-props -- see file header docblock */
import { TriangleAlert } from "lucide-vue-next";
import { useI18n } from "vue-i18n";
import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import type { useQueueActions } from "../composables/useQueueActions";

defineProps<{
  queueActions: ReturnType<typeof useQueueActions>;
}>();

const { t } = useI18n();
</script>

<template>
  <Dialog
    :open="queueActions.showCancelDialog.value"
    @update:open="(v) => !v && queueActions.closeCancelDialog()"
  >
    <DialogContent>
      <DialogHeader>
        <DialogTitle>{{ t("queue.cancel_appointment") }}</DialogTitle>
        <DialogDescription>
          {{
            t("queue.cancel_confirm", { name: queueActions.cancelTarget.value?.name ?? "" })
          }}
        </DialogDescription>
      </DialogHeader>

      <div class="space-y-2">
        <label for="cancel-reason" class="text-sm font-medium text-foreground">
          {{ t("queue.cancel_reason_label") }}
        </label>
        <Input
          id="cancel-reason"
          v-model="queueActions.cancelReason.value"
          :aria-label="t('queue.cancel_reason_label')"
          :placeholder="t('queue.cancel_reason_placeholder')"
          @keydown.enter="queueActions.confirmCancelQueueItem"
        />
      </div>

      <DialogFooter>
        <Button variant="secondary" @click="queueActions.closeCancelDialog">
          {{ t("common.cancel") }}
        </Button>
        <Button
          variant="critical"
          class="inline-flex items-center gap-1.5"
          :disabled="!queueActions.cancelReason.value.trim() || queueActions.cancelSubmitting.value"
          @click="queueActions.confirmCancelQueueItem"
        >
          <TriangleAlert class="h-3.5 w-3.5" aria-hidden="true" />
          {{ t("queue.cancel_confirm_action") }}
        </Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
