/**
 * Nursing Assessment Store (Volume 2.3 §6, Volume 3.8 Phase 3)
 * ================================================================
 * Completes a patient's nurse-queue assessment by ordering downstream
 * services (lab/pharmacy/radiology/procedure) with a clinical note — this
 * is what actually clears an encounter off `GET /nursing/tasks` (Volume
 * 2.3 §9's task list is "open encounters with no assessed service
 * request" — `NurseQueueController::index`), not a free-text documentation
 * form. Confirmed by reading `NurseQueueController::assess()` and
 * `CompleteNurseAssessmentRequest` directly (2026-08-13) before building
 * this — the route also had a real backend bug (missing `{encounterId}`
 * URI segment, 500 on every call) fixed the same day.
 *
 * API endpoint: POST /api/v1/nursing/assessments/{encounterId}
 */

import { defineStore } from 'pinia';
import { ref } from 'vue';

export type AssessmentServiceType = 'laboratory' | 'pharmacy' | 'radiology' | 'clinical_procedure';

export interface AssessmentItemInput {
    itemName: string;
    serviceType: AssessmentServiceType;
    itemCode?: string;
    quantity?: number;
}

export interface AssessmentInput {
    clinicalNote: string;
    items: AssessmentItemInput[];
}

export const useNursingAssessmentStore = defineStore('nursingAssessment', () => {
    const isSaving = ref(false);
    const error = ref<string | null>(null);

    /** POST /nursing/assessments/{encounterId} */
    async function completeAssessment(encounterId: string, input: AssessmentInput): Promise<boolean> {
        isSaving.value = true;
        error.value = null;
        try {
            const res = await fetch(`/api/v1/nursing/assessments/${encodeURIComponent(encounterId)}`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify(input),
            });
            if (!res.ok) throw new Error('Failed to complete assessment');
            return true;
        } catch (e) {
            error.value = e instanceof Error ? e.message : 'Failed to complete assessment';
            return false;
        } finally {
            isSaving.value = false;
        }
    }

    return {
        isSaving,
        error,
        completeAssessment,
    };
});
