/**
 * useCashierQueue — who is waiting to pay
 * ========================================
 * One row per patient, not per charge: someone standing at the counter with a
 * consultation and two lab tests is one person to serve, and listing them
 * three times would make the queue length a lie.
 *
 * Charge-driven rather than appointment-driven, so a walk-in with an ad-hoc
 * charge and no appointment appears here too.
 */

import { computed, ref } from "vue";
import { useI18nSafe } from "@/composables/useI18nSafe";

export type CashierQueueTab = "awaiting_payment" | "paid_today";

export interface CashierQueueRow {
  patientId: string;
  patientName: string | null;
  patientNumber: string | null;
  chargeCount: number;
  unpricedCount: number;
  amountDue: string;
  currencyCode: string;
  oldestChargeAt: string | null;
}

export interface CashierCharge {
  id: string;
  chargeNumber: string;
  patientId: string;
  appointmentId: string | null;
  sourceKind: string;
  description: string;
  unit: string | null;
  quantity: number;
  currencyCode: string;
  unitPrice: string;
  grossAmount: string;
  discountAmount: string;
  discountReason: string | null;
  taxAmount: string;
  netAmount: string;
  amountPaid: string;
  amountDue: string;
  payerClass: string;
  status: string;
  pricingStatus: string | null;
  /** False for an unpriced charge: outstanding, but nothing to take yet. */
  isPayable: boolean;
  authorizationBasis: string | null;
  authorizedAt: string | null;
  createdAt: string | null;
}

const JSON_HEADERS = {
  Accept: "application/json",
  "X-Requested-With": "XMLHttpRequest",
};

export function useCashierQueue() {
  const { t } = useI18nSafe();

  const activeTab = ref<CashierQueueTab>("awaiting_payment");
  const searchTerm = ref("");
  const rows = ref<CashierQueueRow[]>([]);
  const counts = ref<Record<CashierQueueTab, number>>({
    awaiting_payment: 0,
    paid_today: 0,
  });

  const selectedPatientId = ref<string | null>(null);
  const charges = ref<CashierCharge[]>([]);
  const basketTotalDue = ref("0.00");
  const basketCurrency = ref("TZS");
  const basketUnpricedCount = ref(0);

  const isLoading = ref(false);
  const isLoadingCharges = ref(false);
  const error = ref<string | null>(null);

  const selectedRow = computed(
    () => rows.value.find((r) => r.patientId === selectedPatientId.value) ?? null,
  );

  /** Only priced, outstanding charges can be taken to the payment dialog. */
  const payableCharges = computed(() => charges.value.filter((c) => c.isPayable));

  async function get(url: string) {
    const response = await fetch(url, {
      headers: JSON_HEADERS,
      credentials: "same-origin",
    });

    if (!response.ok) {
      throw new Error(t("cashier.error_load_failed"));
    }

    return response.json();
  }

  async function fetchQueue(silent = false): Promise<void> {
    if (!silent) isLoading.value = true;
    error.value = null;

    try {
      const params = new URLSearchParams({ status: activeTab.value });
      if (searchTerm.value.trim() !== "") {
        params.set("q", searchTerm.value.trim());
      }

      const [queue, statusCounts] = await Promise.all([
        get(`/api/v1/cashier/queue?${params.toString()}`),
        get("/api/v1/cashier/queue/status-counts"),
      ]);

      rows.value = queue?.data ?? [];
      counts.value = {
        awaiting_payment: statusCounts?.data?.awaiting_payment ?? 0,
        paid_today: statusCounts?.data?.paid_today ?? 0,
      };

      // A patient who has just been served drops off the list; keeping them
      // selected would leave the basket showing a stale, already-paid total.
      if (
        selectedPatientId.value !== null &&
        !rows.value.some((r) => r.patientId === selectedPatientId.value)
      ) {
        selectedPatientId.value = null;
        charges.value = [];
      }
    } catch (e) {
      error.value = (e as Error).message;
    } finally {
      isLoading.value = false;
    }
  }

  async function selectPatient(patientId: string | null): Promise<void> {
    selectedPatientId.value = patientId;
    charges.value = [];

    if (patientId === null) return;

    isLoadingCharges.value = true;

    try {
      const payload = await get(`/api/v1/cashier/patients/${patientId}/charges`);
      charges.value = payload?.data ?? [];
      basketTotalDue.value = payload?.meta?.amountDue ?? "0.00";
      basketCurrency.value = payload?.meta?.currencyCode ?? "TZS";
      basketUnpricedCount.value = payload?.meta?.unpricedCount ?? 0;
    } catch (e) {
      error.value = (e as Error).message;
    } finally {
      isLoadingCharges.value = false;
    }
  }

  async function refresh(): Promise<void> {
    const current = selectedPatientId.value;
    await fetchQueue(true);

    if (current !== null && rows.value.some((r) => r.patientId === current)) {
      await selectPatient(current);
    }
  }

  function setTab(tab: CashierQueueTab): void {
    activeTab.value = tab;
    void selectPatient(null);
    void fetchQueue();
  }

  return {
    activeTab,
    searchTerm,
    rows,
    counts,
    selectedPatientId,
    selectedRow,
    charges,
    payableCharges,
    basketTotalDue,
    basketCurrency,
    basketUnpricedCount,
    isLoading,
    isLoadingCharges,
    error,
    fetchQueue,
    selectPatient,
    refresh,
    setTab,
  };
}
