<!--
  TakePaymentDialog — the counter transaction
  ============================================
  Amount due, cash received, change. The cashier types what the patient handed
  over and the change is computed, rather than the other way round, because
  that is the order it happens at the counter.

  Confirm stays disabled while the tender is short: prepaid means paid, and a
  part payment would leave the service unprovided with the patient believing
  otherwise.
-->
<script setup lang="ts">
import { Banknote } from "lucide-vue-next";
import { computed, nextTick, ref, watch } from "vue";
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
import {
  formatMoney,
  fromAmountInput,
  toAmountInput,
} from "../cashierFormatters";

const props = defineProps<{
  open: boolean;
  dueMinor: number;
  tenderedMinor: number;
  changeMinor: number;
  isShort: boolean;
  canSubmit: boolean;
  isSubmitting: boolean;
  currencyCode: string;
}>();

const emit = defineEmits<{
  (e: "update:open", value: boolean): void;
  (e: "update:tendered", minor: number): void;
  (e: "confirm"): void;
}>();

const { t } = useI18nSafe();

const tenderInput = ref<InstanceType<typeof Input> | null>(null);

const tenderedDisplay = computed({
  get: () => toAmountInput(props.tenderedMinor),
  set: (value: string) => emit("update:tendered", fromAmountInput(value)),
});

// The exact amount is by far the most common tender, so it is one keystroke
// away rather than something to be typed digit by digit.
function tenderExact(): void {
  emit("update:tendered", props.dueMinor);
}

watch(
  () => props.open,
  async (open) => {
    if (!open) return;
    await nextTick();
    (
      document.querySelector<HTMLInputElement>("[data-cashier-tender]")
    )?.select();
  },
);
</script>

<template>
  <Dialog :open="open" @update:open="emit('update:open', $event)">
    <DialogContent class="sm:max-w-md">
      <DialogHeader>
        <DialogTitle>{{ t("cashier.take_payment") }}</DialogTitle>
        <DialogDescription>{{ t("cashier.subtitle") }}</DialogDescription>
      </DialogHeader>

      <div class="flex flex-col gap-4">
        <div class="rounded-lg bg-muted/60 px-4 py-3">
          <p class="text-xs text-muted-foreground">{{ t("cashier.amount_due") }}</p>
          <p class="text-2xl font-semibold tabular-nums">
            {{ formatMoney(dueMinor, currencyCode) }}
          </p>
        </div>

        <div class="flex flex-col gap-1.5">
          <Label for="cashier-tender">{{ t("cashier.cash_received") }}</Label>
          <div class="flex items-center gap-2">
            <Input
              id="cashier-tender"
              ref="tenderInput"
              v-model="tenderedDisplay"
              data-cashier-tender
              type="number"
              inputmode="decimal"
              min="0"
              step="1"
              class="h-11 text-lg tabular-nums"
              :aria-invalid="isShort"
            />
            <Button
              type="button"
              variant="outline"
              class="h-11 shrink-0 cursor-pointer"
              @click="tenderExact"
            >
              {{ t("cashier.quick_amount") }}
            </Button>
          </div>
          <p v-if="isShort" class="text-xs font-medium text-critical">
            {{ t("cashier.tender_short") }}
          </p>
        </div>

        <div
          class="flex items-baseline justify-between rounded-lg border border-border/70 px-4 py-3"
        >
          <span class="text-sm text-muted-foreground">{{ t("cashier.change") }}</span>
          <span class="text-xl font-semibold tabular-nums">
            {{ formatMoney(changeMinor, currencyCode) }}
          </span>
        </div>
      </div>

      <DialogFooter>
        <Button
          variant="ghost"
          class="cursor-pointer"
          @click="emit('update:open', false)"
        >
          {{ t("cashier.cancel") }}
        </Button>
        <Button
          class="cursor-pointer"
          :disabled="!canSubmit || isSubmitting"
          @click="emit('confirm')"
        >
          <Banknote class="mr-2 size-4" aria-hidden="true" />
          {{ t("cashier.confirm_payment") }}
        </Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
