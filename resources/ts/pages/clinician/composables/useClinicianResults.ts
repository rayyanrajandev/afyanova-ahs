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
      // Only fetch verified/completed results — unverified lab work must not be visible to clinicians
      const url = patientId
        ? `/api/v1/clinician/results?patientId=${encodeURIComponent(patientId)}&status=completed`
        : "/api/v1/clinician/results?status=completed";

      const res = await fetch(url, {
        headers: { "X-Requested-With": "XMLHttpRequest" },
      });

      if (!res.ok) {
        throw new Error("Failed to fetch diagnostic results");
      }

      const body = await res.json();
      const mapped = (body.data ?? []).map((item: any) => ({
        id: item.id || `res-${Math.random()}`,
        encounterId: item.encounterId,
        patientId: item.patientId,
        testName: item.testName || item.test || "Diagnostic Test",
        category: item.category || (item.modality ? "imaging" : "lab"),
        value: item.value || item.resultValue || "—",
        unit: item.unit || "",
        referenceRange: item.referenceRange || item.reference || "—",
        flag: item.flag || (item.isCritical ? "critical" : item.isAbnormal ? "abnormal" : "normal"),
        performedAt: item.performedAt || item.date || item.createdAt || new Date().toISOString(),
        technicianName: item.technicianName || item.performedBy || "Lab Staff",
        isAcknowledged: !!item.acknowledgedAt || !!item.isAcknowledged,
        acknowledgedBy: item.acknowledgedBy,
        interpretation: item.interpretation || item.impression || item.notes || item.conclusion,
        conclusion: item.conclusion || item.impression || item.notes,
        status: item.status,
        isVerified: !!item.verifiedAt || item.status === "completed",
      }));

      // Defense-in-depth: only show verified results even if backend returns mixed statuses
      results.value = mapped.filter((r: any) => r.isVerified || r.status === "completed");
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
