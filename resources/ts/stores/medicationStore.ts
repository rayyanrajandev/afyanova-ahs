/**
 * Medication Store (Volume 1.4 §3.1, Volume 2.2 §13.1, Volume 2.3 §12.1)
 * ======================================================================
 * Manages the drug catalog, prescriptions, and the MAR
 * (Medication Administration Record) for the Nursing workspace.
 *
 * API endpoints (Volume 2.3 §12.2):
 *   GET  /nursing/mar                    — get MAR for patient
 *   POST /nursing/mar/{id}/administer     — record administration (verifies 5 Rights)
 */

import { defineStore } from 'pinia';
import { ref } from 'vue';

export type MarStatus = 'due' | 'given' | 'missed' | 'omitted' | 'held' | 'refused' | 'overdue';

export interface MarMedication {
    id: string;
    patientId: string;
    name: string;
    dose: string;
    route: string;
    dueTime: string;
    status: MarStatus;
}

export const useMedicationStore = defineStore('medication', () => {
    // ---- State ----
    const mar = ref<MarMedication[]>([]);
    const isLoading = ref(false);
    const error = ref<string | null>(null);

    // ---- Actions ----

    /** GET /nursing/mar */
    async function fetchMar(patientId: string): Promise<MarMedication[]> {
        isLoading.value = true;
        error.value = null;
        try {
            const res = await fetch(`/nursing/mar?patient_id=${encodeURIComponent(patientId)}`, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
            if (!res.ok) throw new Error('Failed to fetch MAR');
            mar.value = (await res.json()) as MarMedication[];
            return mar.value;
        } catch (e) {
            error.value = e instanceof Error ? e.message : 'Failed to fetch MAR';
            return [];
        } finally {
            isLoading.value = false;
        }
    }

    /** POST /nursing/mar/{id}/administer — verifies the 5 Rights (Volume 2.3 §8.2) */
    async function administerMedication(id: string, verification: {
        rightPatient: boolean;
        rightMedication: boolean;
        rightDose: boolean;
        rightRoute: boolean;
        rightTime: boolean;
        notes?: string;
    }): Promise<boolean> {
        try {
            const res = await fetch(`/nursing/mar/${id}/administer`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify(verification),
            });
            if (!res.ok) throw new Error('Failed to administer medication');
            const med = mar.value.find((m) => m.id === id);
            if (med) med.status = 'given';
            return true;
        } catch (e) {
            error.value = e instanceof Error ? e.message : 'Failed to administer medication';
            return false;
        }
    }

    return {
        mar,
        isLoading,
        error,
        fetchMar,
        administerMedication,
    };
});