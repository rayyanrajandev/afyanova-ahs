/**
 * useCashierPayment — taking the money
 * =====================================
 * The idempotency key is generated when the payment dialog opens, not when it
 * is submitted. That is the whole mechanism: a double-tapped Confirm, or a
 * retry over a bad connection, sends the same key and gets back the original
 * receipt instead of taking the money twice.
 *
 * Supports Tanzania's 4 primary payment methods:
 * 1. Fedha Taslimu (Cash)
 * 2. Lipa kwa Simu (Lipa Namba / M-Pesa / Tigo / Airtel / HaloPesa)
 * 3. SimBanking / Benki (NMB / CRDB)
 * 4. Namba ya Malipo (GePG Control Number)
 */

import { computed, ref } from "vue";
import { useI18nSafe } from "@/composables/useI18nSafe";
import { useToast } from "@/composables/useToast";
import { changeDue, decimalToMinor } from "../cashierFormatters";
import type { CashierCharge } from "./useCashierQueue";

export type PaymentTenderMethod =
  | "cash"
  | "mobile_money"
  | "bank_transfer"
  | "gepg";

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

  const paymentMethod = ref<PaymentTenderMethod>("cash");
  const paymentReference = ref("");
  const phoneNumber = ref("");
  const stkStatus = ref<"idle" | "sending" | "waiting" | "success" | "failed">("idle");
  const tenderLines = ref<{ method: string; amountMinor: number; reference?: string }[]>([]);

  const dueMinor = ref(0);

  const changeMinor = computed(() => {
    if (tenderLines.value.length > 0) {
      const totalTendered = tenderLines.value.reduce((sum, line) => sum + line.amountMinor, 0);
      const cashTendered = tenderLines.value
        .filter((l) => l.method === "cash")
        .reduce((sum, line) => sum + line.amountMinor, 0);
      
      if (cashTendered > 0 && totalTendered > dueMinor.value) {
        return totalTendered - dueMinor.value;
      }
      return 0;
    }

    if (paymentMethod.value === "cash") {
      return changeDue(tenderedMinor.value, dueMinor.value);
    }
    return 0;
  });

  const isShort = computed(() => {
    if (tenderLines.value.length > 0) {
      const totalTendered = tenderLines.value.reduce((sum, line) => sum + line.amountMinor, 0);
      return totalTendered < dueMinor.value;
    }
    
    if (paymentMethod.value === "cash") {
      return tenderedMinor.value < dueMinor.value;
    }
    return false;
  });

  const canSubmit = computed(() => {
    if (selectedChargeIds.value.length === 0 || isSubmitting.value) return false;

    if (tenderLines.value.length > 0) {
      return !isShort.value;
    }

    if (paymentMethod.value === "cash") {
      return !isShort.value;
    }

    if (paymentMethod.value === "mobile_money") {
      return (
        phoneNumber.value.trim().length >= 9 ||
        paymentReference.value.trim().length >= 3
      );
    }

    if (paymentMethod.value === "bank_transfer") {
      return paymentReference.value.trim().length >= 3;
    }

    if (paymentMethod.value === "gepg") {
      return paymentReference.value.trim().length >= 6;
    }

    return false;
  });

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

    paymentMethod.value = "cash";
    paymentReference.value = "";
    phoneNumber.value = "";
    stkStatus.value = "idle";
    tenderLines.value = [];
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
      const payload: Record<string, unknown> = {
        patientId,
        serviceChargeIds: selectedChargeIds.value,
        tenderedAmountMinor: tenderedMinor.value,
        idempotencyKey: idempotencyKey.value,
        method: paymentMethod.value,
        paymentReference: paymentReference.value.trim() || undefined,
        phoneNumber: phoneNumber.value.trim() || undefined,
      };

      if (tenderLines.value.length > 0) {
        payload.tenderLines = tenderLines.value;
        const total = tenderLines.value.reduce((acc, line) => acc + line.amountMinor, 0);
        payload.tenderedAmountMinor = total;
      }

      const response = await fetch("/api/v1/cashier/payments", {
        method: "POST",
        headers: { ...JSON_HEADERS, "X-CSRF-TOKEN": csrfToken() },
        credentials: "same-origin",
        body: JSON.stringify(payload),
      });

      const resData = await response.json().catch(() => null);

      if (!response.ok) {
        toast.error(resData?.message ?? t("cashier.error_payment_failed"));
        return null;
      }

      const payment = resData?.data as CashierPayment;
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
    paymentMethod,
    paymentReference,
    phoneNumber,
    stkStatus,
    tenderLines,
    beginPayment,
    toggleCharge,
    submit,
  };
}
