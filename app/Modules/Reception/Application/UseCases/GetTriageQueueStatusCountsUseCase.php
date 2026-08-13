<?php

namespace App\Modules\Reception\Application\UseCases;

use App\Modules\Appointment\Domain\ValueObjects\AppointmentStatus;
use App\Modules\Appointment\Infrastructure\Models\AppointmentModel;

/**
 * Backs triage/Queue.vue's sticky-header KPI cards. Deliberately not a
 * reuse of ListAppointmentStatusCountsUseCase (appointments/status-counts):
 * that endpoint buckets by literal AppointmentStatus values scoped by
 * scheduled_at, which doesn't fit this queue's "what's happening in triage
 * right now" semantics — no_show is unreachable from waiting_triage
 * (AppointmentStatus::allowedForwardTransitions()), and "completed" as a
 * whole-visit closure state doesn't mean "triage was finished". See
 * reports/appointments-scheduling-workspace-modernization-plan.md's
 * "triage status count cards" update for the full reasoning.
 *
 * Four counts, two different semantics:
 * - waiting / inProgress: live state of the current waiting_triage
 *   population, split on the triage-claim columns
 *   (triage_owner_user_id/triage_owner_assigned_at, added alongside
 *   ClaimAppointmentTriageUseCase) — not date-scoped, since "who's in the
 *   queue right now" has no notion of "today".
 * - completed / cancelled: today's totals, not the live queue's own
 *   population (an appointment leaves waiting_triage the moment either of
 *   these happens, so there is nothing to count "of the queue" — this is a
 *   same-shift operational summary instead). completed uses triaged_at
 *   (triage actually finished), not AppointmentStatus::COMPLETED (a
 *   separate, later whole-visit closure state). cancelled is scoped to
 *   checked_in_at IS NOT NULL so it only counts cancellations that happened
 *   after the visit had already reached the triage queue, not cancellations
 *   of a still-scheduled, never-checked-in visit.
 */
class GetTriageQueueStatusCountsUseCase
{
    public function execute(): array
    {
        $today = now()->startOfDay();

        $waiting = AppointmentModel::query()
            ->where('status', AppointmentStatus::WAITING_TRIAGE->value)
            ->whereNull('triage_owner_user_id')
            ->count();

        $inProgress = AppointmentModel::query()
            ->where('status', AppointmentStatus::WAITING_TRIAGE->value)
            ->whereNotNull('triage_owner_user_id')
            ->count();

        $completed = AppointmentModel::query()
            ->whereNotNull('triaged_at')
            ->where('triaged_at', '>=', $today)
            ->count();

        $cancelled = AppointmentModel::query()
            ->where('status', AppointmentStatus::CANCELLED->value)
            ->whereNotNull('checked_in_at')
            ->where('updated_at', '>=', $today)
            ->count();

        return [
            'waiting' => $waiting,
            'inProgress' => $inProgress,
            'completed' => $completed,
            'cancelled' => $cancelled,
        ];
    }
}
