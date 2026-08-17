/**
 * vitalsStore — `recordVitals()` (Volume 2.3 §7, Volume 3.8 Phase 2).
 * =======================================================================
 * Was previously untested (Volume 3.8 Phase 8) despite being the store
 * behind the one clinical-action form that's been wired the longest —
 * covering the success/failure/malformed-response paths directly rather
 * than trusting the read-through that happened when it was first built.
 */

import { createPinia, setActivePinia } from 'pinia';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { useVitalsStore, type VitalSetInput } from '../vitalsStore';

function input(overrides: Partial<VitalSetInput> = {}): VitalSetInput {
    return {
        encounterId: 'enc-1',
        temperature: 37.1,
        pulse: 78,
        respiratoryRate: 16,
        bloodPressureSystolic: 120,
        bloodPressureDiastolic: 80,
        oxygenSaturation: 98,
        ...overrides,
    } as VitalSetInput;
}

describe('vitalsStore — recordVitals', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
    });

    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('posts the vitals payload and returns the recorded set on success', async () => {
        const recorded = { id: 'vit-1', encounterId: 'enc-1', temperature: 37.1, isFlagged: false };
        const fetchMock = vi.fn().mockResolvedValue({
            ok: true,
            json: async () => ({ data: recorded }),
        });
        vi.stubGlobal('fetch', fetchMock);

        const store = useVitalsStore();
        const result = await store.recordVitals(input());

        expect(result).toEqual(recorded);
        expect(store.error).toBeNull();
        expect(store.isSaving).toBe(false);
        expect(fetchMock).toHaveBeenCalledWith(
            '/api/v1/nursing/vitals',
            expect.objectContaining({ method: 'POST' }),
        );
    });

    it('returns null and sets error on a non-ok response', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue({ ok: false, json: async () => ({}) }),
        );

        const store = useVitalsStore();
        const result = await store.recordVitals(input());

        expect(result).toBeNull();
        expect(store.error).toBe('Failed to record vitals');
        expect(store.isSaving).toBe(false);
    });

    it('returns null when the response is ok but carries no data envelope', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue({ ok: true, json: async () => ({}) }),
        );

        const store = useVitalsStore();
        const result = await store.recordVitals(input());

        expect(result).toBeNull();
        expect(store.error).not.toBeNull();
    });

    it('surfaces a thrown network error rather than leaving isSaving stuck true', async () => {
        vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new TypeError('Network request failed')));

        const store = useVitalsStore();
        const result = await store.recordVitals(input());

        expect(result).toBeNull();
        expect(store.error).toBe('Network request failed');
        expect(store.isSaving).toBe(false);
    });
});
