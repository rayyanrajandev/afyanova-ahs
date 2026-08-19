/**
 * useCashierRefunds — money going back out
 * =========================================
 * Requesting and ruling on a refund are separate acts by separate people. The
 * composable exposes both, and the workspace shows only what the signed-in
 * user actually holds — an approve button a cashier can see and never use
 * teaches them to ignore disabled controls.
 */

import { computed, ref } from "vue";
import { useI18nSafe } from "@/composables/useI18nSafe";
import { useToast } from "@/composables/useToast";

export interface PatientPayment {
  id: string;
  paymentNumber: string;
  receiptNumber: string | null;
  receiptId: string | null;
  method: string;
  currencyCode: string;
  amount: string;
  alreadyRefunded: string;
  refundable: string;
  isRefundable: boolean;
  status: string;
  receivedAt: string | null;
}

export interface Refund {
  id: string;
  refundNumber: string;
  patientId: string;
  originalPaymentId: string;
  currencyCode: string;
  amount: string;
  reason: string;
  status: "requested" | "approved" | "paid" | "rejected";
  requestedByUserId: number;
  requestedAt: string | null;
  approvedByUserId: number | null;
  paidFromSessionId: string | null;
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

export function useCashierRefunds() {
  const { t } = useI18nSafe();
  const toast = useToast();

  const pending = ref<Refund[]>([]);
  const payments = ref<PatientPayment[]>([]);
  const isLoading = ref(false);
  const isSubmitting = ref(false);

  const pendingCount = computed(() => pending.value.length);

  async function call(url: string, method = "GET", body?: unknown) {
    const response = await fetch(url, {
      method,
      headers:
        method === "GET"
          ? { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" }
          : { ...JSON_HEADERS, "X-CSRF-TOKEN": csrfToken() },
      credentials: "same-origin",
      body: body === undefined ? undefined : JSON.stringify(body),
    });

    const payload = await response.json().catch(() => null);

    if (!response.ok) {
      throw new Error(payload?.message ?? t("cashier.error_generic"));
    }

    return payload;
  }

  async function fetchPending(): Promise<void> {
    isLoading.value = true;

    try {
      pending.value = (await call("/api/v1/cashier/refunds"))?.data ?? [];
    } catch {
      // A cashier without cashier.refunds.request cannot list them; that is
      // not an error worth interrupting the counter for.
      pending.value = [];
    } finally {
      isLoading.value = false;
    }
  }

  async function fetchPaymentsFor(patientId: string): Promise<void> {
    isLoading.value = true;

    try {
      payments.value =
        (await call(`/api/v1/cashier/patients/${patientId}/payments`))?.data ?? [];
    } catch (e) {
      toast.error((e as Error).message);
      payments.value = [];
    } finally {
      isLoading.value = false;
    }
  }

  async function request(
    paymentId: string,
    amountMinor: number,
    reason: string,
  ): Promise<boolean> {
    isSubmitting.value = true;

    try {
      await call("/api/v1/cashier/refunds", "POST", { paymentId, amountMinor, reason });
      toast.success(t("cashier.refund_requested"));
      await fetchPending();

      return true;
    } catch (e) {
      toast.error((e as Error).message);

      return false;
    } finally {
      isSubmitting.value = false;
    }
  }

  async function rule(
    refundId: string,
    decision: "approve" | "reject",
    payload: { paidFromSessionId?: string; reason?: string; note?: string },
  ): Promise<boolean> {
    isSubmitting.value = true;

    try {
      await call(`/api/v1/cashier/refunds/${refundId}/${decision}`, "POST", payload);
      toast.success(
        decision === "approve"
          ? t("cashier.refund_approved")
          : t("cashier.refund_rejected"),
      );
      await fetchPending();

      return true;
    } catch (e) {
      toast.error((e as Error).message);

      return false;
    } finally {
      isSubmitting.value = false;
    }
  }

  return {
    pending,
    pendingCount,
    payments,
    isLoading,
    isSubmitting,
    fetchPending,
    fetchPaymentsFor,
    request,
    rule,
  };
}
