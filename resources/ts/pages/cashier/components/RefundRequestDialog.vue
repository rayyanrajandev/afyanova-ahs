<!--
  RefundRequestDialog — ask for money back
  =========================================
  A refund is raised against a specific payment, not against a balance: the
  cashier picks the note that was taken, so the record says which one and the
  amount is bounded by what that payment can still give back.

  Requesting is all this does. Nothing leaves the drawer until someone else
  approves it.
-->
<script setup lang="ts">
import { computed, ref, watch } from "vue";
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
import { Label } from "@/components/ui/label";
import { useI18nSafe } from "@/composables/useI18nSafe";
import { decimalToMinor, formatMoney, fromAmountInput, toAmountInput } from "../cashierFormatters";
import type { PatientPayment } from "../composables/useCashierRefunds";

const props = defineProps<{
  open: boolean;
  payments: PatientPayment[];
  isLoading: boolean;
  isSubmitting: boolean;
}>();

const emit = defineEmits<{
  (e: "update:open", value: boolean): void;
  (e: "confirm", payload: { paymentId: string; amountMinor: number; reason: string }): void;
  (e: "reverse", payload: { paymentId: string; reason: string }): void;
}>();

const { t } = useI18nSafe();

const selected = ref<PatientPayment | null>(null);
const amount = ref("0");
const reason = ref("");

watch(
  () => props.open,
  (open) => {
    if (!open) return;
    selected.value = null;
    amount.value = "0";
    reason.value = "";
  },
);

function pick(payment: PatientPayment): void {
  if (!payment.isRefundable) return;

  selected.value = payment;
  // Default to the whole refundable amount: a full refund is the common case,
  // and a partial one is a deliberate edit rather than a number to assemble.
  amount.value = toAmountInput(decimalToMinor(payment.refundable));
}

const amountMinor = computed(() => fromAmountInput(amount.value));

const exceedsRefundable = computed(
  () =>
    selected.value !== null &&
    amountMinor.value > decimalToMinor(selected.value.refundable),
);

const canSubmit = computed(
  () =>
    selected.value !== null &&
    amountMinor.value > 0 &&
    !exceedsRefundable.value &&
    reason.value.trim().length >= 3 &&
    !props.isSubmitting,
);
</script>

<template>
  <Dialog :open="open" @update:open="emit('update:open', $event)">
    <DialogContent class="sm:max-w-md">
      <DialogHeader>
        <DialogTitle>{{ t("cashier.refund_request") }}</DialogTitle>
        <DialogDescription>{{ t("cashier.refund_pick_payment") }}</DialogDescription>
      </DialogHeader>

      <div class="flex flex-col gap-4">
        <p
          v-if="!isLoading && payments.length === 0"
          class="rounded-md border border-border/70 px-3 py-4 text-center text-xs text-muted-foreground"
        >
          {{ t("cashier.refund_no_payments") }}
        </p>

        <ul v-else class="max-h-52 overflow-y-auto rounded-md border border-border/70">
          <li v-for="payment in payments" :key="payment.id">
            <button
              type="button"
              class="flex w-full items-center justify-between gap-3 px-3 py-2 text-left transition-colors"
              :class="[
                payment.isRefundable
                  ? 'cursor-pointer hover:bg-muted/60'
                  : 'cursor-not-allowed opacity-60',
                selected?.id === payment.id && 'bg-primary/10',
              ]"
              :disabled="!payment.isRefundable"
              @click="pick(payment)"
            >
              <span class="min-w-0">
                <span class="block truncate text-sm font-medium">
                  {{ payment.receiptNumber ?? payment.paymentNumber }}
                </span>
                <span class="block text-xs text-muted-foreground">
                  <template v-if="payment.status !== 'recorded'">
                    {{ t("cashier.refund_reversed") }}
                  </template>
                  <template v-else-if="payment.alreadyRefunded !== '0.00'">
                    {{
                      t("cashier.refund_already_refunded", {
                        amount: formatMoney(payment.alreadyRefunded, payment.currencyCode),
                      })
                    }}
                  </template>
                  <template v-else>{{ payment.paymentNumber }}</template>
                </span>
              </span>
              <span class="shrink-0 text-sm font-semibold tabular-nums">
                {{ formatMoney(payment.refundable, payment.currencyCode) }}
              </span>
            </button>
          </li>
        </ul>

        <div class="flex flex-col gap-1.5">
          <Label for="cashier-refund-amount">{{ t("cashier.refund_amount") }}</Label>
          <Input
            id="cashier-refund-amount"
            v-model="amount"
            type="number"
            min="0"
            step="1"
            class="h-10 tabular-nums"
            :disabled="selected === null"
            :aria-invalid="exceedsRefundable"
          />
          <p v-if="exceedsRefundable" class="text-xs font-medium text-critical">
            {{ t("cashier.refund_over_refundable") }}
          </p>
        </div>

        <div class="flex flex-col gap-1.5">
          <Label for="cashier-refund-reason">{{ t("cashier.reason") }}</Label>
          <Input id="cashier-refund-reason" v-model="reason" class="h-9" />
          <p class="text-xs text-muted-foreground">{{ t("cashier.reason_required") }}</p>
        </div>
      </div>

      <DialogFooter>
        <Button variant="ghost" class="cursor-pointer" @click="emit('update:open', false)">
          {{ t("cashier.cancel") }}
        </Button>
        <Button
          v-if="selected && fromAmountInput(amount) === decimalToMinor(selected.refundable)"
          variant="outline"
          class="cursor-pointer text-warning border-warning hover:bg-warning/10 hover:text-warning"
          :disabled="reason.trim().length < 3 || isSubmitting"
          @click="
            selected &&
              emit('reverse', {
                paymentId: selected.id,
                reason: reason.trim(),
              })
          "
        >
          Reverse Payment
        </Button>
        <Button
          class="cursor-pointer"
          :disabled="!canSubmit"
          @click="
            selected &&
              emit('confirm', {
                paymentId: selected.id,
                amountMinor,
                reason: reason.trim(),
              })
          "
        >
          {{ t("cashier.refund_request") }}
        </Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
