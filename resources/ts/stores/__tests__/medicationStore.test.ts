/**
 * medicationStore — `toMarMedication()` mapping and `fetchMar()`
 * (Volume 2.3 §8, Volume 3.8 Phase 6).
 * =======================================================================
 * Locks in the 4-bug fix directly: the request URL carries the `/api/v1`
 * prefix and the real `patientId` query param name (not `patient_id`),
 * and the paginated `{data, meta}` envelope is unwrapped through
 * `toMarMedication()` rather than cast straight to `MarMedication[]` —
 * exactly the shape that was silently broken before this phase.
 */

import { createPinia, setActivePinia } from 'pinia';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { toMarMedication, useMedicationStore } from '../medicationStore';

function apiRow(overrides: Partial<Parameters<typeof toMarMedication>[0]> = {}) {
    return {
        id: 'ord-1',
        patientId: 'pat-1',
        medicationName: 'Paracetamol',
        doseQuantity: 500,
        doseUnit: 'mg',
        route: 'oral',
        frequency: 'q6h',
        status: 'dispensed',
        ...overrides,
    };
}

describe('medicationStore — toMarMedication', () => {
    it('joins dose quantity and unit into a single display string', () => {
        const med = toMarMedication(apiRow({ doseQuantity: 500, doseUnit: 'mg' }));
        expect(med.dose).toBe('500 mg');
    });

    it('omits a null dose quantity/unit rather than rendering "null mg"', () => {
        const med = toMarMedication(apiRow({ doseQuantity: null, doseUnit: 'mg' }));
        expect(med.dose).toBe('mg');
    });

    it('falls back to "pending" when status is null, never fabricating an administration state', () => {
        const med = toMarMedication(apiRow({ status: null }));
        expect(med.status).toBe('pending');
    });

    it('preserves the real dispensed status rather than always defaulting', () => {
        const med = toMarMedication(apiRow({ status: 'dispensed' }));
        expect(med.status).toBe('dispensed');
    });
});

describe('medicationStore — fetchMar', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
    });

    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('requests the /api/v1-prefixed URL with the real patientId query param name', async () => {
        const fetchMock = vi.fn().mockResolvedValue({ ok: true, json: async () => ({ data: [] }) });
        vi.stubGlobal('fetch', fetchMock);

        const store = useMedicationStore();
        await store.fetchMar('pat-1');

        expect(fetchMock).toHaveBeenCalledWith(
            '/api/v1/nursing/mar?patientId=pat-1',
            expect.objectContaining({ headers: expect.objectContaining({ 'X-Requested-With': 'XMLHttpRequest' }) }),
        );
    });

    it('unwraps the paginated {data, meta} envelope into a flat MarMedication[]', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue({
                ok: true,
                json: async () => ({
                    data: [apiRow({ id: 'ord-1' }), apiRow({ id: 'ord-2' })],
                    meta: { currentPage: 1, perPage: 20, total: 2, lastPage: 1 },
                }),
            }),
        );

        const store = useMedicationStore();
        const result = await store.fetchMar('pat-1');

        expect(result).toHaveLength(2);
        expect(result[0].id).toBe('ord-1');
        expect(store.mar).toHaveLength(2);
    });

    it('returns an empty array and sets error on a non-ok response, rather than throwing into the caller', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue({ ok: false, json: async () => ({}) }));

        const store = useMedicationStore();
        const result = await store.fetchMar('pat-1');

        expect(result).toEqual([]);
        expect(store.error).toBe('Failed to fetch MAR');
    });
});
