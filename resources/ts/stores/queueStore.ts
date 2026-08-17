/**
 * Queue Store (Volume 1.4 §3.1, Volume 2.1 §12.1, Volume 2.2 §13.1, Volume 2.3 §12.1)
 * ===================================================================================
 * Manages the reception queue (Volume 2.1), the clinician queue (Volume 2.2),
 * and the nursing task list (Volume 2.3 §9).
 *
 * API endpoints:
 *   GET   /reception/queue                    — get reception queue (Volume 2.1 §12.2)
 *   POST  /reception/queue/{id}/cancel        — cancel a queue item (Volume 2.1 §10.3)
 *   GET  /nursing/tasks                      — get nursing task list (Volume 2.3 §12.2)
 *
 * `POST /nursing/tasks/{id}/complete` (and this store's own `completeTask`)
 * removed 2026-08-13 (Volume 3.8 Phase 5) — the backend controller never had
 * a `complete()` method (real, confirmed 500 on every call), and the
 * frontend never called it either. Completing a task with no downstream
 * orders is now handled by `nursingAssessmentStore.completeAssessment()`
 * with an empty `items` array, not a separate action.
 *
 * Reception queue action scope (Volume 3.7 audit, 2026-08-10): the reception
 * queue view fetches stage=waiting_triage, and AppointmentStatus's own forward-
 * transition graph only allows WAITING_TRIAGE → {CANCELLED, COMPLETED} — so
 * "Cancel" is the only status-changing row action valid on *this* view.
 * "Check-in" (SCHEDULED → WAITING_TRIAGE) and "No-show" (SCHEDULED-only) both
 * apply to appointments that haven't arrived yet, which requires a "today's
 * scheduled appointments" view this workspace doesn't have (Volume 2.1 §9,
 * Appointment Scheduling — unbuilt). Wiring them here would 422 on every click.
 *
 * `cancelQueueItem` calls the reception-scoped `/reception/queue/{id}/cancel`
 * route, not the generic `/appointments/{id}/status` endpoint — the backend
 * route reuses the same UpdateAppointmentStatusUseCase internally (no logic
 * duplication), but the reception frontend's dependency surface stays inside
 * its own `/reception/*` contract rather than reaching into the shared/generic
 * API directly, matching every other endpoint this store and patientStore call.
 *
 * `fetchTasks()` (nursing) response shape fixed 2026-08-13: `GET nursing/tasks`
 * returns a paginated `{data, meta}` envelope of encounters, not a bare
 * `QueueTask[]` — was being cast straight through with no transform, so the
 * Tasks tab likely rendered broken/empty since it was first wired. See
 * `toNursingTask()` below, mirrors `toTask()`'s existing transform pattern.
 */

import { defineStore } from 'pinia';
import { ref } from 'vue';

/**
 * The patient's visit context, surfaced by `NurseQueueController::index`
 * (2026-08-14) so the Nursing UI can show where a patient is in their
 * journey (e.g. "Walk-in OPD · In Triage") instead of a bare opened
 * encounter. `stage` mirrors PatientFlow's journey derivation.
 */
export interface VisitContext {
    appointmentId?: string | null;
    appointmentStatus: string | null;
    stage: string | null;
    /**
     * Server-resolved flow step (PatientFlowStep) — authoritative for the badge.
     * `appointmentStatus`/`stage` cannot express a nursing pickup, so a header
     * reading those alone shows the queue the patient sits in rather than who
     * is actually with them.
     */
    visitStage?: string | null;
    arrivalMode: 'scheduled_checkin' | 'walk_in' | 'emergency' | null;
    visitCategory: string | null;
    encounterType: string | null;
    isAdmitted: boolean;
}

/**
 * Reception-to-nursing administrative readiness context (2026-08-14).
 * Surfaced by NurseQueueController so nurses can see insurance verification status,
 * coverage type, and check-in verification notes at a glance.
 */
export interface ReadinessContext {
    coverageType: 'insurance' | 'self_pay' | string | null;
    insuranceVerified: boolean | null;
    insuranceProvider: string | null;
    verificationNotes: string | null;
}

export interface QueueTask {
    id: string;
    description: string;
    patientId: string;
    patientName: string;
    dueTime: string;
    waitMinutes: number;
    priority: 'critical' | 'urgent' | 'normal';
    status: 'pending' | 'in_progress' | 'complete';
    stage?: string | null;
    source?: 'clinician_order' | 'scheduled' | 'nurse_created' | 'system';
    /**
     * Reception-only (Volume 2.1 §10.1/§10.2): how the patient arrived —
     * drives the queue's real tiering (emergency > scheduled > walk-in,
     * `GetReceptionQueueUseCase::ARRIVAL_MODE_TIERS`). `null`/absent for the
     * nursing task list, which has no arrival concept.
     */
    arrivalMode?: 'scheduled_checkin' | 'walk_in' | 'emergency' | 'returned' | null;
    /** Nursing-only (2026-08-14): the patient's visit journey context. */
    visit?: VisitContext | null;
    /** Nursing-only (2026-08-14): reception administrative readiness context. */
    readiness?: ReadinessContext | null;
}

/**
 * The reception queue endpoint validates a required `stage` query param
 * (one of waiting_triage|waiting_provider|in_consultation) and answers
 * `{ data: [...] , meta: {...} }` where each row carries appointmentId,
 * patientName, department, waitMinutes, status, etc. We map that into the
 * QueueTask shape the workspaces consume.
 */
export type ReceptionQueueStage = 'waiting_triage' | 'waiting_provider' | 'in_consultation' | 'admitted';

export interface ReceptionQueueEntry {
    appointmentId: string;
    appointmentNumber: string | null;
    status: string | null;
    stage?: string | null;
    patientId: string | null;
    patientName: string | null;
    patientNumber: string | null;
    department: string | null;
    arrivalMode?: 'scheduled_checkin' | 'walk_in' | 'emergency' | null;
    waitMinutes: number | null;
}

/** Exported for unit testing (see `__tests__/queueStore.test.ts`) — not used outside this module otherwise. */
export function toTask(entry: ReceptionQueueEntry): QueueTask {
    const waitMinutes = entry.waitMinutes ?? 0;
    // Tier the reception "wait time" into a Queue priority so the UI's
    // critical/urgent/normal sorting keeps clinical waiting sensible.
    const priority: QueueTask['priority'] =
        waitMinutes >= 60 ? 'critical' : waitMinutes >= 30 ? 'urgent' : 'normal';

    return {
        id: entry.appointmentId,
        description: entry.department ?? '',
        patientId: entry.patientId ?? '',
        patientName: entry.patientName ?? 'Unknown',
        arrivalMode: entry.arrivalMode ?? null,
        dueTime: waitMinutes < 60 ? `${waitMinutes} min` : `${Math.floor(waitMinutes / 60)}h ${waitMinutes % 60}m`,
        waitMinutes,
        priority,
        status: entry.status === 'in_consultation' ? 'in_progress' : 'pending',
        stage: entry.stage ?? entry.status ?? null,
        source: 'scheduled',
    };
}

/**
 * `NurseQueueController::index`'s response shape (`GET nursing/tasks`) —
 * open encounters awaiting nurse assessment, paginated as `{data, meta}`,
 * each row an encounter + its patient, not a pre-shaped task. Confirmed by
 * reading the controller directly (2026-08-13) after finding `fetchTasks()`
 * below was casting this straight to `QueueTask[]` with no transform at
 * all — the Tasks tab has likely been rendering broken/empty since it was
 * first wired, not because the endpoint doesn't work.
 */
export interface NursingTaskEntry {
    id: string;
    encounterNumber: string | null;
    patientId: string | null;
    appointmentId: string | null;
    status: string | null;
    type: string | null;
    openedAt: string | null;
    visit: VisitContext | null;
    readiness?: ReadinessContext | null;
    patient: {
        id: string;
        patientNumber: string | null;
        firstName: string | null;
        middleName: string | null;
        lastName: string | null;
        age: number | null;
    } | null;
}

/**
 * Exported for unit testing (see `__tests__/queueStore.test.ts`) — not used
 * outside this module otherwise, mirrors `toTask`'s own precedent. Unlike
 * reception's queue, the backend doesn't precompute a wait time, so it's
 * derived here from `openedAt` for display only.
 *
 * Priority is NOT wait-time-tiered the way `toTask` does it (2026-08-13,
 * reported directly by the user — every task was rendering "Critical").
 * Reception's minutes-scale thresholds (30/60 min) don't translate to a
 * nurse-assessment queue where encounters can legitimately stay open for
 * hours; against real seed data every single task cleared 60 minutes,
 * making the signal meaningless (everything red, nothing distinguishable).
 * Priority is derived instead from `EncounterType` (`app/Modules/Encounter/
 * Domain/ValueObjects/EncounterType.php`) — the actual clinical urgency
 * signal already present in the data: emergency arrivals are critical
 * regardless of wait time (that's why they're classified emergency);
 * inpatient encounters awaiting assessment are urgent; routine outpatient
 * walk-ins are normal.
 */
export function toNursingTask(entry: NursingTaskEntry): QueueTask {
    const openedAt = entry.openedAt ? new Date(entry.openedAt).getTime() : null;
    const waitMinutes = openedAt !== null ? Math.max(0, Math.floor((Date.now() - openedAt) / 60000)) : 0;
    const priority: QueueTask['priority'] =
        entry.type === 'emergency' ? 'critical' : entry.type === 'inpatient' ? 'urgent' : 'normal';
    const patientName = entry.patient
        ? [entry.patient.firstName, entry.patient.middleName, entry.patient.lastName]
              .filter((part): part is string => Boolean(part && part.trim()))
              .join(' ')
        : '';

    return {
        id: entry.id,
        description: entry.type ?? '',
        patientId: entry.patientId ?? entry.patient?.id ?? '',
        patientName: patientName || 'Unknown',
        dueTime: waitMinutes < 60 ? `${waitMinutes} min` : `${Math.floor(waitMinutes / 60)}h ${waitMinutes % 60}m`,
        waitMinutes,
        priority,
        status: 'pending',
        stage: entry.visit?.stage ?? entry.visit?.appointmentStatus ?? null,
        source: 'system',
        visit: entry.visit ? { ...entry.visit, ...(entry.appointmentId ? { appointmentId: entry.appointmentId } : {}) } : null,
        readiness: entry.readiness ?? null,
    };
}

export const useQueueStore = defineStore('queue', () => {
    // ---- State ----
    const tasks = ref<QueueTask[]>([]);
    const stageCounts = ref<Record<string, number>>({
        waiting_triage: 0,
        waiting_provider: 0,
        in_consultation: 0,
        admitted: 0,
        total: 0,
    });
    const isLoading = ref(false);
    const error = ref<string | null>(null);

    // ---- Actions ----

    /** GET /reception/queue/status-counts */
    async function fetchStageCounts(): Promise<Record<string, number>> {
        try {
            const res = await fetch('/api/v1/reception/queue/status-counts', {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
            if (res.ok) {
                const body = (await res.json()) as { data?: Record<string, number> };
                if (body.data) {
                    stageCounts.value = { ...stageCounts.value, ...body.data };
                }
            }
        } catch {
            // Non-blocking for offline / degraded network resilience
        }
        return stageCounts.value;
    }

    /** GET /reception/queue?stage=… (Volume 2.1 §12.2) */
    async function fetchReceptionQueue(stage: ReceptionQueueStage = 'waiting_triage'): Promise<QueueTask[]> {
        isLoading.value = true;
        error.value = null;
        try {
            const res = await fetch(`/api/v1/reception/queue?stage=${encodeURIComponent(stage)}`, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
            if (!res.ok) throw new Error('Failed to fetch reception queue');
            const body = (await res.json()) as { data?: ReceptionQueueEntry[] };
            tasks.value = (body.data ?? []).map(toTask);
            void fetchStageCounts();
            return tasks.value;
        } catch (e) {
            error.value = e instanceof Error ? e.message : 'Failed to fetch reception queue';
            return [];
        } finally {
            isLoading.value = false;
        }
    }

    /** GET /nursing/tasks */
    async function fetchTasks(): Promise<QueueTask[]> {
        isLoading.value = true;
        error.value = null;
        try {
            const res = await fetch('/api/v1/nursing/tasks', {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
            if (!res.ok) {
                if (res.status === 403 || res.status === 401) {
                    tasks.value = [];
                    return [];
                }
                throw new Error('Failed to fetch tasks');
            }
            const body = (await res.json()) as { data?: NursingTaskEntry[] };
            tasks.value = (body.data ?? []).map(toNursingTask);
            return tasks.value;
        } catch (e) {
            error.value = e instanceof Error ? e.message : 'Failed to fetch tasks';
            return [];
        } finally {
            isLoading.value = false;
        }
    }

    /**
     * POST /reception/queue/{id}/cancel (Volume 2.1 §10.3 "Cancel").
     * `reason` is required by the backend (audit trail). On success the task
     * leaves the queue — a cancelled appointment doesn't belong in the
     * waiting list.
     */
    async function cancelQueueItem(id: string, reason: string): Promise<boolean> {
        try {
            const res = await fetch(`/api/v1/reception/queue/${id}/cancel`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({ reason }),
            });
            if (!res.ok) {
                const body = await res.json().catch(() => null);
                throw new Error(body?.message ?? 'Failed to cancel appointment');
            }
            tasks.value = tasks.value.filter((t) => t.id !== id);
            void fetchStageCounts();
            return true;
        } catch (e) {
            error.value = e instanceof Error ? e.message : 'Failed to cancel appointment';
            return false;
        }
    }

    /**
     * POST /reception/queue/reorder (Volume 2.1 §10.3 "Reorder", Volume 3.7
     * T5.5). Tier is a hard floor enforced server-side
     * (ReorderReceptionQueueUseCase) — a submitted order that would move a
     * lower-priority tier ahead of a higher one comes back as a 422, which
     * this surfaces via `error` rather than silently doing nothing so the
     * caller knows to revert its optimistic local order.
     */
    async function reorderQueue(orderedAppointmentIds: string[]): Promise<boolean> {
        try {
            const res = await fetch('/api/v1/reception/queue/reorder', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({ appointmentIds: orderedAppointmentIds }),
            });
            if (!res.ok) {
                const body = await res.json().catch(() => null);
                throw new Error(body?.message ?? 'Failed to reorder the queue');
            }
            void fetchStageCounts();
            return true;
        } catch (e) {
            error.value = e instanceof Error ? e.message : 'Failed to reorder the queue';
            return false;
        }
    }

    async function returnToReception(appointmentId: string, reason?: string): Promise<boolean> {
        try {
            const res = await fetch(`/api/v1/nursing/return-to-reception/${encodeURIComponent(appointmentId)}`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({ reason }),
            });
            if (!res.ok) {
                const body = await res.json().catch(() => null);
                throw new Error(body?.message ?? 'Failed to return patient to reception');
            }
            void fetchStageCounts();
            return true;
        } catch (e) {
            error.value = e instanceof Error ? e.message : 'Failed to return patient to reception';
            return false;
        }
    }

    return {
        tasks,
        stageCounts,
        isLoading,
        error,
        fetchStageCounts,
        fetchReceptionQueue,
        fetchTasks,
        cancelQueueItem,
        reorderQueue,
        returnToReception,
    };
});