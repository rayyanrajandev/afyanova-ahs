/**
 * Results Store (Volume 1.4 §3.1, Volume 2.2 §13.1)
 * ==================================================
 * Manages lab/imaging results and critical results.
 * Used by the Clinician workspace (Volume 2.2) and Nursing (Volume 2.3).
 *
 * API endpoints (Volume 2.2 §13.2):
 *   GET  /clinician/results                    — get results
 *   POST /clinician/results/{id}/acknowledge   — acknowledge result
 */

import { defineStore } from 'pinia';
import { computed, ref } from 'vue';

export interface Result {
    id: string;
    patientId: string;
    test: string;
    value: string;
    reference: string;
    flag: 'normal' | 'abnormal' | 'critical';
    date: string;
    acknowledged: boolean;
}

export const useResultsStore = defineStore('results', () => {
    // ---- State ----
    const results = ref<Result[]>([]);
    const isLoading = ref(false);
    const error = ref<string | null>(null);

    // ---- Getters ----
    const criticalResults = computed(() => results.value.filter((r) => r.flag === 'critical'));
    const unacknowledgedResults = computed(() => results.value.filter((r) => !r.acknowledged));

    // ---- Actions ----

    /** GET /clinician/results */
    async function fetchResults(patientId?: string): Promise<Result[]> {
        isLoading.value = true;
        error.value = null;
        try {
            const query = patientId ? `?patient_id=${encodeURIComponent(patientId)}` : '';
            const res = await fetch(`/clinician/results${query}`, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
            if (!res.ok) throw new Error('Failed to fetch results');
            results.value = (await res.json()) as Result[];
            return results.value;
        } catch (e) {
            error.value = e instanceof Error ? e.message : 'Failed to fetch results';
            return [];
        } finally {
            isLoading.value = false;
        }
    }

    /** POST /clinician/results/{id}/acknowledge */
    async function acknowledgeResult(id: string): Promise<boolean> {
        try {
            const res = await fetch(`/clinician/results/${id}/acknowledge`, {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
            if (!res.ok) throw new Error('Failed to acknowledge result');
            const result = results.value.find((r) => r.id === id);
            if (result) result.acknowledged = true;
            return true;
        } catch (e) {
            error.value = e instanceof Error ? e.message : 'Failed to acknowledge result';
            return false;
        }
    }

    return {
        results,
        isLoading,
        error,
        criticalResults,
        unacknowledgedResults,
        fetchResults,
        acknowledgeResult,
    };
});