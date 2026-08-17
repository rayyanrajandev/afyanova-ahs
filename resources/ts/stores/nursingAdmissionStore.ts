/**
 * Nursing Admission Store (Volume 2.3)
 * =========================================================================
 * Escalate/admit a patient from the Nursing workspace to an inpatient admission.
 *
 * API endpoint: POST /api/v1/nursing/admissions
 */

import { defineStore } from "pinia";
import { ref } from "vue";

export interface NursingAdmissionInput {
  patientId: string;
  encounterId: string;
  appointmentId?: string | null;
  admittedAt: string;
  admissionReason?: string | null;
  ward?: string | null;
  bed?: string | null;
  notes?: string | null;
}

export interface NursingAdmissionResponse {
  admission: {
    id: string;
    admissionNumber: string;
    status: string;
    admittedAt: string;
    ward: string | null;
    bed: string | null;
    admissionReason: string | null;
  };
  encounter: {
    id: string;
    type: string;
    status: string;
    admissionId: string;
  };
}

export const useNursingAdmissionStore = defineStore("nursingAdmission", () => {
  const isSaving = ref(false);
  const error = ref<string | null>(null);

  /** POST /nursing/admissions */
  async function createAdmission(input: NursingAdmissionInput): Promise<NursingAdmissionResponse | null> {
    isSaving.value = true;
    error.value = null;
    try {
      const res = await fetch("/api/v1/nursing/admissions", {
        method: "POST",
        headers: { "Content-Type": "application/json", "X-Requested-With": "XMLHttpRequest" },
        body: JSON.stringify(input),
      });

      if (!res.ok) {
        const body = (await res.json().catch(() => null)) as { message?: string; errors?: Record<string, string[]> } | null;
        const msg = body?.message || "Failed to create admission";
        throw new Error(msg);
      }

      const body = (await res.json()) as { data?: NursingAdmissionResponse };
      if (!body.data) throw new Error("Invalid response format from server");
      return body.data;
    } catch (e) {
      error.value = e instanceof Error ? e.message : "Failed to create admission";
      return null;
    } finally {
      isSaving.value = false;
    }
  }

  return {
    isSaving,
    error,
    createAdmission,
  };
});
