/**
 * Vitals Store (Volume 2.3 §7, Volume 3.8 Phase 2)
 * ==================================================
 * Records and reads back vital-sign sets for the Nursing workspace.
 *
 * API endpoints (Volume 2.3 §12.2):
 *   POST /api/v1/nursing/vitals               — record a vital-sign set (patient.vitals.record)
 *   GET  /api/v1/nursing/vitals/{patientId}    — latest + history for a patient (patients.read)
 *
 * The backend (StorePatientVitalSetRequest) supports temperature, heart rate,
 * respiratory rate, BP, SpO2, weight, and — since 2026-08-14 — height, BMI,
 * and pain score.
 *
 * `fetchLatest` added 2026-08-13 — reported directly by the user: a
 * recorded vital never showed up anywhere after the fact. `recordVitals`'s
 * own response only ever echoed `{id, patientId, recordedAt}`, not the
 * recorded values, so this was never wired to read anything back; the
 * "Recent Vitals" card was reading from a local, component-only ref that
 * resets on every navigation/reload instead. Reuses the same
 * `PatientVitalSetController::latestForPatient` the generic (pre-existing)
 * `patient-vitals/patient/{id}` route already calls, just through
 * nursing's own `/nursing/*` contract.
 */

import { defineStore } from 'pinia';
import { ref } from 'vue';

export interface VitalSetInput {
    patientId: string;
    temperatureC?: number;
    heartRateBpm?: number;
    respiratoryRateBpm?: number;
    systolicBpMmhg?: number;
    diastolicBpMmhg?: number;
    oxygenSaturationPct?: number;
    weightKg?: number;
    heightCm?: number;
    bmi?: number;
    painScore?: number;
    /**
     * Routing target chosen at triage completion. Recording vitals is what
     * advances a visit out of waiting_triage, so it is also the moment the
     * patient's department is decided — walk-ins arrive with none.
     */
    departmentId?: string;
}

export interface VitalSetRecorded {
    id: string;
    patientId: string;
    recordedAt: string;
}

export interface VitalSetRecord {
    id: string;
    patientId: string;
    recordedByUserId: number | null;
    recordedAt: string | null;
    temperatureC: number | null;
    heartRateBpm: number | null;
    systolicBpMmhg: number | null;
    diastolicBpMmhg: number | null;
    oxygenSaturationPct: number | null;
    respiratoryRateBpm: number | null;
    weightKg: number | null;
    heightCm: number | null;
    bmi: number | null;
    painScore: number | null;
}

export const useVitalsStore = defineStore('vitals', () => {
    const isSaving = ref(false);
    const isLoading = ref(false);
    const error = ref<string | null>(null);
    const latest = ref<VitalSetRecord | null>(null);
    const history = ref<VitalSetRecord[]>([]);

    /** POST /nursing/vitals */
    async function recordVitals(input: VitalSetInput): Promise<VitalSetRecorded | null> {
        isSaving.value = true;
        error.value = null;
        try {
            const res = await fetch('/api/v1/nursing/vitals', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify(input),
            });
            if (!res.ok) throw new Error('Failed to record vitals');
            const body = (await res.json()) as { data?: VitalSetRecorded };
            if (!body.data) throw new Error('Failed to record vitals');
            return body.data;
        } catch (e) {
            error.value = e instanceof Error ? e.message : 'Failed to record vitals';
            return null;
        } finally {
            isSaving.value = false;
        }
    }

    /** GET /nursing/vitals/{patientId} */
    async function fetchLatest(patientId: string): Promise<void> {
        isLoading.value = true;
        try {
            const res = await fetch(`/api/v1/nursing/vitals/${encodeURIComponent(patientId)}`, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
            if (!res.ok) throw new Error('Failed to fetch vitals');
            const body = (await res.json()) as { data?: { latest: VitalSetRecord | null; history: VitalSetRecord[] } };
            latest.value = body.data?.latest ?? null;
            history.value = body.data?.history ?? [];
        } catch (e) {
            error.value = e instanceof Error ? e.message : 'Failed to fetch vitals';
            latest.value = null;
            history.value = [];
        } finally {
            isLoading.value = false;
        }
    }

    return {
        isSaving,
        isLoading,
        error,
        latest,
        history,
        recordVitals,
        fetchLatest,
    };
});
