<!--
  RefundReviewDialog — a supervisor rules on refunds
  ===================================================
  Approve or decline, both with a reason, and never by the person who asked.
  Rendered only for someone who actually holds cashier.refunds.approve: a
  button a cashier can see and never use teaches them to ignore disabled
  controls.

  Approving pays out of the open drawer, so the refund lands in that session's
  expected cash rather than vanishing into the facility.
-->
<script setup lang="ts">
import { AlertTriangle } from "lucide-vue-next";
import { computed, ref } from "vue";
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
import { useI18nSafe } from "@/composables/useI18nSafe";
import { formatMoney } from "../cashierFormatters";
import type { Refund } from "../composables/useCashierRefunds";

const props = defineProps<{
  open: boolean;
  refunds: Refund[];
  isSubmitting: boolean;
  /** Null when the reviewer has no drawer open — approval needs one. */
  openSessionId: string | null;
}>();

const emit = defineEmits<{
  (e: "update:open", value: boolean): void;
  (
    e: "rule",
    payload: { refundId: string; decision: "approve" | "reject"; reason: string },
  ): void;
}>();

const { t } = useI18nSafe();

const reasons = ref<Record<string, string>>({});

const canApprove = computed(() => props.openSessionId !== null);

function reasonFor(id: string): string {
  return (reasons.value[id] ?? "").trim();
}
</script>

<template>
  <Dialog :open="open" @update:open="emit('update:open', $event)">
    <DialogContent class="sm:max-w-xl">
      <DialogHeader>
        <DialogTitle>{{ t("cashier.refunds") }}</DialogTitle>
        <DialogDescription>{{ t("cashier.refund_decision_reason") }}</DialogDescription>
      </DialogHeader>

      <p
        v-if="!canApprove && refunds.length > 0"
        class="flex items-start gap-2 rounded-md border border-warning/25 bg-warning/5 px-3 py-2 text-xs text-warning"
      >
        <AlertTriangle class="mt-0.5 size-3.5 shrink-0" aria-hidden="true" />
        {{ t("cashier.refund_needs_open_drawer") }}
      </p>

      <div
        v-if="refunds.length === 0"
        class="rounded-md border border-border/70 px-4 py-8 text-center"
      >
        <p class="text-sm font-medium">{{ t("cashier.refund_pending_none") }}</p>
        <p class="text-xs text-muted-foreground">
          {{ t("cashier.refund_pending_none_desc") }}
        </p>
      </div>

      <ul v-else class="flex max-h-96 flex-col gap-2 overflow-y-auto">
        <li
          v-for="refund in refunds"
          :key="refund.id"
          class="rounded-md border border-border/70 px-3 py-2.5"
        >
          <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
              <p class="truncate text-sm font-medium">{{ refund.reason }}</p>
              <p class="text-xs text-muted-foreground">
                {{ refund.refundNumber }} ·
                {{ t("cashier.refund_requested_by", { id: refund.requestedByUserId }) }}
              </p>
            </div>
            <p class="shrink-0 text-sm font-semibold tabular-nums">
              {{ formatMoney(refund.amount, refund.currencyCode) }}
            </p>
          </div>

          <div class="mt-2 flex items-center gap-2">
            <Input
              v-model="reasons[refund.id]"
              class="h-8 flex-1"
              :placeholder="t('cashier.refund_decision_reason')"
              :aria-label="t('cashier.refund_decision_reason')"
            />
            <Button
              variant="outline"
              size="sm"
              class="shrink-0 cursor-pointer"
              :disabled="isSubmitting || reasonFor(refund.id).length < 3"
              @click="
                emit('rule', {
                  refundId: refund.id,
                  decision: 'reject',
                  reason: reasonFor(refund.id),
                })
              "
            >
              {{ t("cashier.refund_reject") }}
            </Button>
            <Button
              size="sm"
              class="shrink-0 cursor-pointer"
              :disabled="isSubmitting || !canApprove"
              @click="
                emit('rule', {
                  refundId: refund.id,
                  decision: 'approve',
                  reason: reasonFor(refund.id),
                })
              "
            >
              {{ t("cashier.refund_approve") }}
            </Button>
          </div>
        </li>
      </ul>

      <DialogFooter>
        <Button variant="ghost" class="cursor-pointer" @click="emit('update:open', false)">
          {{ t("cashier.confirm") }}
        </Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
