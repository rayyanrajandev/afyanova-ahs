/**
 * Clinician Results Review Composable (Volume 2.2 §9)
 * ====================================================
 * Fetches diagnostic lab and radiology results, provides clinical reference
 * ranges, critical value flagging, and doctor acknowledgment.
 */

import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import { useToast } from "@/composables/useToast";

export interface DiagnosticResultItem {
  id: string;
  encounterId?: string;
  patientId?: string;
  testName: string;
  category: "lab" | "imaging";
  value: string;
  unit?: string;
  referenceRange?: string;
  flag: "normal" | "abnormal" | "critical";
  performedAt: string;
  technicianName?: string;
  isAcknowledged: boolean;
  acknowledgedBy?: string;
  conclusion?: string;
}

export function useClinicianResults() {
  const { t } = useI18n({ useScope: "global" });
  const toast = useToast();

  const results = ref<DiagnosticResultItem[]>([]);
  const isResultsLoading = ref(false);
  const resultsError = ref<string | null>(null);

  async function fetchResults(patientId?: string) {
    isResultsLoading.value = true;
    resultsError.value = null;
    try {
      // 1. Fetch verified/completed Laboratory results
      const labUrl = patientId
        ? `/api/v1/clinician/results?patientId=${encodeURIComponent(patientId)}&status=completed`
        : "/api/v1/clinician/results?status=completed";

      // 2. Fetch completed/verified Radiology imaging results
      const radUrl = patientId
        ? `/api/v1/radiology/orders?patientId=${encodeURIComponent(patientId)}&status=completed`
        : "/api/v1/radiology/orders?status=completed";

      const [labRes, radRes] = await Promise.all([
        fetch(labUrl, { headers: { "X-Requested-With": "XMLHttpRequest" } }).catch(() => null),
        fetch(radUrl, { headers: { "X-Requested-With": "XMLHttpRequest" } }).catch(() => null),
      ]);

      const resultsList: DiagnosticResultItem[] = [];

      // Process Laboratory Results
      if (labRes && labRes.ok) {
        const body = await labRes.json();
        for (const item of (body.data ?? [])) {
          const isLab = !item.modality && (item.category === "lab" || !!item.testCode || !!item.labTestCatalogItemId || !!item.specimenType);

          // Strict Medicolegal Gate: Unverified lab work (draft) must NEVER appear on the clinician chart
          const isVerified = isLab ? !!item.verifiedAt : (!!item.verifiedAt || item.status === "completed");
          if (!isVerified) {
            continue;
          }

          const technicianName = item.verifiedBy || item.technicianName || item.performedBy || "Lab Staff";
          const performedAt = item.verifiedAt || item.resultedAt || item.performedAt || item.createdAt || new Date().toISOString();

          // If structured resultParameters exist, unpack each parameter for clean clinician review
          if (Array.isArray(item.resultParameters) && item.resultParameters.length > 0) {
            for (const param of item.resultParameters) {
              resultsList.push({
                id: `${item.id}-${param.code || param.name}`,
                encounterId: item.encounterId,
                patientId: item.patientId,
                testName: item.resultParameters.length > 1 ? `${item.testName} (${param.name})` : (item.testName || param.name),
                category: "lab",
                value: param.value !== undefined && param.value !== null && param.value !== "" ? String(param.value) : (item.resultSummary || "—"),
                unit: param.unit || item.catalogUnit || "",
                referenceRange: param.referenceRange || param.reference || "—",
                flag: param.flag === "critical" || param.isCritical ? "critical" : (param.flag === "abnormal" || param.isAbnormal ? "abnormal" : "normal"),
                performedAt,
                technicianName,
                isAcknowledged: !!item.acknowledgedAt || !!item.isAcknowledged,
                acknowledgedBy: item.acknowledgedBy,
                interpretation: item.verificationNote || item.clinicalNotes || item.resultSummary,
                conclusion: item.verificationNote || item.resultSummary,
              });
            }
          } else {
            // Single summary or qualitative result
            resultsList.push({
              id: item.id || `res-${Math.random()}`,
              encounterId: item.encounterId,
              patientId: item.patientId,
              testName: item.testName || item.test || "Diagnostic Test",
              category: isLab ? "lab" : "imaging",
              value: item.resultSummary || item.value || item.resultValue || "—",
              unit: item.catalogUnit || item.unit || "",
              referenceRange: item.referenceRange || item.reference || "—",
              flag: item.flag || (item.isCritical ? "critical" : item.isAbnormal ? "abnormal" : "normal"),
              performedAt,
              technicianName,
              isAcknowledged: !!item.acknowledgedAt || !!item.isAcknowledged,
              acknowledgedBy: item.acknowledgedBy,
              interpretation: item.verificationNote || item.interpretation || item.impression || item.notes || item.conclusion,
              conclusion: item.verificationNote || item.conclusion || item.impression || item.notes,
            });
          }
        }
      }

      // Process Radiology Imaging Results
      if (radRes && radRes.ok) {
        const radBody = await radRes.json();
        for (const order of (radBody.data ?? [])) {
          if (order.reportSummary || order.status === "completed" || order.verifiedAt) {
            const technicianName = order.verifiedBy || order.orderingClinician || "Radiology Specialist";
            const performedAt = order.verifiedAt || order.completedAt || order.orderedAt || new Date().toISOString();

            resultsList.push({
              id: `rad-${order.id}`,
              encounterId: order.appointmentId || undefined,
              patientId: order.patientId,
              testName: order.studyDescription || order.procedureCode || "Diagnostic Imaging Study",
              category: "imaging",
              value: order.verifiedAt ? "Final Verified Report" : "Report Submitted",
              unit: (order.modality || "RAD").toUpperCase(),
              referenceRange: `Modality: ${(order.modality || "Imaging").toUpperCase()}`,
              flag: "normal",
              performedAt,
              technicianName,
              isAcknowledged: false,
              interpretation: order.reportSummary || "Examination completed. Findings documented.",
              conclusion: order.verificationNote || order.clinicalIndication || undefined,
            });
          }
        }
      }

      // Sort newest results first
      resultsList.sort((a, b) => new Date(b.performedAt).getTime() - new Date(a.performedAt).getTime());
      results.value = resultsList;
    } catch (err: any) {
      resultsError.value = err.message;
    } finally {
      isResultsLoading.value = false;
    }
  }

  async function acknowledgeResult(resultId: string): Promise<boolean> {
    try {
      const res = await fetch(`/api/v1/clinician/results/${encodeURIComponent(resultId)}/acknowledge`, {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          "X-Requested-With": "XMLHttpRequest",
        },
      });

      if (!res.ok) {
        throw new Error("Failed to acknowledge result");
      }

      const target = results.value.find((r) => r.id === resultId);
      if (target) {
        target.isAcknowledged = true;
      }

      toast.success(t("clinician.acknowledged", "Result reviewed & acknowledged"));
      return true;
    } catch (err: any) {
      toast.error(err.message || "Failed to acknowledge result");
      return false;
    }
  }

  return {
    results,
    isResultsLoading,
    resultsError,
    fetchResults,
    acknowledgeResult,
  };
}
