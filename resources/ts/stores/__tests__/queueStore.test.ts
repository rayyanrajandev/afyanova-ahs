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
import { toNursingTask, toTask, useQueueStore, type NursingTaskEntry, type ReceptionQueueEntry } from '../queueStore';

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

function nursingTaskEntry(overrides: Partial<NursingTaskEntry> = {}): NursingTaskEntry {
    return {
        id: 'enc-1',
        encounterNumber: 'ENC1',
        patientId: 'pat-1',
        appointmentId: null,
        status: 'opened',
        type: 'outpatient',
        openedAt: new Date().toISOString(),
        visit: null,
        patient: {
            id: 'pat-1',
            patientNumber: 'MRN1',
            firstName: 'Zarina',
            middleName: null,
            lastName: 'Megji',
            age: 25,
        },
        ...overrides,
    };
}

describe('queueStore — toNursingTask mapping', () => {
    // Bug fix (2026-08-13): `fetchTasks()` used to cast the backend's raw
    // `{data, meta}` envelope straight to `QueueTask[]` with no transform —
    // this locks in the real shape `NurseQueueController::index` returns.
    it('builds a patient display name from the nested patient object', () => {
        const task = toNursingTask(nursingTaskEntry());
        expect(task.patientName).toBe('Zarina Megji');
        expect(task.patientId).toBe('pat-1');
        expect(task.id).toBe('enc-1');
    });

    it('falls back to "Unknown" when patient is null', () => {
        const task = toNursingTask(nursingTaskEntry({ patient: null, patientId: null }));
        expect(task.patientName).toBe('Unknown');
        expect(task.patientId).toBe('');
    });

    it('derives wait time from openedAt for display, independent of priority', () => {
        const openedAt = new Date(Date.now() - 65 * 60000).toISOString();
        const task = toNursingTask(nursingTaskEntry({ openedAt }));
        expect(task.waitMinutes).toBeGreaterThanOrEqual(65);
    });

    it('derives priority from encounter type, not wait time (bug fix 2026-08-13 — every task rendered "Critical" because reception\'s minutes-scale thresholds treated hours-old encounters as universally over threshold)', () => {
        expect(toNursingTask(nursingTaskEntry({ type: 'emergency', openedAt: new Date().toISOString() })).priority).toBe('critical');
        expect(toNursingTask(nursingTaskEntry({ type: 'inpatient', openedAt: new Date().toISOString() })).priority).toBe('urgent');
        expect(toNursingTask(nursingTaskEntry({ type: 'outpatient', openedAt: new Date(Date.now() - 500 * 60000).toISOString() })).priority).toBe('normal');
    });

    it('always starts a fetched task as "pending", ignoring the encounter\'s own status string', () => {
        const task = toNursingTask(nursingTaskEntry({ status: 'opened' }));
        expect(task.status).toBe('pending');
    });

    it('carries the visit journey context through to the task (2026-08-14)', () => {
        const visit = {
            appointmentStatus: 'waiting_triage',
            stage: 'waiting_triage',
            arrivalMode: 'walk_in' as const,
            visitCategory: 'opd_walk_in',
            encounterType: 'outpatient',
            isAdmitted: false,
        };
        const task = toNursingTask(nursingTaskEntry({ visit }));
        expect(task.visit).toEqual(visit);
    });

    it('maps a missing visit context to null rather than leaving it undefined', () => {
        const task = toNursingTask(nursingTaskEntry({ visit: null }));
        expect(task.visit).toBeNull();
    });
});

describe('queueStore — fetchTasks', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
    });

    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('unwraps the paginated {data, meta} envelope instead of storing it as-is', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue({
                ok: true,
                json: async () => ({
                    data: [nursingTaskEntry({ id: 'enc-1' }), nursingTaskEntry({ id: 'enc-2', patientId: 'pat-2' })],
                    meta: { currentPage: 1, perPage: 20, total: 2, lastPage: 1 },
                }),
            }),
        );

        const store = useQueueStore();
        const tasks = await store.fetchTasks();

        expect(tasks).toHaveLength(2);
        expect(tasks[0].id).toBe('enc-1');
        expect(store.tasks).toHaveLength(2);
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
