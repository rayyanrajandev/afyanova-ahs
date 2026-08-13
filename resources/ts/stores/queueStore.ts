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
 *   POST /nursing/tasks/{id}/complete        — complete task (Volume 2.3 §12.2)
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
 */

import { defineStore } from 'pinia';
import { ref } from 'vue';

export interface QueueTask {
    id: string;
    description: string;
    patientId: string;
    patientName: string;
    dueTime: string;
    waitMinutes: number;
    priority: 'critical' | 'urgent' | 'normal';
    status: 'pending' | 'in_progress' | 'complete';
    source?: 'clinician_order' | 'scheduled' | 'nurse_created' | 'system';
    /**
     * Reception-only (Volume 2.1 §10.1/§10.2): how the patient arrived —
     * drives the queue's real tiering (emergency > scheduled > walk-in,
     * `GetReceptionQueueUseCase::ARRIVAL_MODE_TIERS`). `null`/absent for the
     * nursing task list, which has no arrival concept.
     */
    arrivalMode?: 'scheduled_checkin' | 'walk_in' | 'emergency' | null;
}

/**
 * The reception queue endpoint validates a required `stage` query param
 * (one of waiting_triage|waiting_provider|in_consultation) and answers
 * `{ data: [...] , meta: {...} }` where each row carries appointmentId,
 * patientName, department, waitMinutes, status, etc. We map that into the
 * QueueTask shape the workspaces consume.
 */
export type ReceptionQueueStage = 'waiting_triage' | 'waiting_provider' | 'in_consultation';

export interface ReceptionQueueEntry {
    appointmentId: string;
    appointmentNumber: string | null;
    status: string | null;
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
        source: 'scheduled',
    };
}

export const useQueueStore = defineStore('queue', () => {
    // ---- State ----
    const tasks = ref<QueueTask[]>([]);
    const isLoading = ref(false);
    const error = ref<string | null>(null);

    // ---- Actions ----

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
            if (!res.ok) throw new Error('Failed to fetch tasks');
            tasks.value = (await res.json()) as QueueTask[];
            return tasks.value;
        } catch (e) {
            error.value = e instanceof Error ? e.message : 'Failed to fetch tasks';
            return [];
        } finally {
            isLoading.value = false;
        }
    }

    /** POST /nursing/tasks/{id}/complete */
    async function completeTask(id: string): Promise<boolean> {
        try {
            const res = await fetch(`/api/v1/nursing/tasks/${id}/complete`, {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
            if (!res.ok) throw new Error('Failed to complete task');
            const task = tasks.value.find((t) => t.id === id);
            if (task) task.status = 'complete';
            return true;
        } catch (e) {
            error.value = e instanceof Error ? e.message : 'Failed to complete task';
            return false;
        }
    }

    /** Mark a task as in-progress (local, before server confirmation) */
    function markInProgress(id: string) {
        const task = tasks.value.find((t) => t.id === id);
        if (task) task.status = 'in_progress';
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
            return true;
        } catch (e) {
            error.value = e instanceof Error ? e.message : 'Failed to reorder the queue';
            return false;
        }
    }

    return {
        tasks,
        isLoading,
        error,
        fetchReceptionQueue,
        fetchTasks,
        completeTask,
        markInProgress,
        cancelQueueItem,
        reorderQueue,
    };
});