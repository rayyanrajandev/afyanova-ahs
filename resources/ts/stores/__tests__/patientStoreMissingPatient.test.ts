/**
 * patientStore — a patient that no longer exists is a state, not an error.
 * =======================================================================
 * Regression coverage for the emptied-database bug: with every patient
 * deleted, the workspace patient list rendered
 * "Failed to fetch patient 01a00671-…" instead of its empty placeholder.
 *
 * Cause: one shared `error` ref served both the list fetch and the
 * single-patient lookup, and the list panels bind `:error` to it. A stale
 * selection (URL param, localStorage recent) triggered a detail fetch whose
 * 404 wrote into the list's error slot — and DataTable renders `error` in
 * preference to the empty state, so the list reported a failure it never had.
 */

import { createPinia, setActivePinia } from 'pinia';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { usePatientStore } from '../patientStore';

function jsonResponse(status: number, body: unknown): Response {
    return {
        ok: status >= 200 && status < 300,
        status,
        json: async () => body,
    } as Response;
}

function patientRow(id: string) {
    return {
        id,
        patient_number: 'MRN-1',
        first_name: 'Neema',
        last_name: 'Mushi',
        gender: 'female',
        date_of_birth: '1990-01-01',
    };
}

describe('patientStore — missing patient handling', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
    });

    afterEach(() => {
        vi.unstubAllGlobals();
        vi.restoreAllMocks();
    });

    it('does not write a 404 into the list error slot', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => jsonResponse(404, { message: 'Not found.' })));

        const store = usePatientStore();
        const result = await store.fetchPatient('01a00671-dd37-704d-af62-5620e51d8767');

        expect(result).toBeNull();
        // The list never failed, so its error slot must stay clean — this is
        // what lets the empty-state placeholder render.
        expect(store.error).toBeNull();
        // A missing patient is not a fault to report anywhere.
        expect(store.detailError).toBeNull();
    });

    it('evicts a deleted patient from the cache and clears it as current', async () => {
        const id = '01a00671-dd37-704d-af62-5620e51d8767';
        const fetchMock = vi
            .fn()
            .mockResolvedValueOnce(jsonResponse(200, { data: patientRow(id) }))
            .mockResolvedValueOnce(jsonResponse(404, { message: 'Not found.' }));
        vi.stubGlobal('fetch', fetchMock);

        const store = usePatientStore();

        await store.fetchPatient(id);
        store.setCurrentPatient(id);
        expect(store.patients.get(id)).toBeTruthy();
        expect(store.currentPatient).toBeTruthy();

        // Same patient, now deleted in the DB.
        await store.fetchPatient(id);

        expect(store.patients.get(id)).toBeUndefined();
        expect(store.currentPatient).toBeNull();
    });

    it('still reports a genuine failure, kept out of the list error slot', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => jsonResponse(500, { message: 'Server error.' })));

        const store = usePatientStore();
        const result = await store.fetchPatient('01a00671-dd37-704d-af62-5620e51d8767');

        expect(result).toBeNull();
        // A 500 is a real fault and must not be swallowed...
        expect(store.detailError).toBeTruthy();
        // ...but it is still not the patient list's failure.
        expect(store.error).toBeNull();
    });

    it('leaves the list error slot clean when every patient is deleted', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => jsonResponse(200, { data: [], meta: { total: 0 } })));

        const store = usePatientStore();
        const list = await store.fetchPatients();

        expect(list).toEqual([]);
        expect(store.totalPatientCount).toBe(0);
        expect(store.error).toBeNull();
    });
});
