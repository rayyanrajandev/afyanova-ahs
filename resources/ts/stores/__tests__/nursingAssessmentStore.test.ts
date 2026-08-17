/**
 * nursingAssessmentStore — `completeAssessment()` (Volume 2.3 §6,
 * Volume 3.8 Phase 3).
 * =======================================================================
 * Covers the encounter-id-in-URL contract directly — this endpoint 500'd
 * on every real call before Phase 3's fix (missing `{encounterId}` URI
 * segment), so the request shape itself is worth locking in, not just the
 * store's own success/failure branching.
 */

import { createPinia, setActivePinia } from 'pinia';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { useNursingAssessmentStore, type AssessmentInput } from '../nursingAssessmentStore';

function input(overrides: Partial<AssessmentInput> = {}): AssessmentInput {
    return {
        clinicalNote: 'Patient stable, no acute distress.',
        items: [],
        ...overrides,
    };
}

describe('nursingAssessmentStore — completeAssessment', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
    });

    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('posts to the encounter-scoped URL and returns true on success', async () => {
        const fetchMock = vi.fn().mockResolvedValue({ ok: true, json: async () => ({}) });
        vi.stubGlobal('fetch', fetchMock);

        const store = useNursingAssessmentStore();
        const ok = await store.completeAssessment('enc-1', input());

        expect(ok).toBe(true);
        expect(store.error).toBeNull();
        expect(fetchMock).toHaveBeenCalledWith(
            '/api/v1/nursing/assessments/enc-1',
            expect.objectContaining({ method: 'POST', body: JSON.stringify(input()) }),
        );
    });

    it('URL-encodes an encounter id that contains reserved characters', async () => {
        const fetchMock = vi.fn().mockResolvedValue({ ok: true, json: async () => ({}) });
        vi.stubGlobal('fetch', fetchMock);

        const store = useNursingAssessmentStore();
        await store.completeAssessment('enc/1 with spaces', input());

        expect(fetchMock).toHaveBeenCalledWith(
            '/api/v1/nursing/assessments/enc%2F1%20with%20spaces',
            expect.anything(),
        );
    });

    it('allows a zero-order assessment (Phase 5 — completion with no downstream orders is valid)', async () => {
        const fetchMock = vi.fn().mockResolvedValue({ ok: true, json: async () => ({}) });
        vi.stubGlobal('fetch', fetchMock);

        const store = useNursingAssessmentStore();
        const ok = await store.completeAssessment('enc-1', input({ items: [] }));

        expect(ok).toBe(true);
    });

    it('returns false and sets error on a non-ok response', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue({ ok: false, json: async () => ({}) }));

        const store = useNursingAssessmentStore();
        const ok = await store.completeAssessment('enc-1', input());

        expect(ok).toBe(false);
        expect(store.error).toBe('Failed to complete assessment');
    });
});
