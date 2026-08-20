<!--
  TakePaymentDialog — the counter transaction
  ============================================
  Tanzania Standard Payment Methods:
  1. Fedha Taslimu (Cash TZS)
  2. Lipa kwa Simu (Lipa Namba: M-Pesa, Tigo Pesa, Airtel, HaloPesa)
  3. SimBanking / Benki (NMB Mkononi, CRDB SimBanking)
  4. Namba ya Malipo (Control Number / GePG)
-->
<script setup lang="ts">
import {
  Banknote,
  FileCheck,
  Landmark,
  PhoneCall,
  QrCode,
  Smartphone,
} from "lucide-vue-next";
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
import type { PaymentTenderMethod } from "../composables/useCashierPayment";

const props = defineProps<{
  open: boolean;
  dueMinor: number;
  tenderedMinor: number;
  changeMinor: number;
  isShort: boolean;
  canSubmit: boolean;
  isSubmitting: boolean;
  currencyCode: string;
  paymentMethod?: PaymentTenderMethod;
  paymentReference?: string;
  phoneNumber?: string;
  tenderLines?: { method: string; amountMinor: number; reference?: string }[];
}>();

const emit = defineEmits<{
  (e: "update:open", value: boolean): void;
  (e: "update:tendered", minor: number): void;
  (e: "update:method", method: PaymentTenderMethod): void;
  (e: "update:reference", ref: string): void;
  (e: "update:phone", phone: string): void;
  (e: "add-tender", line: { method: string; amountMinor: number; reference?: string }): void;
  (e: "remove-tender", index: number): void;
  (e: "confirm"): void;
}>();

const { t } = useI18nSafe();

const activeMethod = computed<PaymentTenderMethod>({
  get: () => props.paymentMethod ?? "cash",
  set: (val) => emit("update:method", val),
});

const tenderInput = ref<InstanceType<typeof Input> | null>(null);
const isStkSent = ref(false);
const showQrCode = ref(false);

const tenderedDisplay = computed({
  get: () => toAmountInput(props.tenderedMinor),
  set: (value: string) => emit("update:tendered", fromAmountInput(value)),
});

const totalTenderedLinesMinor = computed(() => {
  return props.tenderLines?.reduce((sum, line) => sum + line.amountMinor, 0) ?? 0;
});

const remainingDueMinor = computed(() => {
  const rem = props.dueMinor - totalTenderedLinesMinor.value;
  return rem > 0 ? rem : 0;
});

const canAddTenderLine = computed(() => {
  if (props.isSubmitting) return false;
  if (remainingDueMinor.value <= 0) return false;
  
  if (activeMethod.value === "cash") {
    return props.tenderedMinor > 0;
  }
  
  if (activeMethod.value === "mobile_money") {
    return (
      props.tenderedMinor > 0 &&
      (props.phoneNumber?.trim().length! >= 9 ||
        props.paymentReference?.trim().length! >= 3)
    );
  }
  
  if (activeMethod.value === "bank_transfer") {
    return props.tenderedMinor > 0 && props.paymentReference?.trim().length! >= 3;
  }
  
  if (activeMethod.value === "gepg") {
    return props.tenderedMinor > 0 && props.paymentReference?.trim().length! >= 6;
  }
  
  return false;
});

function addTenderLine() {
  if (!canAddTenderLine.value) return;
  emit("add-tender", {
    method: activeMethod.value,
    amountMinor: props.tenderedMinor,
    reference: props.paymentReference?.trim() || props.phoneNumber?.trim(),
  });
  // Reset fields for the next tender
  emit("update:tendered", remainingDueMinor.value - props.tenderedMinor > 0 ? remainingDueMinor.value - props.tenderedMinor : 0);
  emit("update:reference", "");
  emit("update:phone", "");
}

function tenderExact(): void {
  emit("update:tendered", remainingDueMinor.value);
}

function sendStkPrompt(): void {
  isStkSent.value = true;
}

watch(
  () => props.open,
  async (open) => {
    if (!open) return;
    isStkSent.value = false;
    showQrCode.value = false;
    await nextTick();
    (
      document.querySelector<HTMLInputElement>("[data-cashier-tender]")
    )?.select();
  },
);
</script>

<template>
  <Dialog :open="open" @update:open="emit('update:open', $event)">
    <DialogContent class="sm:max-w-2xl">
      <DialogHeader>
        <DialogTitle>{{ t("cashier.take_payment") }}</DialogTitle>
        <DialogDescription>{{ t("cashier.subtitle") }}</DialogDescription>
      </DialogHeader>

      <div class="flex flex-col gap-4">
        <!-- Total Due Banner -->
        <div class="flex items-center justify-between rounded-lg bg-muted/60 px-4 py-3">
          <div>
            <p class="text-xs text-muted-foreground">{{ t("cashier.amount_due") }}</p>
            <p class="text-2xl font-semibold tabular-nums">
              {{ formatMoney(remainingDueMinor, currencyCode) }}
            </p>
          </div>
          <div class="text-right">
            <span class="text-xs text-muted-foreground block mb-1">Total: {{ formatMoney(dueMinor, currencyCode) }}</span>
            <span class="rounded-md bg-primary/10 px-2.5 py-1 text-xs font-semibold text-primary">
              {{ currencyCode }}
            </span>
          </div>
        </div>
        
        <div v-if="tenderLines && tenderLines.length > 0" class="flex flex-col gap-2 rounded-lg border border-border p-3">
          <p class="text-xs font-semibold uppercase text-muted-foreground">Tender Lines</p>
          <div v-for="(line, index) in tenderLines" :key="index" class="flex items-center justify-between text-sm">
            <div class="flex items-center gap-2">
              <span class="font-medium capitalize">{{ line.method.replace('_', ' ') }}</span>
              <span v-if="line.reference" class="text-xs text-muted-foreground">{{ line.reference }}</span>
            </div>
            <div class="flex items-center gap-3">
              <span class="font-semibold tabular-nums">{{ formatMoney(line.amountMinor, currencyCode) }}</span>
              <Button variant="ghost" size="sm" class="h-6 w-6 p-0 text-critical" @click="emit('remove-tender', index)">x</Button>
            </div>
          </div>
        </div>

        <!-- Common Tanzania Payment Methods Tabs (4 Methods) -->
        <div v-if="remainingDueMinor > 0" class="grid grid-cols-2 gap-1.5 rounded-lg bg-muted/70 p-1 text-xs font-medium sm:grid-cols-4">
          <!-- 1. Cash -->
          <button
            type="button"
            class="flex cursor-pointer items-center justify-center gap-2 rounded-md px-3 py-2 transition-all"
            :class="
              activeMethod === 'cash'
                ? 'bg-card font-semibold text-foreground shadow-2xs'
                : 'text-muted-foreground hover:text-foreground'
            "
            @click="activeMethod = 'cash'"
          >
            <Banknote class="size-4 shrink-0" />
            <span class="truncate">{{ t("cashier.tender_cash") }}</span>
          </button>

          <!-- 2. Lipa kwa Simu (M-Pesa / Tigo / Airtel) -->
          <button
            type="button"
            class="flex cursor-pointer items-center justify-center gap-2 rounded-md px-3 py-2 transition-all"
            :class="
              activeMethod === 'mobile_money'
                ? 'bg-card font-semibold text-foreground shadow-2xs'
                : 'text-muted-foreground hover:text-foreground'
            "
            @click="activeMethod = 'mobile_money'"
          >
            <Smartphone class="size-4 shrink-0" />
            <span class="truncate">Lipa Namba</span>
          </button>

          <!-- 3. SimBanking / Benki -->
          <button
            type="button"
            class="flex cursor-pointer items-center justify-center gap-2 rounded-md px-3 py-2 transition-all"
            :class="
              activeMethod === 'bank_transfer'
                ? 'bg-card font-semibold text-foreground shadow-2xs'
                : 'text-muted-foreground hover:text-foreground'
            "
            @click="activeMethod = 'bank_transfer'"
          >
            <Landmark class="size-4 shrink-0" />
            <span class="truncate">SimBanking</span>
          </button>

          <!-- 4. Control Number (GePG) -->
          <button
            type="button"
            class="flex cursor-pointer items-center justify-center gap-2 rounded-md px-3 py-2 transition-all"
            :class="
              activeMethod === 'gepg'
                ? 'bg-card font-semibold text-foreground shadow-2xs'
                : 'text-muted-foreground hover:text-foreground'
            "
            @click="activeMethod = 'gepg'"
          >
            <FileCheck class="size-4 shrink-0" />
            <span class="truncate">Control No.</span>
          </button>
        </div>

        <div v-if="remainingDueMinor > 0" class="flex flex-col gap-1.5 mt-2">
          <Label for="cashier-tender">Tender Amount</Label>
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

        <!-- 1. CASH VIEW -->
        <template v-if="remainingDueMinor > 0 && activeMethod === 'cash'">
          <div
            class="flex items-baseline justify-between rounded-lg border border-border/70 px-4 py-3 mt-2"
          >
            <span class="text-sm text-muted-foreground">{{ t("cashier.change") }}</span>
            <span class="text-xl font-semibold tabular-nums">
              {{ formatMoney(changeMinor, currencyCode) }}
            </span>
          </div>
        </template>

        <!-- 2. LIPA KWA SIMU (M-PESA / TIGO / AIRTEL / HALOPESA) VIEW -->
        <template v-else-if="remainingDueMinor > 0 && activeMethod === 'mobile_money'">
          <div class="flex flex-col gap-3">
            <!-- Official Lipa Namba Box -->
            <div class="flex items-center justify-between rounded-lg border border-primary/25 bg-primary/5 p-3">
              <div>
                <span class="text-xs font-semibold text-primary uppercase">{{ t("cashier.lipa_kwa_simu") }}</span>
                <p class="text-xl font-bold font-mono text-foreground tracking-wider">5421098</p>
                <p class="text-xs text-muted-foreground">AFYANOVA HEALTH SYSTEM</p>
              </div>
              <div class="text-right text-xs text-muted-foreground max-w-[220px]">
                <p class="font-medium text-foreground">{{ t("cashier.dial_ussd_hint") }}</p>
              </div>
            </div>

            <div class="flex flex-col gap-1.5">
              <Label for="cashier-phone">{{ t("cashier.phone_number") }}</Label>
              <div class="flex items-center gap-2">
                <Input
                  id="cashier-phone"
                  :model-value="phoneNumber"
                  type="tel"
                  class="h-10 text-base"
                  placeholder="07XXXXXXXX"
                  @update:model-value="emit('update:phone', String($event))"
                />
                <Button
                  type="button"
                  variant="outline"
                  class="h-10 shrink-0 cursor-pointer"
                  :disabled="!phoneNumber || phoneNumber.length < 9"
                  @click="sendStkPrompt"
                >
                  <PhoneCall class="mr-1.5 size-3.5" />
                  {{ t("cashier.send_stk_prompt") }}
                </Button>
              </div>
              <p class="text-xs text-muted-foreground">{{ t("cashier.phone_number_hint") }}</p>
            </div>

            <div v-if="isStkSent" class="rounded-md border border-primary/25 bg-primary/5 p-3 text-xs">
              <p class="font-medium text-primary">{{ t("cashier.waiting_for_patient_pin") }}</p>
            </div>

            <div class="flex flex-col gap-1.5">
              <Label for="cashier-mpesa-ref">{{ t("cashier.enter_lipa_ref") }}</Label>
              <Input
                id="cashier-mpesa-ref"
                :model-value="paymentReference"
                type="text"
                class="h-9 uppercase font-mono"
                placeholder="e.g. 9K28QXYZ7"
                @update:model-value="emit('update:reference', String($event))"
              />
              <p class="text-xs text-muted-foreground">{{ t("cashier.payment_reference_hint") }}</p>
            </div>

            <Button
              type="button"
              variant="ghost"
              size="sm"
              class="self-start text-xs text-muted-foreground"
              @click="showQrCode = !showQrCode"
            >
              <QrCode class="mr-1 size-3.5" />
              {{ showQrCode ? 'Hide QR Code' : 'Show Dynamic QR Code' }}
            </Button>

            <div v-if="showQrCode" class="flex flex-col items-center justify-center rounded-lg border border-border/80 p-4">
              <div class="flex size-32 items-center justify-center rounded-lg bg-foreground/5 text-xs text-muted-foreground font-mono">
                [ SCAN TO PAY ]
              </div>
              <p class="mt-2 text-xs font-semibold text-foreground">{{ formatMoney(dueMinor, currencyCode) }}</p>
            </div>
          </div>
        </template>

        <!-- 3. SIMBANKING / BENKI (NMB / CRDB) VIEW -->
        <template v-else-if="remainingDueMinor > 0 && activeMethod === 'bank_transfer'">
          <div class="flex flex-col gap-3">
            <div class="rounded-lg border border-border/80 bg-muted/30 p-3 text-xs">
              <p class="font-bold text-foreground">NMB / CRDB SimBanking Transfer</p>
              <p class="text-muted-foreground mt-0.5">Account: AFYANOVA HEALTH SYSTEM · A/C No: 20110023456</p>
            </div>

            <div class="flex flex-col gap-1.5">
              <Label for="cashier-bank-ref">{{ t("cashier.payment_reference") }}</Label>
              <Input
                id="cashier-bank-ref"
                :model-value="paymentReference"
                type="text"
                class="h-10 text-base uppercase font-mono"
                placeholder="e.g. CRDB-TXN-123456"
                @update:model-value="emit('update:reference', String($event))"
              />
              <p class="text-xs text-muted-foreground">{{ t("cashier.payment_reference_hint") }}</p>
            </div>
          </div>
        </template>

        <!-- 4. CONTROL NUMBER (GEPG) VIEW -->
        <template v-else-if="remainingDueMinor > 0 && activeMethod === 'gepg'">
          <div class="flex flex-col gap-3">
            <div class="rounded-lg border border-primary/30 bg-primary/5 p-3 text-xs">
              <p class="font-bold text-primary">Namba ya Malipo (GePG Control Number)</p>
              <p class="text-muted-foreground mt-0.5">Mgonjwa anaweza kulipia kupitia benki zote na mitandao ya simu.</p>
            </div>

            <div class="flex flex-col gap-1.5">
              <Label for="cashier-gepg-ref">{{ t("cashier.gepg_number") }}</Label>
              <Input
                id="cashier-gepg-ref"
                :model-value="paymentReference"
                type="text"
                class="h-11 text-lg font-mono font-bold tracking-wider"
                placeholder="99XXXXXXXXXX"
                @update:model-value="emit('update:reference', String($event))"
              />
              <p class="text-xs text-muted-foreground">{{ t("cashier.gepg_hint") }}</p>
            </div>
          </div>
        </template>
        
        <div v-if="remainingDueMinor > 0" class="flex justify-end pt-2">
          <Button
            type="button"
            variant="outline"
            :disabled="!canAddTenderLine"
            @click="addTenderLine"
          >
            Add to Payment
          </Button>
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
