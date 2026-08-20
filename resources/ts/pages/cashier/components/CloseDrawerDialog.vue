<!--
  CloseDrawerDialog — the blind count
  ====================================
  The cashier counts the cash and enters the total. Only then does the ledger
  reveal what it expected, and the variance.

  This is not a UI convention: the API withholds `expectedCash` for an open
  session, so there is nothing here to hide and nothing to recover from the
  network tab. The dialog cannot show the answer early because it does not have
  it.
-->
<script setup lang="ts">
import { AlertTriangle, CheckCircle2 } from "lucide-vue-next";
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
import { decimalToMinor, formatMoney, fromAmountInput } from "../cashierFormatters";
import type {
  CashierSession,
  CloseBreakdown,
} from "../composables/useCashierSession";

const props = defineProps<{
  open: boolean;
  isSubmitting: boolean;
  currencyCode: string;
  /** Set only after the count has been submitted. */
  result: {
    session: CashierSession;
    requiresApproval: boolean;
    breakdown: CloseBreakdown | null;
  } | null;
}>();

const emit = defineEmits<{
  (e: "update:open", value: boolean): void;
  (e: "confirm", declaredCashMinor: number): void;
}>();

const { t } = useI18nSafe();

const countInput = ref("0");

watch(
  () => props.open,
  (open) => {
    if (open) countInput.value = "0";
  },
);

const varianceMinor = computed(() =>
  props.result ? decimalToMinor(props.result.session.variance) : 0,
);

const varianceLabel = computed(() => {
  if (varianceMinor.value === 0) return t("cashier.variance_balanced");

  return varianceMinor.value > 0
    ? t("cashier.variance_over")
    : t("cashier.variance_short");
});
</script>

<template>
  <Dialog :open="open" @update:open="emit('update:open', $event)">
    <DialogContent class="sm:max-w-xl">
      <DialogHeader>
        <DialogTitle>{{ t("cashier.count_the_drawer") }}</DialogTitle>
        <DialogDescription>{{ t("cashier.counted_hint") }}</DialogDescription>
      </DialogHeader>

      <!-- Before the count: the field, and nothing that hints at the answer. -->
      <div v-if="result === null" class="flex flex-col gap-1.5">
        <Label for="cashier-count">{{ t("cashier.counted_amount") }}</Label>
        <Input
          id="cashier-count"
          v-model="countInput"
          type="number"
          inputmode="decimal"
          min="0"
          step="1"
          class="h-11 text-lg tabular-nums"
        />
      </div>

      <!-- After: what the ledger expected, and the difference. -->
      <div v-else class="flex flex-col gap-3">
        <!--
          Where the expected figure came from. Showing a variance without it
          asks the cashier to accept a number they cannot check, and then to
          sign for the difference.
        -->
        <dl
          v-if="result.breakdown"
          class="flex flex-col gap-1 rounded-lg border border-border/70 px-4 py-3 text-xs"
        >
          <div class="flex justify-between">
            <dt class="text-muted-foreground">{{ t("cashier.opening_float") }}</dt>
            <dd class="tabular-nums">
              {{ formatMoney(result.breakdown.openingFloat, currencyCode) }}
            </dd>
          </div>
          <div class="flex justify-between">
            <dt class="text-muted-foreground">
              {{ t("cashier.cash_taken") }}
              <span class="opacity-70">({{ result.breakdown.paymentCount }})</span>
            </dt>
            <dd class="tabular-nums">
              + {{ formatMoney(result.breakdown.cashTaken, currencyCode) }}
            </dd>
          </div>
          <div
            v-if="result.breakdown.cashIn !== '0.00'"
            class="flex justify-between"
          >
            <dt class="text-muted-foreground">{{ t("cashier.movement_float_top_up") }}</dt>
            <dd class="tabular-nums">
              + {{ formatMoney(result.breakdown.cashIn, currencyCode) }}
            </dd>
          </div>
          <div
            v-if="result.breakdown.cashOut !== '0.00'"
            class="flex justify-between"
          >
            <dt class="text-muted-foreground">{{ t("cashier.movement_banking_drop") }}</dt>
            <dd class="tabular-nums">
              − {{ formatMoney(result.breakdown.cashOut, currencyCode) }}
            </dd>
          </div>
          <div
            v-if="result.breakdown.refundsPaid !== '0.00'"
            class="flex justify-between"
          >
            <dt class="text-muted-foreground">{{ t("cashier.refunded") }}</dt>
            <dd class="tabular-nums">
              − {{ formatMoney(result.breakdown.refundsPaid, currencyCode) }}
            </dd>
          </div>
          <div
            v-if="result.breakdown.reversals !== '0.00'"
            class="flex justify-between opacity-70"
          >
            <dt>{{ t("cashier.reversed") }}</dt>
            <dd class="tabular-nums">
              {{ formatMoney(result.breakdown.reversals, currencyCode) }}
            </dd>
          </div>
        </dl>

        <dl class="flex flex-col gap-1.5 rounded-lg bg-muted/60 px-4 py-3 text-sm">
          <div class="flex justify-between">
            <dt class="text-muted-foreground">{{ t("cashier.counted") }}</dt>
            <dd class="tabular-nums">
              {{ formatMoney(result.session.declaredCash, currencyCode) }}
            </dd>
          </div>
          <div class="flex justify-between">
            <dt class="text-muted-foreground">{{ t("cashier.expected") }}</dt>
            <dd class="tabular-nums">
              {{ formatMoney(result.session.expectedCash, currencyCode) }}
            </dd>
          </div>
          <div class="flex justify-between border-t border-border/70 pt-1.5 font-semibold">
            <dt>{{ t("cashier.variance") }} · {{ varianceLabel }}</dt>
            <dd class="tabular-nums">
              {{ formatMoney(result.session.variance, currencyCode) }}
            </dd>
          </div>
        </dl>

        <p
          v-if="result.requiresApproval"
          class="flex items-start gap-2 rounded-md border border-warning/25 bg-warning/5 px-3 py-2 text-xs text-warning"
        >
          <AlertTriangle class="mt-0.5 size-3.5 shrink-0" aria-hidden="true" />
          {{ t("cashier.variance_needs_approval") }}
        </p>
        <p
          v-else
          class="flex items-start gap-2 rounded-md border border-success/25 bg-success/5 px-3 py-2 text-xs text-success"
        >
          <CheckCircle2 class="mt-0.5 size-3.5 shrink-0" aria-hidden="true" />
          {{ t("cashier.drawer_closed_ok") }}
        </p>
      </div>

      <DialogFooter>
        <Button variant="ghost" class="cursor-pointer" @click="emit('update:open', false)">
          {{ result === null ? t("cashier.cancel") : t("cashier.confirm") }}
        </Button>
        <Button
          v-if="result === null"
          class="cursor-pointer"
          :disabled="isSubmitting"
          @click="emit('confirm', fromAmountInput(countInput))"
        >
          {{ t("cashier.close_drawer") }}
        </Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
