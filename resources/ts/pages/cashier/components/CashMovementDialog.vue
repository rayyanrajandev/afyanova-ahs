<!--
  CashMovementDialog — cash in or out, other than by taking payment
  =================================================================
  Without this the expected total is wrong from the first time anyone banks a
  float, and every close after that shows a variance nobody can explain — which
  trains people to sign variances off, defeating the control.
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
import { fromAmountInput } from "../cashierFormatters";
import type { CashMovementReason } from "../composables/useCashierSession";

const REASONS: CashMovementReason[] = [
  "float_top_up",
  "banking_drop",
  "petty_cash",
  "correction",
];

const props = defineProps<{ open: boolean; isSubmitting: boolean }>();

const emit = defineEmits<{
  (e: "update:open", value: boolean): void;
  (
    e: "confirm",
    payload: { reason: CashMovementReason; amountMinor: number; note: string },
  ): void;
}>();

const { t } = useI18nSafe();

const reason = ref<CashMovementReason>("float_top_up");
const amount = ref("0");
const note = ref("");

watch(
  () => props.open,
  (open) => {
    if (!open) return;
    reason.value = "float_top_up";
    amount.value = "0";
    note.value = "";
  },
);

const amountMinor = computed(() => fromAmountInput(amount.value));
const canSubmit = computed(() => amountMinor.value > 0 && !props.isSubmitting);
</script>

<template>
  <Dialog :open="open" @update:open="emit('update:open', $event)">
    <DialogContent class="sm:max-w-sm">
      <DialogHeader>
        <DialogTitle>{{ t("cashier.drawer") }}</DialogTitle>
        <DialogDescription>{{ t("cashier.movement_hint") }}</DialogDescription>
      </DialogHeader>

      <div class="flex flex-col gap-3">
        <div class="flex flex-col gap-1.5">
          <span class="text-sm font-medium">{{ t("cashier.reason") }}</span>
          <div class="grid grid-cols-2 gap-1.5">
            <button
              v-for="option in REASONS"
              :key="option"
              type="button"
              class="cursor-pointer rounded-md border px-2 py-1.5 text-xs transition-colors"
              :class="
                reason === option
                  ? 'border-primary bg-primary/10 font-semibold text-primary'
                  : 'border-border text-muted-foreground hover:text-foreground'
              "
              @click="reason = option"
            >
              {{ t(`cashier.movement_${option}`) }}
            </button>
          </div>
        </div>

        <div class="flex flex-col gap-1.5">
          <Label for="cashier-movement-amount">{{ t("cashier.amount_due") }}</Label>
          <Input
            id="cashier-movement-amount"
            v-model="amount"
            type="number"
            min="0"
            step="1"
            class="h-10 tabular-nums"
          />
        </div>

        <div class="flex flex-col gap-1.5">
          <Label for="cashier-movement-note">{{ t("cashier.reason") }}</Label>
          <Input id="cashier-movement-note" v-model="note" class="h-9" />
        </div>
      </div>

      <DialogFooter>
        <Button variant="ghost" class="cursor-pointer" @click="emit('update:open', false)">
          {{ t("cashier.cancel") }}
        </Button>
        <Button
          class="cursor-pointer"
          :disabled="!canSubmit"
          @click="
            emit('confirm', {
              reason,
              amountMinor,
              note: note.trim(),
            })
          "
        >
          {{ t("cashier.confirm") }}
        </Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
