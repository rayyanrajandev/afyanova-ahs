/**
 * useRadiologyOrders — Radiology / Imaging Worklist Composable
 * =============================================================================
 * The imaging counterpart of useLaboratoryOrders, and deliberately not a copy of
 * it. Two differences drive the whole workspace:
 *
 *  - **Radiology schedules; the lab accessions.** The status vocabulary is
 *    `ordered -> scheduled -> in_progress -> completed`, where the lab's is
 *    `ordered -> collected -> in_progress -> completed`. `scheduled` is a real
 *    booking against a modality and a time (`scheduledFor`), not a specimen
 *    arriving at a bench, so the first tab of this workspace is a scheduler.
 *
 *  - **There is no specimen.** A study is identified by modality (X-ray, CT,
 *    MRI, ultrasound) and a study description. Nothing here has a tube type, a
 *    sample integrity check, or a parameter matrix — a radiology result is a
 *    narrative report with an impression, not a table of analyte values.
 *
 * Status values are the backend's own, verbatim. The laboratory workspace once
 * renamed `collected` to `sample_collected` on read and back on write, which
 * made every status comparison in that file silently false; that mistake is not
 * repeated here.
 */

import { computed, ref } from "vue";
import { useToast } from "@/composables/useToast";

/** Matches App\Modules\Radiology\Domain\ValueObjects\RadiologyOrderStatus. */
export type RadiologyOrderStatus =
  | "ordered"
  | "scheduled"
  | "in_progress"
  | "completed"
  | "cancelled";

export type RadiologyModality = "xray" | "ct" | "mri" | "ultrasound" | "mammography" | "fluoroscopy";

export interface RadiologyOrder {
  id: string;
  orderNumber?: string;
  patientId: string;
  patientName?: string;
  patientMrn?: string;
  patientGender?: string;
  patientAge?: number | string;
  appointmentId?: string | null;
  modality: RadiologyModality | string;
  procedureCode?: string | null;
  studyDescription: string;
  clinicalIndication?: string | null;
  priority: "routine" | "urgent" | "stat";
  status: RadiologyOrderStatus;
  orderedAt?: string | null;
  orderingClinician?: string | null;
  /** The booked slot. Null until someone schedules the study. */
  scheduledFor?: string | null;
  reportSummary?: string | null;
  completedAt?: string | null;
  verifiedAt?: string | null;
  verifiedBy?: string | null;
  verificationNote?: string | null;
  statusReason?: string | null;
  /**
   * Where the patient stands in the whole visit, resolved server-side by
   * ClinicalOrderVisitStageEnricher — the same value the reception, nursing and
   * clinician boards show. Null for a direct-service order with no appointment.
   */
  visitStage?: string | null;
}

export interface PatientRadiologyGroup {
  patientId: string;
  patientName: string;
  patientMrn: string;
  patientGender?: string;
  patientAge?: number | string;
  orders: RadiologyOrder[];
  totalStudies: number;
  orderedCount: number;
  scheduledCount: number;
  inProgressCount: number;
  completedCount: number;
  highestPriority: "routine" | "urgent" | "stat";
  latestOrderedAt?: string | null;
  modalities: string[];
}

/** Statuses still needing work from this department. */
const OPEN_STATUSES: RadiologyOrderStatus[] = ["ordered", "scheduled", "in_progress"];

export function useRadiologyOrders() {
  const toast = useToast();

  const orders = ref<RadiologyOrder[]>([]);
  const selectedOrderId = ref<string | null>(null);
  const selectedPatientId = ref<string | null>(null);
  const viewMode = ref<"patient" | "study">("patient");
  const isLoadingOrders = ref(false);
  const isUpdatingOrder = ref(false);
  const isVerifying = ref(false);

  const searchQuery = ref("");
  const selectedStatusFilter = ref<RadiologyOrderStatus | "all">("all");
  const selectedModalityFilter = ref<RadiologyModality | "all">("all");

  const selectedOrder = computed<RadiologyOrder | null>(() =>
    selectedOrderId.value ? (orders.value.find((o) => o.id === selectedOrderId.value) ?? null) : null,
  );

  /** Every open study for the selected order's patient — a patient rarely has one. */
  const selectedPatientOrders = computed<RadiologyOrder[]>(() => {
    const patientId = selectedOrder.value?.patientId || selectedPatientId.value;
    if (!patientId) return [];

    return orders.value.filter((o) => o.patientId === patientId);
  });

  const patientGroups = computed<PatientRadiologyGroup[]>(() => {
    const map = new Map<string, PatientRadiologyGroup>();

    for (const order of orders.value) {
      const pid = order.patientId || order.patientMrn || "unknown-pat";
      if (!map.has(pid)) {
        map.set(pid, {
          patientId: order.patientId,
          patientName: order.patientName || "Patient",
          patientMrn: order.patientMrn || "MRN-0000",
          patientGender: order.patientGender,
          patientAge: order.patientAge,
          orders: [],
          totalStudies: 0,
          orderedCount: 0,
          scheduledCount: 0,
          inProgressCount: 0,
          completedCount: 0,
          highestPriority: "routine",
          latestOrderedAt: order.orderedAt,
          modalities: [],
        });
      }

      const group = map.get(pid)!;
      group.orders.push(order);
      group.totalStudies++;

      if (order.status === "ordered") group.orderedCount++;
      else if (order.status === "scheduled") group.scheduledCount++;
      else if (order.status === "in_progress") group.inProgressCount++;
      else if (order.status === "completed") group.completedCount++;

      if (!group.modalities.includes(order.modality)) {
        group.modalities.push(order.modality);
      }

      if (order.priority === "stat") {
        group.highestPriority = "stat";
      } else if (order.priority === "urgent" && group.highestPriority !== "stat") {
        group.highestPriority = "urgent";
      }
    }

    return Array.from(map.values()).sort((a, b) => {
      const rank = { stat: 0, urgent: 1, routine: 2 } as const;
      const byPriority = (rank[a.highestPriority] ?? 2) - (rank[b.highestPriority] ?? 2);
      if (byPriority !== 0) return byPriority;
      return new Date(b.latestOrderedAt ?? 0).getTime() - new Date(a.latestOrderedAt ?? 0).getTime();
    });
  });

  const filteredPatientGroups = computed<PatientRadiologyGroup[]>(() => {
    let list = patientGroups.value;
    const query = searchQuery.value.trim().toLowerCase();

    if (query !== "") {
      list = list.filter(
        (g) =>
          g.patientName.toLowerCase().includes(query) ||
          g.patientMrn.toLowerCase().includes(query) ||
          g.orders.some(
            (o) =>
              (o.studyDescription ?? "").toLowerCase().includes(query) ||
              (o.orderNumber ?? "").toLowerCase().includes(query),
          ),
      );
    }

    if (selectedStatusFilter.value !== "all") {
      list = list.filter((g) => {
        if (selectedStatusFilter.value === "ordered") return g.orderedCount > 0;
        if (selectedStatusFilter.value === "scheduled") return g.scheduledCount > 0;
        if (selectedStatusFilter.value === "in_progress") return g.inProgressCount > 0;
        if (selectedStatusFilter.value === "completed") return g.completedCount > 0;
        return true;
      });
    }

    if (selectedModalityFilter.value !== "all") {
      list = list.filter((g) => g.modalities.includes(selectedModalityFilter.value));
    }

    return list;
  });

  const statusCounts = computed<Record<RadiologyOrderStatus | "all", number>>(() => {
    const counts = {
      all: orders.value.length,
      ordered: 0,
      scheduled: 0,
      in_progress: 0,
      completed: 0,
      cancelled: 0,
    } as Record<RadiologyOrderStatus | "all", number>;

    for (const order of orders.value) {
      if (order.status in counts) counts[order.status] += 1;
    }

    return counts;
  });

  const filteredOrders = computed<RadiologyOrder[]>(() => {
    const query = searchQuery.value.trim().toLowerCase();

    return orders.value.filter((order) => {
      if (selectedStatusFilter.value !== "all" && order.status !== selectedStatusFilter.value) return false;
      if (selectedModalityFilter.value !== "all" && order.modality !== selectedModalityFilter.value) return false;
      if (query === "") return true;

      return [order.patientName, order.patientMrn, order.studyDescription, order.orderNumber]
        .some((field) => (field ?? "").toLowerCase().includes(query));
    });
  });

  /** Worklist ordering: STAT first, then urgent, then oldest order first. */
  const worklistOrders = computed<RadiologyOrder[]>(() => {
    const rank = { stat: 0, urgent: 1, routine: 2 } as const;

    return [...filteredOrders.value].sort((a, b) => {
      const byPriority = (rank[a.priority] ?? 2) - (rank[b.priority] ?? 2);
      if (byPriority !== 0) return byPriority;

      return new Date(a.orderedAt ?? 0).getTime() - new Date(b.orderedAt ?? 0).getTime();
    });
  });

  async function fetchOrders(): Promise<void> {
    isLoadingOrders.value = true;
    try {
      const res = await fetch("/api/v1/radiology/orders?perPage=50", {
        headers: { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" },
      });
      if (!res.ok) throw new Error("Failed to load the imaging worklist");

      const body = (await res.json()) as { data?: Array<Record<string, unknown>> };

      orders.value = (body.data ?? []).map((raw) => {
        const patient = (raw.patient ?? {}) as Record<string, unknown>;
        const pFirst = (patient.firstName as string) || (raw.patientFirstName as string) || "";
        const pMiddle = (patient.middleName as string) || "";
        const pLast = (patient.lastName as string) || (raw.patientLastName as string) || "";
        const pFullName = [pFirst, pMiddle, pLast].filter(Boolean).join(" ");

        const patientName =
          (patient.name as string) ||
          (patient.fullName as string) ||
          (pFullName.length > 0 ? pFullName : (raw.patientName as string) || undefined);
        const patientMrn =
          (patient.mrn as string) ||
          (patient.patientNumber as string) ||
          (raw.patientMrn as string) ||
          (raw.patientNumber as string) ||
          undefined;
        const patientGender =
          (patient.gender as string) || (raw.patientGender as string) || undefined;
        const patientAge =
          (patient.age as string | number) ||
          (raw.patientAge as string | number) ||
          (patient.dateOfBirth ? `${new Date().getFullYear() - new Date(patient.dateOfBirth as string).getFullYear()} yrs` : undefined);

        return {
          id: String(raw.id),
          orderNumber: (raw.orderNumber as string) ?? undefined,
          patientId: String(raw.patientId ?? ""),
          patientName,
          patientMrn,
          patientGender,
          patientAge,
          appointmentId: (raw.appointmentId as string) ?? null,
          modality: (raw.modality as string) ?? "xray",
          procedureCode: (raw.procedureCode as string) ?? null,
          studyDescription: (raw.studyDescription as string) ?? "Imaging study",
          clinicalIndication: (raw.clinicalIndication as string) ?? null,
          priority: ((raw.priority as string) ?? "routine") as RadiologyOrder["priority"],
          status: ((raw.status as string) ?? "ordered") as RadiologyOrderStatus,
          orderedAt: (raw.orderedAt as string) ?? null,
          orderingClinician: (raw.orderingClinician as string) ?? null,
          scheduledFor: (raw.scheduledFor as string) ?? null,
          reportSummary: (raw.reportSummary as string) ?? null,
          completedAt: (raw.completedAt as string) ?? null,
          verifiedAt: (raw.verifiedAt as string) ?? null,
          verifiedBy: (raw.verifiedBy as string) ?? null,
          verificationNote: (raw.verificationNote as string) ?? null,
          statusReason: (raw.statusReason as string) ?? null,
          visitStage: (raw.visitStage as string) ?? null,
        } satisfies RadiologyOrder;
      });

      // Keep a selection if it survived the refresh; otherwise pick the first
      // piece of open work rather than whatever happens to sort first.
      if (!selectedOrderId.value || !orders.value.some((o) => o.id === selectedOrderId.value)) {
        selectedOrderId.value = worklistOrders.value.find((o) => OPEN_STATUSES.includes(o.status))?.id ?? null;
      }
    } catch {
      toast.error("Could not load the imaging worklist. Check your connection and try again.");
    } finally {
      isLoadingOrders.value = false;
    }
  }

  function selectOrder(orderId: string | null): void {
    selectedOrderId.value = orderId;
    if (orderId) {
      const order = orders.value.find((o) => o.id === orderId);
      if (order?.patientId) {
        selectedPatientId.value = order.patientId;
      }
    }
  }

  function selectPatient(patientId: string): void {
    selectedPatientId.value = patientId;
    const group = patientGroups.value.find((g) => g.patientId === patientId);
    if (group && group.orders.length > 0) {
      // Pick first uncompleted order, or the first order
      const primaryOrder = group.orders.find((o) => OPEN_STATUSES.includes(o.status)) || group.orders[0];
      selectedOrderId.value = primaryOrder.id;
    }
  }

  /**
   * The single write door for status changes. Every transition in this
   * workspace goes through here so the flow event, the audit row and the board
   * broadcast happen exactly once per action — the backend
   * (RecordRadiologyFlowTransitionService) does the rest.
   */
  async function updateStatus(
    orderId: string,
    status: RadiologyOrderStatus,
    extra: { reason?: string | null; scheduledFor?: string | null; reportSummary?: string | null } = {},
  ): Promise<boolean> {
    isUpdatingOrder.value = true;
    try {
      const res = await fetch(`/api/v1/radiology/orders/${encodeURIComponent(orderId)}/status`, {
        method: "PATCH",
        headers: {
          "Content-Type": "application/json",
          Accept: "application/json",
          "X-Requested-With": "XMLHttpRequest",
        },
        body: JSON.stringify({ status, ...extra }),
      });

      if (!res.ok) {
        const failure = (await res.json().catch(() => ({}))) as { message?: string };
        toast.error(failure.message ?? "Could not update the study. Try again.");
        return false;
      }

      await fetchOrders();
      return true;
    } catch {
      toast.error("Could not update the study. Check your connection and try again.");
      return false;
    } finally {
      isUpdatingOrder.value = false;
    }
  }

  /** Books the study into a slot. `ordered -> scheduled`. */
  const scheduleStudy = (orderId: string, scheduledFor: string) =>
    updateStatus(orderId, "scheduled", { scheduledFor });

  /** The patient is on the table. `scheduled -> in_progress`. */
  const startStudy = (orderId: string) => updateStatus(orderId, "in_progress");

  /** The report is written. `in_progress -> completed`, still unverified. */
  const submitReport = (orderId: string, reportSummary: string) =>
    updateStatus(orderId, "completed", { reportSummary });

  const cancelStudy = (orderId: string, reason: string) =>
    updateStatus(orderId, "cancelled", { reason });

  /**
   * Radiologist sign-off. Separate from completing the study on purpose: the
   * backend enforces a two-person rule, so whoever performed and reported the
   * study cannot also release it.
   */
  async function verifyReport(orderId: string, verificationNote: string): Promise<boolean> {
    isVerifying.value = true;
    try {
      const res = await fetch(`/api/v1/radiology/orders/${encodeURIComponent(orderId)}/verify`, {
        method: "PATCH",
        headers: {
          "Content-Type": "application/json",
          Accept: "application/json",
          "X-Requested-With": "XMLHttpRequest",
        },
        body: JSON.stringify({ verificationNote }),
      });

      if (!res.ok) {
        const failure = (await res.json().catch(() => ({}))) as { message?: string };
        // The two-person rule and "report required before verification" both
        // arrive here as a plain message; showing it verbatim is more useful
        // than a generic failure, because the reason is the whole point.
        toast.error(failure.message ?? "Could not verify this report. Try again.");
        return false;
      }

      toast.success("Report verified and released.");
      await fetchOrders();
      return true;
    } catch {
      toast.error("Could not verify this report. Check your connection and try again.");
      return false;
    } finally {
      isVerifying.value = false;
    }
  }

  return {
    orders,
    selectedOrderId,
    selectedPatientId,
    selectedOrder,
    selectedPatientOrders,
    viewMode,
    patientGroups,
    filteredPatientGroups,
    isLoadingOrders,
    isUpdatingOrder,
    isVerifying,
    searchQuery,
    selectedStatusFilter,
    selectedModalityFilter,
    statusCounts,
    filteredOrders,
    worklistOrders,
    fetchOrders,
    selectOrder,
    selectPatient,
    scheduleStudy,
    startStudy,
    submitReport,
    cancelStudy,
    verifyReport,
  };
}

export type UseRadiologyOrders = ReturnType<typeof useRadiologyOrders>;
