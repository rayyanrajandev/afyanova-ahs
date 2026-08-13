<?php

namespace App\Modules\Reception\Application\UseCases;

use App\Modules\Appointment\Domain\ValueObjects\AppointmentStatus;
use App\Modules\Appointment\Infrastructure\Models\AppointmentModel;

/**
 * Backs clinician/Queue.vue's sticky-header KPI cards — the same shape and
 * reasoning as GetTriageQueueStatusCountsUseCase, adapted for the
 * consultation stage instead of triage. See that use case's docblock for
 * why this isn't a reuse of ListAppointmentStatusCountsUseCase
 * (appointments/status-counts): different semantics scoped to "what's
 * happening in this queue right now" rather than the full appointments list.
 *
 * Four counts:
 * - waiting: waiting_provider, never yet seen a provider
 *   (consultation_started_at IS NULL). Live, not date-scoped.
 * - onHold: waiting_provider, but has already been seen once before
 *   (consultation_started_at IS NOT NULL) — sent back for labs/pharmacy,
 *   will return. This distinction only became reliable after fixing
 *   AppointmentController::updateProviderWorkflow(), which previously
 *   nulled consultation_started_at unconditionally on every exit from
 *   in_consultation. Live, not date-scoped.
 * - inProgress: in_consultation. Live, not date-scoped.
 * - completed: status = completed AND consultation_started_at IS NOT NULL,
 *   today only — scoped to visits that actually went through a consultation
 *   (not administrative closures of a still-scheduled visit, which is a
 *   front-desk action unrelated to this page's clinical workflow). Uses
 *   updated_at as the "when did this become completed" proxy, same pattern
 *   GetTriageQueueStatusCountsUseCase uses for cancelled-today (no
 *   completed_at column exists).
 */
class GetClinicianQueueStatusCountsUseCase
{
    public function execute(): array
    {
        $today = now()->startOfDay();

        $waiting = AppointmentModel::query()
            ->where('status', AppointmentStatus::WAITING_PROVIDER->value)
            ->whereNull('consultation_started_at')
            ->count();

        $onHold = AppointmentModel::query()
            ->where('status', AppointmentStatus::WAITING_PROVIDER->value)
            ->whereNotNull('consultation_started_at')
            ->count();

        $inProgress = AppointmentModel::query()
            ->where('status', AppointmentStatus::IN_CONSULTATION->value)
            ->count();

        $completed = AppointmentModel::query()
            ->where('status', AppointmentStatus::COMPLETED->value)
            ->whereNotNull('consultation_started_at')
            ->where('updated_at', '>=', $today)
            ->count();

        return [
            'waiting' => $waiting,
            'onHold' => $onHold,
            'inProgress' => $inProgress,
            'completed' => $completed,
        ];
    }
}
