/**
 * queueStore — `toTask()` arrival-mode mapping (Volume 2.1 §10.1/§10.2,
 * Volume 3.7 T5.1) and `reorderQueue()` (Volume 2.1 §10.3, Volume 3.7 T5.5).
 * =======================================================================
 * `toTask()` coverage: `arrivalMode` now survives the backend-entry →
 * `QueueTask` mapping (it was previously declared on the wire type but
 * silently dropped), which is what lets `useQueueActions.ts`'s
 * `tierLabel()` show the real emergency/scheduled/walk-in tier instead of
 * the queue reading as a flat, unordered list.
 *
 * `reorderQueue()` coverage: the request/response contract only — the
 * actual tier-hard-floor rule is enforced and tested server-side
 * (`ReorderReceptionQueueUseCase`, `tests/Feature/Reception/
 * ReceptionQueueReorderApiTest.php`); duplicating that logic in a client
 * mock would test the mock, not the rule.
 */

import { createPinia, setActivePinia } from 'pinia';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { toTask, useQueueStore, type ReceptionQueueEntry } from '../queueStore';

function entry(overrides: Partial<ReceptionQueueEntry> = {}): ReceptionQueueEntry {
    return {
        appointmentId: 'apt-1',
        appointmentNumber: 'APT1',
        status: 'waiting_triage',
        patientId: 'pat-1',
        patientName: 'Test Patient',
        patientNumber: 'MRN1',
        department: 'Outpatient',
        arrivalMode: null,
        waitMinutes: 10,
        ...overrides,
    };
}

describe('queueStore — toTask arrivalMode mapping', () => {
    it('carries a known arrival mode through unchanged', () => {
        expect(toTask(entry({ arrivalMode: 'emergency' })).arrivalMode).toBe('emergency');
        expect(toTask(entry({ arrivalMode: 'scheduled_checkin' })).arrivalMode).toBe('scheduled_checkin');
        expect(toTask(entry({ arrivalMode: 'walk_in' })).arrivalMode).toBe('walk_in');
    });

    it('maps a missing/null arrival mode to null rather than dropping the field', () => {
        expect(toTask(entry({ arrivalMode: null })).arrivalMode).toBeNull();
        expect(toTask(entry({ arrivalMode: undefined })).arrivalMode).toBeNull();
    });

    it('still derives wait-based priority independently of arrival mode', () => {
        // priority is a different axis (Queue.vue's generic wait-urgency
        // scale) — an emergency arrival with a short wait is still 'normal'
        // priority; tiering is expressed via arrivalMode, not by inflating
        // priority to force it to the top.
        const task = toTask(entry({ arrivalMode: 'emergency', waitMinutes: 5 }));
        expect(task.priority).toBe('normal');
    });
});

describe('queueStore — reorderQueue', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
    });

    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('posts the ordered ids and returns true on success', async () => {
        const fetchMock = vi.fn().mockResolvedValue({
            ok: true,
            json: async () => ({ data: { reordered: 2 } }),
        });
        vi.stubGlobal('fetch', fetchMock);

        const store = useQueueStore();
        const ok = await store.reorderQueue(['apt-2', 'apt-1']);

        expect(ok).toBe(true);
        expect(fetchMock).toHaveBeenCalledWith(
            '/api/v1/reception/queue/reorder',
            expect.objectContaining({
                method: 'POST',
                body: JSON.stringify({ appointmentIds: ['apt-2', 'apt-1'] }),
            }),
        );
    });

    it('returns false and surfaces the server message on a tier-floor rejection', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue({
                ok: false,
                json: async () => ({ message: 'This order would move a patient ahead of a higher-priority arrival.' }),
            }),
        );

        const store = useQueueStore();
        const ok = await store.reorderQueue(['apt-walk-in', 'apt-emergency']);

        expect(ok).toBe(false);
        expect(store.error).toBe('This order would move a patient ahead of a higher-priority arrival.');
    });
});
