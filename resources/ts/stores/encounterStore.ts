/**
 * Encounter Store (Volume 1.4 §3.1, Volume 2.2 §13.1)
 * ====================================================
 * Manages encounters and the current encounter.
 * Used by the Clinician workspace (Volume 2.2).
 *
 * API endpoints (Volume 2.2 §13.2):
 *   GET  /clinician/encounters          — list encounters
 *   POST /clinician/encounters          — start encounter
 *   GET  /clinician/encounters/{id}     — encounter detail
 */

import { defineStore } from 'pinia';
import { computed, ref } from 'vue';

export interface Encounter {
    id: string;
    patientId: string;
    patientName: string;
    type: string;
    status: 'open' | 'in_progress' | 'closed' | 'cancelled';
    startedAt: string;
    endedAt?: string | null;
    clinicianId?: string | null;
    reason?: string | null;
}

export const useEncounterStore = defineStore('encounter', () => {
    // ---- State ----
    const encounters = ref<Encounter[]>([]);
    const currentEncounterId = ref<string | null>(null);
    const isLoading = ref(false);
    const error = ref<string | null>(null);

    // ---- Getters ----
    const currentEncounter = computed(() =>
        currentEncounterId.value ? encounters.value.find((e) => e.id === currentEncounterId.value) ?? null : null,
    );

    // ---- Actions ----

    /** GET /clinician/encounters */
    async function fetchEncounters(patientId?: string): Promise<Encounter[]> {
        isLoading.value = true;
        error.value = null;
        try {
            const query = patientId ? `?patient_id=${encodeURIComponent(patientId)}` : '';
            const res = await fetch(`/api/v1/clinician/encounters${query}`, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
            if (!res.ok) throw new Error('Failed to fetch encounters');
            encounters.value = (await res.json()) as Encounter[];
            return encounters.value;
        } catch (e) {
            error.value = e instanceof Error ? e.message : 'Failed to fetch encounters';
            return [];
        } finally {
            isLoading.value = false;
        }
    }

    /** POST /clinician/encounters */
    async function startEncounter(payload: {
        patientId: string;
        type: string;
        reason?: string;
    }): Promise<Encounter | null> {
        isLoading.value = true;
        error.value = null;
        try {
            const res = await fetch('/api/v1/clinician/encounters', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify(payload),
            });
            if (!res.ok) throw new Error('Failed to start encounter');
            const encounter = (await res.json()) as Encounter;
            encounters.value.unshift(encounter);
            currentEncounterId.value = encounter.id;
            return encounter;
        } catch (e) {
            error.value = e instanceof Error ? e.message : 'Failed to start encounter';
            return null;
        } finally {
            isLoading.value = false;
        }
    }

    /** GET /clinician/encounters/{id} */
    async function fetchEncounter(id: string): Promise<Encounter | null> {
        isLoading.value = true;
        error.value = null;
        try {
            const res = await fetch(`/api/v1/clinician/encounters/${id}`, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
            if (!res.ok) throw new Error('Failed to fetch encounter');
            const encounter = (await res.json()) as Encounter;
            const index = encounters.value.findIndex((e) => e.id === id);
            if (index !== -1) {
                encounters.value[index] = encounter;
            } else {
                encounters.value.unshift(encounter);
            }
            return encounter;
        } catch (e) {
            error.value = e instanceof Error ? e.message : 'Failed to fetch encounter';
            return null;
        } finally {
            isLoading.value = false;
        }
    }

    function setCurrentEncounter(id: string | null) {
        currentEncounterId.value = id;
    }

    function clearCurrentEncounter() {
        currentEncounterId.value = null;
    }

    return {
        encounters,
        currentEncounterId,
        isLoading,
        error,
        currentEncounter,
        fetchEncounters,
        startEncounter,
        fetchEncounter,
        setCurrentEncounter,
        clearCurrentEncounter,
    };
});