/**
 * usePharmacyOrders — Pharmacy Worklist & Dispensing Composable (Volume 2.6)
 * =========================================================================
 * Forensically audited against App\Modules\Pharmacy backend:
 * - Status Enum: 'pending' | 'in_preparation' | 'partially_dispensed' | 'dispensed' | 'cancelled'
 * - Filter Params: `q` for search query, `status` for exact status enum
 * - Status Counts: 'pending', 'in_preparation', 'partially_dispensed', 'dispensed', 'cancelled', 'total'
 * - State Progression:
 *     Step 1: 'pending' -> 'in_preparation' (Preparation started)
 *     Step 2: 'in_preparation' -> 'dispensed' (Batch & quantity fulfillment)
 *     Step 3: 'dispensed' -> Verified via /verify endpoint (Supervisor sign-off)
 */

import { computed, ref, watch } from "vue";
import { useToast } from "@/composables/useToast";

export type PharmacyOrderStatus =
  | "pending"
  | "in_preparation"
  | "partially_dispensed"
  | "dispensed"
  | "cancelled";

export interface SafetyConflict {
  id?: string;
  code?: string;
  name?: string;
  severity: "low" | "medium" | "high" | "critical";
  description?: string;
  clinicalEffect?: string;
  managementRecommendation?: string;
}

export interface InventoryBatchOption {
  id: string | null;
  batchNumber: string | null;
  internalBatchNumber?: string | null;
  expiryDate: string | null;
  quantity: number;
  reserved?: number;
  available: number;
}

export interface DispenseInventory {
  id: string | null;
  itemCode: string | null;
  itemName: string | null;
  unit: string | null;
  dispensingUnit: string | null;
  conversionFactor?: number | null;
  currentStock: number | null;
  onHandStock: number | null;
  reorderLevel: number | null;
  maxStockLevel?: number | null;
  status: string | null;
  stockState: "healthy" | "low_stock" | "out_of_stock" | string;
  batchTrackingMode: "untracked" | "tracked" | string;
  blockedBatchQuantity?: number;
  /** Whether the item has batch records at all, dispensable or not. */
  hasBatchRecords?: boolean;
  validBatchCount?: number;
  availableBatches: InventoryBatchOption[];
}

export interface PharmacySafetyReview {
  severity: "none" | "warning" | "critical";
  blockers: string[];
  warnings: string[];
  rules: any[];
  allergyConflicts: SafetyConflict[];
  interactionConflicts: SafetyConflict[];
  laboratorySignals: any[];
  policyRecommendation?: string | null;
  activeProfileMatches: any[];
  matchingActiveOrders: any[];
  sameEncounterDuplicates: any[];
  recentPatientDuplicates: any[];
  dispenseInventory?: DispenseInventory | null;
}

export interface PharmacyOrder {
  id: string;
  orderNumber?: string | null;
  patientId: string;
  patientName?: string;
  patientMrn?: string;
  patientGender?: string;
  patientAge?: number | string;
  patientDob?: string;
  patientPhone?: string;
  patient?: {
    id?: string;
    name?: string;
    mrn?: string;
    patientNumber?: string;
    firstName?: string;
    middleName?: string;
    lastName?: string;
    gender?: string;
    age?: string | number;
    dob?: string;
    dateOfBirth?: string;
    phone?: string;
  } | null;
  encounterId?: string | null;
  appointmentId?: string | null;
  admissionId?: string | null;
  approvedMedicineCatalogItemId?: string | null;
  medicationCode: string;
  medicationName: string;
  dosageInstruction?: string | null;
  doseQuantity?: number | string | null;
  doseUnit?: string | null;
  route?: string | null;
  frequency?: string | null;
  durationValue?: number | string | null;
  durationUnit?: string | null;
  clinicalIndication?: string | null;
  quantityPrescribed: number;
  prescribedUnit?: string | null;
  unitPrice?: number | null;
  totalPrice?: number | null;
  quantityDispensed?: number | null;
  dispensedUnit?: string | null;
  dispensingNotes?: string | null;
  dispensedAt?: string | null;
  /** Who released it. Verification refuses this user — see the verify path. */
  dispensedByUserId?: number | string | null;
  verifiedAt?: string | null;
  verifiedByUserId?: string | null;
  verifiedBy?: string | null;
  verificationNote?: string | null;
  orderingClinician?: string | null;
  orderedBy?: {
    id?: number | string;
    name?: string;
    email?: string;
  } | null;
  orderedAt: string;
  status: PharmacyOrderStatus;
  substitutionAllowed?: boolean;
  substitutionMade?: boolean;
  substitutedMedicationCode?: string | null;
  substitutedMedicationName?: string | null;
  substitutionReason?: string | null;
  reconciliationStatus?: string | null;
  visitStage?: string | null;
}

export function normalizePharmacyOrder(raw: any): PharmacyOrder {
  if (!raw) return raw;

  const patientObj = raw.patient || {};
  const pFirst = patientObj.firstName || raw.patientFirstName || "";
  const pMiddle = patientObj.middleName || "";
  const pLast = patientObj.lastName || raw.patientLastName || "";
  const pFullName = [pFirst, pMiddle, pLast].filter(Boolean).join(" ");

  const patientName =
    patientObj.name ||
    patientObj.fullName ||
    (pFullName.length > 0 ? pFullName : undefined) ||
    raw.patientName ||
    "Patient";

  const patientMrn =
    patientObj.mrn ||
    patientObj.patientNumber ||
    raw.patientMrn ||
    raw.patientNumber ||
    "—";

  const patientGender = patientObj.gender || raw.patientGender || "—";

  let patientAge = patientObj.age || raw.patientAge;
  const dob = patientObj.dateOfBirth || patientObj.dob || raw.patientDob;
  if (!patientAge && dob) {
    try {
      const birthYear = new Date(dob).getFullYear();
      if (!isNaN(birthYear)) {
        patientAge = `${new Date().getFullYear() - birthYear} yrs`;
      }
    } catch {
      patientAge = "—";
    }
  }
  if (!patientAge) {
    patientAge = "—";
  }

  const orderingClinician =
    raw.orderingClinician || raw.orderedBy?.name || raw.prescriberName || null;

  return {
    ...raw,
    patientId: String(raw.patientId || patientObj.id || ""),
    patientName,
    patientMrn,
    patientGender,
    patientAge,
    patientDob: dob,
    patientPhone: patientObj.phone || raw.patientPhone,
    orderingClinician,
  };
}

export interface PatientPharmacyGroup {
  patientId: string;
  patientName: string;
  patientMrn: string;
  patientGender: string;
  patientAge: string | number;
  patientDob?: string;
  orders: PharmacyOrder[];
  totalPrescriptions: number;
  pendingCount: number;
  inPreparationCount: number;
  partiallyDispensedCount: number;
  dispensedCount: number;
  verifiedCount: number;
}

export type PharmacyStage =
  | "pending_review"
  | "ready_for_dispense"
  | "dispensed_unverified"
  | "verified_completed"
  | "cancelled";

export function pharmacyStageOf(order: PharmacyOrder): PharmacyStage {
  if (order.status === "cancelled") {
    return "cancelled";
  }
  if (order.verifiedAt) {
    return "verified_completed";
  }
  if (order.dispensedAt || order.status === "dispensed") {
    return "dispensed_unverified";
  }
  if (
    order.status === "in_preparation" ||
    order.status === "partially_dispensed"
  ) {
    return "ready_for_dispense";
  }
  return "pending_review";
}

export type PharmacyTabId = "review" | "dispense" | "verify" | "audit";

export const PHARMACY_STAGE_TAB: Record<PharmacyStage, PharmacyTabId> = {
  pending_review: "review",
  ready_for_dispense: "dispense",
  dispensed_unverified: "verify",
  verified_completed: "verify",
  cancelled: "review",
};

/** The dispensing steps in the order the counter actually works them. */
const PHARMACY_STAGE_SEQUENCE = [
  "pending_review",
  "ready_for_dispense",
  "dispensed_unverified",
  "verified_completed",
] as const;

export function pharmacyStepIndex(stage: PharmacyStage): number {
  return (PHARMACY_STAGE_SEQUENCE as readonly PharmacyStage[]).indexOf(stage);
}

/**
 * A tab is reachable once the order has reached the step it serves. Reading
 * back is always allowed; jumping ahead of the counter is not.
 *
 * The server already refuses the invalid moves — pending goes only to
 * in_preparation, and verification demands a dispense that has happened. What
 * it could not do is stop a pharmacist picking a batch, typing a quantity and
 * pressing Complete Dispense on an order nobody has accepted yet, only to have
 * the whole thing thrown out on submit. This is the same rule, applied where
 * the work is entered rather than where it is saved.
 */
export function isPharmacyTabReachable(
  tab: PharmacyTabId,
  stage: PharmacyStage,
): boolean {
  if (tab === "audit" || tab === "review") return true;

  // A cancelled order keeps its review and its journey readable and opens
  // nothing else: there is no fill left to make and none to sign off.
  if (stage === "cancelled") return false;

  const reached = pharmacyStepIndex(stage);

  if (tab === "dispense") {
    return reached >= pharmacyStepIndex("ready_for_dispense");
  }

  return reached >= pharmacyStepIndex("dispensed_unverified");
}

export interface UsePharmacyOrders {
  orders: typeof orders;
  groupedOrders: typeof groupedOrders;
  statusCounts: typeof statusCounts;
  selectedOrderId: typeof selectedOrderId;
  selectedOrder: typeof selectedOrder;
  selectedPatientOrders: typeof selectedPatientOrders;
  activeTab: typeof activeTab;
  viewMode: typeof viewMode;
  selectedStatusFilter: typeof selectedStatusFilter;
  searchQuery: typeof searchQuery;
  safetyReview: typeof safetyReview;
  isLoadingOrders: typeof isLoadingOrders;
  isLoadingDetails: typeof isLoadingDetails;
  isActionLoading: typeof isActionLoading;
  fetchOrders: (silent?: boolean) => Promise<void>;
  selectOrder: (orderId: string) => Promise<void>;
  updateOrderStatus: (
    status:
      | "in_preparation"
      | "partially_dispensed"
      | "dispensed"
      | "cancelled",
    options?: {
      quantityDispensed?: number;
      dispensedUnit?: string;
      dispensingNotes?: string;
      batchId?: string;
      reason?: string;
    },
  ) => Promise<boolean>;
  verifyDispense: (verificationNote?: string) => Promise<boolean>;
  updatePolicy: (data: {
    formularyDecisionStatus: string;
    formularyDecisionReason?: string;
    substitutionAllowed: boolean;
    substitutionMade: boolean;
    substitutedMedicationCode?: string;
    substitutedMedicationName?: string;
    substitutionReason?: string;
  }) => Promise<boolean>;
  reconcileOrder: (data: {
    reconciliationStatus: string;
    reconciliationDecision?: string;
    reconciliationNote?: string;
  }) => Promise<boolean>;
}

// Global reactive state
const orders = ref<PharmacyOrder[]>([]);
const statusCounts = ref<Record<string, number>>({
  all: 0,
  pending: 0,
  in_preparation: 0,
  partially_dispensed: 0,
  dispensed: 0,
  cancelled: 0,
  total: 0,
});
const selectedOrderId = ref<string>("");
const selectedOrder = ref<PharmacyOrder | null>(null);
const activeTab = ref<PharmacyTabId>("review");
const viewMode = ref<"patient" | "prescription">("patient");
const selectedStatusFilter = ref<string>("all");
const searchQuery = ref<string>("");
const safetyReview = ref<PharmacySafetyReview | null>(null);

const isLoadingOrders = ref<boolean>(true);
const isLoadingDetails = ref<boolean>(false);
const isActionLoading = ref<boolean>(false);

/** The endpoint caps at 100; asking for more just gets clamped. */
const WORKLIST_PAGE_SIZE = 100;

/** Bumped per selection so a slow response cannot overwrite a newer one. */
let selectionToken = 0;

export function usePharmacyOrders(): UsePharmacyOrders {
  const toast = useToast();

  const groupedOrders = computed<PatientPharmacyGroup[]>(() => {
    const map = new Map<string, PatientPharmacyGroup>();

    for (const order of orders.value) {
      const pid = order.patientId || order.patientMrn || "unknown-pat";
      if (!map.has(pid)) {
        map.set(pid, {
          patientId: order.patientId,
          patientName: order.patientName || "Patient",
          patientMrn: order.patientMrn || "—",
          patientGender: order.patientGender || "—",
          patientAge: order.patientAge || "—",
          patientDob: order.patientDob,
          orders: [],
          totalPrescriptions: 0,
          pendingCount: 0,
          inPreparationCount: 0,
          partiallyDispensedCount: 0,
          dispensedCount: 0,
          verifiedCount: 0,
        });
      }

      const group = map.get(pid)!;
      group.orders.push(order);
      group.totalPrescriptions++;

      if (order.status === "pending") {
        group.pendingCount++;
      } else if (order.status === "in_preparation") {
        group.inPreparationCount++;
      } else if (order.status === "partially_dispensed") {
        group.partiallyDispensedCount++;
      } else if (order.status === "dispensed") {
        if (order.verifiedAt) {
          group.verifiedCount++;
        } else {
          group.dispensedCount++;
        }
      }
    }

    return Array.from(map.values());
  });

  const selectedPatientOrders = computed<PharmacyOrder[]>(() => {
    if (!selectedOrder.value) return [];
    return orders.value.filter(
      (o) => o.patientId === selectedOrder.value?.patientId,
    );
  });

  async function fetchOrders(silent = false): Promise<void> {
    if (!silent) isLoadingOrders.value = true;
    try {
      // The page size was never sent, so the endpoint fell back to its default
      // of 15 and the dispensing queue silently stopped at fifteen orders --
      // while the status chips above it, which come from a separate
      // server-side count, went on reporting the true totals. A counter with
      // eighty prescriptions waiting saw "Pending 80" over a list of fifteen
      // and no way to reach the rest.
      const params = new URLSearchParams({
        perPage: String(WORKLIST_PAGE_SIZE),
      });
      if (selectedStatusFilter.value !== "all") {
        params.append("status", selectedStatusFilter.value);
      }
      if (searchQuery.value.trim()) {
        params.append("q", searchQuery.value.trim());
      }

      // The counts have to answer the same question the list was asked, minus
      // the status itself -- they are a breakdown *by* status. Search was
      // missing here, so typing in the box narrowed the list and left every
      // chip reading its unfiltered total.
      const countsParams = new URLSearchParams();
      if (searchQuery.value.trim()) {
        countsParams.append("q", searchQuery.value.trim());
      }

      const [ordersRes, countsRes] = await Promise.all([
        fetch(`/api/v1/pharmacy/orders?${params.toString()}`, {
          headers: { Accept: "application/json" },
        }),
        fetch(
          `/api/v1/pharmacy/orders/status-counts?${countsParams.toString()}`,
          { headers: { Accept: "application/json" } },
        ),
      ]);

      if (ordersRes.ok) {
        const json = await ordersRes.json();
        orders.value = (json.data || []).map(normalizePharmacyOrder);

        // Never leave the workstation pane empty when there is work in the
        // queue. Three ways it ends up that way, and all three land here:
        // nothing has been selected yet, the selected order has since left the
        // worklist, or an id was restored from the URL by useWorkspaceUrlSync
        // -- which assigns the id ref directly and so never loads the detail
        // that the pane actually renders.
        if (orders.value.length > 0) {
          const currentExists = orders.value.some(
            (o) => o.id === selectedOrderId.value,
          );

          if (!currentExists) {
            await selectOrder(orders.value[0].id);
          } else if (selectedOrder.value?.id !== selectedOrderId.value) {
            await selectOrder(selectedOrderId.value);
          } else {
            // Same payload as the detail endpoint, so a poll can refresh the
            // open order straight off the row it just delivered.
            selectedOrder.value =
              orders.value.find((o) => o.id === selectedOrderId.value) ??
              selectedOrder.value;
          }
        } else {
          await selectOrder("");
        }
      }

      if (countsRes.ok) {
        const countsJson = await countsRes.json();
        statusCounts.value = countsJson.data || {};
      }
    } catch (err) {
      console.error("Failed to fetch pharmacy orders:", err);
    } finally {
      if (!silent) isLoadingOrders.value = false;
    }
  }

  async function selectOrder(orderId: string): Promise<void> {
    const token = ++selectionToken;

    if (!orderId) {
      selectedOrderId.value = "";
      selectedOrder.value = null;
      safetyReview.value = null;
      isLoadingDetails.value = false;
      return;
    }

    selectedOrderId.value = orderId;

    // Switching orders is instant, the way it is at the bench. The worklist row
    // and GET /orders/{id} are the same payload -- index and show run the same
    // transformer over the same three enrichers -- so there is nothing to wait
    // for: paint the row we already hold, and let the request refresh it.
    const listRow = orders.value.find((o) => o.id === orderId) ?? null;
    if (listRow) {
      selectedOrder.value = listRow;
      activeTab.value = PHARMACY_STAGE_TAB[pharmacyStageOf(listRow)];
    }

    // The review is computed server-side, so it cannot be painted from the row.
    // Drop it rather than leave the previous patient's interactions and
    // allergy blockers on screen beside this patient's prescription.
    safetyReview.value = null;

    // Only a real wait: an order we could not paint from the worklist.
    isLoadingDetails.value = listRow === null;

    try {
      const [orderRes, safetyRes] = await Promise.all([
        fetch(`/api/v1/pharmacy/orders/${orderId}`, {
          headers: { Accept: "application/json" },
        }),
        fetch(`/api/v1/pharmacy/orders/${orderId}/safety-review`, {
          headers: { Accept: "application/json" },
        }),
      ]);

      // Clicking down a queue faster than the network answers used to let an
      // earlier order's response land on top of a later one.
      if (token !== selectionToken) return;

      if (orderRes.ok) {
        const json = await orderRes.json();
        selectedOrder.value = normalizePharmacyOrder(json.data);

        // Only steer the tab if the pane was still empty; doing it on every
        // refresh would yank a pharmacist off a tab they had just opened.
        if (listRow === null) {
          const currentStage = pharmacyStageOf(selectedOrder.value!);
          activeTab.value = PHARMACY_STAGE_TAB[currentStage];
        }
      }

      if (safetyRes.ok) {
        const safetyJson = await safetyRes.json();
        safetyReview.value = safetyJson.data;
      }
    } catch (err) {
      console.error(
        `Failed to load pharmacy order details for ${orderId}:`,
        err,
      );
    } finally {
      if (token === selectionToken) isLoadingDetails.value = false;
    }
  }

  async function updateOrderStatus(
    status:
      | "in_preparation"
      | "partially_dispensed"
      | "dispensed"
      | "cancelled",
    options: {
      quantityDispensed?: number;
      dispensedUnit?: string;
      dispensingNotes?: string;
      batchId?: string;
      reason?: string;
    } = {},
  ): Promise<boolean> {
    if (!selectedOrderId.value) return false;

    isActionLoading.value = true;
    try {
      const csrfMeta = document.querySelector(
        'meta[name="csrf-token"]',
      ) as HTMLMetaElement;
      const response = await fetch(
        `/api/v1/pharmacy/orders/${selectedOrderId.value}/status`,
        {
          method: "PATCH",
          headers: {
            "Content-Type": "application/json",
            Accept: "application/json",
            "X-CSRF-TOKEN": csrfMeta?.content || "",
          },
          body: JSON.stringify({
            status,
            quantityDispensed: options.quantityDispensed,
            dispensedUnit: options.dispensedUnit,
            dispensingNotes: options.dispensingNotes,
            batchId: options.batchId,
            reason: options.reason,
          }),
        },
      );

      const data = await response.json();
      if (!response.ok) {
        throw new Error(data.message || "Failed to update prescription status");
      }

      toast.success(
        status === "dispensed"
          ? "Medication dispensed successfully"
          : status === "in_preparation"
            ? "Prescription preparation started"
            : `Prescription status updated to ${status.replace("_", " ")}`,
      );

      await selectOrder(selectedOrderId.value);
      await fetchOrders(true);
      return true;
    } catch (err: any) {
      toast.error(err.message || "An error occurred while updating status");
      return false;
    } finally {
      isActionLoading.value = false;
    }
  }

  async function verifyDispense(verificationNote?: string): Promise<boolean> {
    if (!selectedOrderId.value) return false;

    isActionLoading.value = true;
    try {
      const csrfMeta = document.querySelector(
        'meta[name="csrf-token"]',
      ) as HTMLMetaElement;
      const response = await fetch(
        `/api/v1/pharmacy/orders/${selectedOrderId.value}/verify`,
        {
          method: "PATCH",
          headers: {
            "Content-Type": "application/json",
            Accept: "application/json",
            "X-CSRF-TOKEN": csrfMeta?.content || "",
          },
          body: JSON.stringify({
            verificationNote:
              verificationNote || "Dispense verified by Pharmacist",
          }),
        },
      );

      const data = await response.json();
      if (!response.ok) {
        throw new Error(data.message || "Failed to verify dispensation");
      }

      toast.success("Dispensation verified & recorded in patient chart");
      await selectOrder(selectedOrderId.value);
      await fetchOrders(true);
      return true;
    } catch (err: any) {
      toast.error(err.message || "Verification failed");
      return false;
    } finally {
      isActionLoading.value = false;
    }
  }

  async function updatePolicy(data: {
    formularyDecisionStatus: string;
    formularyDecisionReason?: string;
    substitutionAllowed: boolean;
    substitutionMade: boolean;
    substitutedMedicationCode?: string;
    substitutedMedicationName?: string;
    substitutionReason?: string;
  }): Promise<boolean> {
    if (!selectedOrderId.value) return false;

    isActionLoading.value = true;
    try {
      const csrfMeta = document.querySelector(
        'meta[name="csrf-token"]',
      ) as HTMLMetaElement;
      const response = await fetch(
        `/api/v1/pharmacy/orders/${selectedOrderId.value}/policy`,
        {
          method: "PATCH",
          headers: {
            "Content-Type": "application/json",
            Accept: "application/json",
            "X-CSRF-TOKEN": csrfMeta?.content || "",
          },
          body: JSON.stringify(data),
        },
      );

      const resData = await response.json();
      if (!response.ok) {
        throw new Error(resData.message || "Failed to update formulary policy");
      }

      toast.success("Formulary policy & substitution updated");
      await selectOrder(selectedOrderId.value);
      await fetchOrders(true);
      return true;
    } catch (err: any) {
      toast.error(err.message || "Policy update failed");
      return false;
    } finally {
      isActionLoading.value = false;
    }
  }

  async function reconcileOrder(data: {
    reconciliationStatus: string;
    reconciliationDecision?: string;
    reconciliationNote?: string;
  }): Promise<boolean> {
    if (!selectedOrderId.value) return false;

    isActionLoading.value = true;
    try {
      const csrfMeta = document.querySelector(
        'meta[name="csrf-token"]',
      ) as HTMLMetaElement;
      const response = await fetch(
        `/api/v1/pharmacy/orders/${selectedOrderId.value}/reconciliation`,
        {
          method: "PATCH",
          headers: {
            "Content-Type": "application/json",
            Accept: "application/json",
            "X-CSRF-TOKEN": csrfMeta?.content || "",
          },
          body: JSON.stringify(data),
        },
      );

      const resData = await response.json();
      if (!response.ok) {
        throw new Error(resData.message || "Failed to reconcile medication");
      }

      toast.success("Medication reconciled successfully");
      await selectOrder(selectedOrderId.value);
      await fetchOrders(true);
      return true;
    } catch (err: any) {
      toast.error(err.message || "Reconciliation failed");
      return false;
    } finally {
      isActionLoading.value = false;
    }
  }

  // Watchers for reactive refetching
  watch([selectedStatusFilter, searchQuery], () => {
    fetchOrders();
  });

  return {
    orders,
    groupedOrders,
    statusCounts,
    selectedOrderId,
    selectedOrder,
    selectedPatientOrders,
    activeTab,
    viewMode,
    selectedStatusFilter,
    searchQuery,
    safetyReview,
    isLoadingOrders,
    isLoadingDetails,
    isActionLoading,
    fetchOrders,
    selectOrder,
    updateOrderStatus,
    verifyDispense,
    updatePolicy,
    reconcileOrder,
  };
}
