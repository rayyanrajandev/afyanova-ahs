<!--
  ChargeBasketPanel — what this patient owes
  ===========================================
  Lines are selectable: a patient may pay for the consultation now and the lab
  tests after the doctor has actually ordered them, and forcing all-or-nothing
  would send them away over a service they have not agreed to yet.

  An unpriced charge is shown but not selectable. It is genuinely owed and
  genuinely cannot be taken, and collapsing that into "outstanding" would leave
  the cashier arguing with a total that does not add up.
-->
<script setup lang="ts">
import { AlertTriangle, Plus, Receipt, Undo2 } from "lucide-vue-next";
import { computed } from "vue";
import EmptyState from "@/components/common/EmptyState.vue";
import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import { useI18nSafe } from "@/composables/useI18nSafe";
import { decimalToMinor, formatMoney } from "../cashierFormatters";
import type {
  CashierCharge,
  CashierQueueRow,
} from "../composables/useCashierQueue";

const props = defineProps<{
  patient: CashierQueueRow | null;
  charges: CashierCharge[];
  selectedChargeIds: string[];
  currencyCode: string;
  unpricedCount: number;
  isLoading: boolean;
  canTakePayment: boolean;
  canAddCharge: boolean;
  canRequestRefund?: boolean;
}>();

const emit = defineEmits<{
  (e: "toggle", charge: CashierCharge): void;
  (e: "take-payment"): void;
  (e: "add-charge"): void;
  (e: "request-refund"): void;
}>();

const { t } = useI18nSafe();

const selectedTotalMinor = computed(() =>
  props.charges
    .filter((c) => props.selectedChargeIds.includes(c.id))
    .reduce((sum, c) => sum + decimalToMinor(c.amountDue), 0),
);

const hasSelection = computed(() => props.selectedChargeIds.length > 0);
</script>

<template>
  <div class="flex min-h-0 flex-1 flex-col overflow-hidden">
    <EmptyState
      v-if="patient === null"
      illustration="users"
      :title="t('cashier.select_patient_title')"
      :description="t('cashier.select_patient_desc')"
    />

    <template v-else>
      <header
        class="flex shrink-0 items-start justify-between gap-3 border-b border-border/80 bg-surface px-4 py-3"
      >
        <div class="min-w-0">
          <h2 class="truncate text-base font-semibold">
            {{ patient.patientName ?? "—" }}
          </h2>
          <p class="text-xs text-muted-foreground">
            {{ t("cashier.mrn") }} {{ patient.patientNumber ?? "—" }}
          </p>
        </div>

        <div class="flex shrink-0 items-center gap-2">
          <Button
            v-if="canRequestRefund"
            variant="ghost"
            size="sm"
            class="cursor-pointer"
            @click="emit('request-refund')"
          >
            <Undo2 class="mr-1.5 size-3.5" aria-hidden="true" />
            {{ t("cashier.refund_request") }}
          </Button>

          <Button
            v-if="canAddCharge"
            variant="outline"
            size="sm"
            class="cursor-pointer"
            @click="emit('add-charge')"
          >
            <Plus class="mr-1.5 size-3.5" aria-hidden="true" />
            {{ t("cashier.add_charge") }}
          </Button>
        </div>
      </header>

      <div
        v-if="unpricedCount > 0"
        class="flex shrink-0 items-start gap-2 border-b border-warning/25 bg-warning/5 px-4 py-2 text-xs"
      >
        <AlertTriangle class="mt-0.5 size-3.5 shrink-0 text-warning" aria-hidden="true" />
        <div>
          <p class="font-medium text-warning">
            {{ t("cashier.unpriced_warning", unpricedCount, { count: unpricedCount }) }}
          </p>
          <p class="text-muted-foreground">{{ t("cashier.unpriced_hint") }}</p>
        </div>
      </div>

      <div class="min-h-0 flex-1 overflow-y-auto px-4 py-3">
        <h3
          class="mb-2 text-xs font-medium uppercase tracking-wide text-muted-foreground"
        >
          {{ t("cashier.charges_heading") }}
        </h3>

        <ul class="flex flex-col gap-1.5">
          <li
            v-for="charge in charges"
            :key="charge.id"
            class="flex items-start gap-3 rounded-md border border-border/70 px-3 py-2"
            :class="charge.isPayable ? 'bg-card' : 'bg-muted/40'"
          >
            <Checkbox
              :model-value="selectedChargeIds.includes(charge.id)"
              :disabled="!charge.isPayable"
              class="mt-0.5 shrink-0"
              :aria-label="charge.description"
              @update:model-value="emit('toggle', charge)"
            />

            <div class="min-w-0 flex-1">
              <p class="truncate text-sm font-medium">{{ charge.description }}</p>
              <p class="text-xs text-muted-foreground">
                {{ charge.chargeNumber }}
                <span v-if="charge.quantity !== 1"> · x{{ charge.quantity }}</span>
                <span v-if="charge.discountReason"> · {{ charge.discountReason }}</span>
              </p>
            </div>

            <div class="shrink-0 text-right">
              <!--
                Three states, not two. `isPayable` is false for an unpriced
                charge *and* for one that has already been settled, and this
                column used to render both as "Not priced". That was harmless
                only while settled charges never reached the basket; once the
                paid tab could open one, a fully paid consultation was labelled
                unpriced. The reason a charge cannot be taken has to be the
                reason shown.
              -->
              <p v-if="charge.isPayable" class="text-sm font-semibold tabular-nums">
                {{ formatMoney(charge.amountDue, charge.currencyCode) }}
              </p>
              <template v-else-if="charge.pricingStatus !== 'priced'">
                <p class="text-xs font-medium text-warning">
                  {{ t("cashier.not_priced") }}
                </p>
              </template>
              <template v-else>
                <p class="text-sm font-semibold tabular-nums text-muted-foreground">
                  {{ formatMoney(charge.amountPaid, charge.currencyCode) }}
                </p>
                <p class="text-[11px] font-medium text-success">
                  {{ t("cashier.charge_settled") }}
                </p>
              </template>
            </div>
          </li>
        </ul>
      </div>

      <footer
        class="flex shrink-0 items-center justify-between gap-3 border-t border-border/80 bg-surface px-4 py-3"
      >
        <div>
          <p class="text-xs text-muted-foreground">{{ t("cashier.total_due") }}</p>
          <p class="text-lg font-semibold tabular-nums">
            {{ formatMoney(selectedTotalMinor, currencyCode) }}
          </p>
        </div>

        <div class="flex flex-col items-end gap-1">
          <Button
            size="lg"
            class="cursor-pointer"
            :disabled="!hasSelection || !canTakePayment || isLoading"
            @click="emit('take-payment')"
          >
            <Receipt class="mr-2 size-4" aria-hidden="true" />
            {{ t("cashier.take_payment") }}
          </Button>

          <!-- A disabled control that does not say why is a dead end. The
               drawer is the only prerequisite the cashier cannot infer from
               this panel, so it is the one spelled out. -->
          <p v-if="!canTakePayment" class="text-[11px] font-medium text-warning">
            {{ t("cashier.take_payment_needs_drawer") }}
          </p>
        </div>
      </footer>
    </template>
  </div>
</template>
