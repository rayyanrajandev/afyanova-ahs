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
  /** Still owed. Zero once settled — which is why the paid tab needs the next one. */
  amountDue: string;
  /** Already taken. What the "Paid today" tab is actually reporting. */
  amountPaid: string;
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
  /**
   * Whether money can be taken for this charge right now.
   *
   * False for two unrelated reasons — the charge is unpriced, or it has already
   * been settled — so a screen must consult `pricingStatus` before explaining
   * which. Rendering both as "Not priced" labelled paid consultations unpriced
   * the moment settled charges could reach the basket.
   */
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

  /**
   * Only the newest request may write to the queue.
   *
   * Several can be in flight at once — a keystroke in the search box, a live
   * sync refetch, a tab switch — and they do not come back in the order they
   * were sent. Without this the response for "As" can land after the one for
   * "Asha" and overwrite it, which is why the list appeared to change on its
   * own and sometimes showed nothing.
   */
  let latestQueueRequest = 0;
  let latestChargesRequest = 0;
  let searchTimer: ReturnType<typeof setTimeout> | null = null;

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
    const request = ++latestQueueRequest;

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

      // A response that has been overtaken is thrown away, not rendered.
      if (request !== latestQueueRequest) return;

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
      if (request === latestQueueRequest) {
        error.value = (e as Error).message;
      }
    } finally {
      if (request === latestQueueRequest) {
        isLoading.value = false;
      }
    }
  }

  /**
   * Typing is not a reason to hit the server. Waits for a pause, and cancels
   * the previous wait so a fast typist sends one request rather than one per
   * character.
   */
  function search(term: string): void {
    searchTerm.value = term;

    if (searchTimer !== null) clearTimeout(searchTimer);
    searchTimer = setTimeout(() => void fetchQueue(true), 250);
  }

  async function selectPatient(patientId: string | null): Promise<void> {
    selectedPatientId.value = patientId;
    charges.value = [];

    if (patientId === null) return;

    const request = ++latestChargesRequest;
    isLoadingCharges.value = true;

    try {
      // The basket defaults to what is owed, which is nothing once a patient
      // has paid — so opening someone from the "Paid today" tab showed an empty
      // pane. The endpoint has always accepted this; nothing ever sent it.
      const payload = await get(
        `/api/v1/cashier/patients/${patientId}/charges` +
          (activeTab.value === "paid_today" ? "?includeSettled=1" : ""),
      );

      // The cashier may have clicked someone else while this was in flight.
      if (request !== latestChargesRequest || selectedPatientId.value !== patientId) return;

      charges.value = payload?.data ?? [];
      basketTotalDue.value = payload?.meta?.amountDue ?? "0.00";
      basketCurrency.value = payload?.meta?.currencyCode ?? "TZS";
      basketUnpricedCount.value = payload?.meta?.unpricedCount ?? 0;
    } catch (e) {
      if (request === latestChargesRequest) {
        error.value = (e as Error).message;
      }
    } finally {
      if (request === latestChargesRequest) {
        isLoadingCharges.value = false;
      }
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
    search,
    selectPatient,
    refresh,
    setTab,
  };
}
