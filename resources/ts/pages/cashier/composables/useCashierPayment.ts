/**
 * useCashierPayment — taking the money
 * =====================================
 * The idempotency key is generated when the payment dialog opens, not when it
 * is submitted. That is the whole mechanism: a double-tapped Confirm, or a
 * retry over a bad connection, sends the same key and gets back the original
 * receipt instead of taking the money twice.
 */

import { computed, ref } from "vue";
import { useI18nSafe } from "@/composables/useI18nSafe";
import { useToast } from "@/composables/useToast";
import { changeDue, decimalToMinor } from "../cashierFormatters";
import type { CashierCharge } from "./useCashierQueue";

export interface ReceiptSnapshotLine {
  chargeId: string;
  chargeNumber: string;
  description: string;
  quantity: number;
  unitPrice: string;
  amount: string;
}

export interface CashierReceipt {
  id: string;
  receiptNumber: string;
  paymentId: string;
  patientId: string;
  currencyCode: string;
  total: string;
  issuedAt: string | null;
  snapshot: {
    lines: ReceiptSnapshotLine[];
    total: string;
    tendered: string;
    change: string;
    currencyCode: string;
    paymentNumber: string;
    issuedAt: string;
  };
  fiscalStatus: string;
  fiscalReference: string | null;
  reprintCount: number;
}

export interface CashierPayment {
  id: string;
  paymentNumber: string;
  patientId: string;
  method: string;
  currencyCode: string;
  amount: string;
  tendered: string | null;
  change: string | null;
  status: string;
  receivedAt: string | null;
  receipt: CashierReceipt | null;
}

const JSON_HEADERS = {
  Accept: "application/json",
  "Content-Type": "application/json",
  "X-Requested-With": "XMLHttpRequest",
};

function csrfToken(): string {
  return (
    document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
      ?.content ?? ""
  );
}

export function useCashierPayment() {
  const { t } = useI18nSafe();
  const toast = useToast();

  const selectedChargeIds = ref<string[]>([]);
  const tenderedMinor = ref(0);
  const idempotencyKey = ref<string | null>(null);
  const isSubmitting = ref(false);
  const lastReceipt = ref<CashierReceipt | null>(null);

  const dueMinor = ref(0);

  const changeMinor = computed(() => changeDue(tenderedMinor.value, dueMinor.value));
  const isShort = computed(() => tenderedMinor.value < dueMinor.value);
  const canSubmit = computed(
    () => selectedChargeIds.value.length > 0 && !isShort.value && !isSubmitting.value,
  );

  /**
   * Opening the dialog mints the key. Re-opening it starts a genuinely new
   * intent, which is correct — the cashier has gone back and changed something.
   */
  function beginPayment(charges: CashierCharge[]): void {
    selectedChargeIds.value = charges.map((c) => c.id);
    dueMinor.value = charges.reduce((sum, c) => sum + decimalToMinor(c.amountDue), 0);
    tenderedMinor.value = dueMinor.value;
    idempotencyKey.value = crypto.randomUUID();
    lastReceipt.value = null;
  }

  function toggleCharge(charge: CashierCharge, charges: CashierCharge[]): void {
    const ids = new Set(selectedChargeIds.value);
    if (ids.has(charge.id)) {
      ids.delete(charge.id);
    } else {
      ids.add(charge.id);
    }

    selectedChargeIds.value = [...ids];
    dueMinor.value = charges
      .filter((c) => ids.has(c.id))
      .reduce((sum, c) => sum + decimalToMinor(c.amountDue), 0);

    if (tenderedMinor.value < dueMinor.value) {
      tenderedMinor.value = dueMinor.value;
    }
  }

  async function submit(patientId: string): Promise<CashierPayment | null> {
    if (!canSubmit.value || idempotencyKey.value === null) return null;

    isSubmitting.value = true;

    try {
      const response = await fetch("/api/v1/cashier/payments", {
        method: "POST",
        headers: { ...JSON_HEADERS, "X-CSRF-TOKEN": csrfToken() },
        credentials: "same-origin",
        body: JSON.stringify({
          patientId,
          serviceChargeIds: selectedChargeIds.value,
          tenderedAmountMinor: tenderedMinor.value,
          idempotencyKey: idempotencyKey.value,
        }),
      });

      const payload = await response.json().catch(() => null);

      if (!response.ok) {
        toast.error(payload?.message ?? t("cashier.error_payment_failed"));

        return null;
      }

      const payment = payload?.data as CashierPayment;
      lastReceipt.value = payment?.receipt ?? null;
      toast.success(t("cashier.payment_recorded"));

      return payment;
    } catch {
      toast.error(t("cashier.error_payment_failed"));

      return null;
    } finally {
      isSubmitting.value = false;
    }
  }

  return {
    selectedChargeIds,
    tenderedMinor,
    dueMinor,
    changeMinor,
    isShort,
    canSubmit,
    isSubmitting,
    lastReceipt,
    beginPayment,
    toggleCharge,
    submit,
  };
}
