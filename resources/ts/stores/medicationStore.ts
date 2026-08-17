/**
 * Medication Store (Volume 1.4 §3.1, Volume 2.2 §13.1, Volume 2.3 §12.1)
 * ======================================================================
 * Manages the drug catalog, prescriptions, and the MAR
 * (Medication Administration Record) for the Nursing workspace.
 *
 * API endpoints (Volume 2.3 §12.2):
 *   GET  /nursing/mar                    — get MAR for patient
 *
 * Fixed 2026-08-13 (Volume 3.8 Phase 6): `fetchMar` was hitting `/nursing/mar`
 * with no `/api/v1` prefix (would 404 — no route matches), filtering by a
 * `patient_id` query param the backend doesn't read (it expects `patientId`,
 * confirmed in `ListPharmacyOrdersUseCase`), and casting the response
 * straight to `MarMedication[]` when it's actually a paginated `{data, meta}`
 * envelope of full pharmacy-order records (`PharmacyOrderResponseTransformer`).
 * On top of that, `loadMar()` in `nursing/Index.vue` was never actually
 * called by anything — the MAR panel opened but never fetched. All fixed
 * together since they compound into the same symptom (an always-empty
 * panel).
 *
 * `administerMedication` removed the same day: `POST /nursing/mar/{id}/
 * administer` requires a permission (`pharmacy.orders.administer`) granted
 * to no role anywhere in the system, and its controller method doesn't
 * exist at all (confirmed live: 403 then, if bypassed, a 500 "undefined
 * method"). There is also no administration-status concept anywhere in the
 * Pharmacy domain — `PharmacyOrderStatus` only goes up to `dispensed`
 * (pharmacy → order fulfilled), nothing for "given to patient". Building
 * real MAR administration (permission, controller, use case, an actual
 * administration-record data model, a genuine 5-Rights confirmation UI) is
 * real feature work, deliberately deferred — not something to fake with a
 * hardcoded "always succeeds" call. See Volume 3.8 §6 for the full record.
 */

import { defineStore } from 'pinia';
import { ref } from 'vue';

/** Real `PharmacyOrderStatus` values (`app/Modules/Pharmacy/Domain/ValueObjects/PharmacyOrderStatus.php`) — not a fabricated administration-status enum. */
export type MarStatus = 'pending' | 'in_preparation' | 'partially_dispensed' | 'dispensed' | 'cancelled';

export interface MarMedication {
    id: string;
    patientId: string;
    name: string;
    dose: string;
    route: string;
    frequency: string;
    status: MarStatus;
}

interface PharmacyOrderApiRow {
    id: string;
    patientId: string | null;
    medicationName: string | null;
    doseQuantity: number | string | null;
    doseUnit: string | null;
    route: string | null;
    frequency: string | null;
    status: string | null;
}

/** Exported for direct unit coverage (Volume 3.8 Phase 8) — same pattern as queueStore's `toTask`/`toNursingTask`. */
export function toMarMedication(row: PharmacyOrderApiRow): MarMedication {
    const dose = [row.doseQuantity, row.doseUnit].filter((part) => part !== null && part !== '').join(' ');
    return {
        id: row.id,
        patientId: row.patientId ?? '',
        name: row.medicationName ?? '',
        dose,
        route: row.route ?? '',
        frequency: row.frequency ?? '',
        status: (row.status as MarStatus | null) ?? 'pending',
    };
}

export const useMedicationStore = defineStore('medication', () => {
    // ---- State ----
    const mar = ref<MarMedication[]>([]);
    const isLoading = ref(false);
    const error = ref<string | null>(null);

    // ---- Actions ----

    /** GET /nursing/mar?patientId=... */
    async function fetchMar(patientId: string): Promise<MarMedication[]> {
        isLoading.value = true;
        error.value = null;
        try {
            const res = await fetch(`/api/v1/nursing/mar?patientId=${encodeURIComponent(patientId)}`, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
            if (!res.ok) throw new Error('Failed to fetch MAR');
            const body = (await res.json()) as { data?: PharmacyOrderApiRow[] };
            mar.value = (body.data ?? []).map(toMarMedication);
            return mar.value;
        } catch (e) {
            error.value = e instanceof Error ? e.message : 'Failed to fetch MAR';
            return [];
        } finally {
            isLoading.value = false;
        }
    }

    return {
        mar,
        isLoading,
        error,
        fetchMar,
    };
});
