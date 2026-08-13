<?php

namespace App\Modules\Appointment\Domain\ValueObjects;

enum AppointmentStatus: string
{
    case SCHEDULED = 'scheduled';
    case WAITING_TRIAGE = 'waiting_triage';
    case WAITING_PROVIDER = 'waiting_provider';
    case IN_CONSULTATION = 'in_consultation';
    case COMPLETED = 'completed';
    case CANCELLED = 'cancelled';
    case NO_SHOW = 'no_show';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }

    /**
     * Phase 2 of reports/patient-arrival-checkin-modernization-plan.md, closing the
     * gap named in reports/patient-arrival-checkin-audit.md §3: the generic
     * PATCH appointments/{id}/status endpoint previously accepted any enum value
     * from any other with no transition guard at all, materially weaker than
     * ServiceRequestStatus::canTransitionTo() elsewhere in this codebase.
     *
     * This graph is the union of every transition real call sites (and the
     * existing test suite, run end-to-end against this guard before it shipped)
     * already rely on — not an invented ideal state machine:
     *  - SCHEDULED -> WAITING_TRIAGE: check-in (audit §3-§4).
     *  - WAITING_PROVIDER -> IN_CONSULTATION: AppointmentController::startConsultation().
     *  - IN_CONSULTATION/WAITING_PROVIDER -> {WAITING_TRIAGE, WAITING_PROVIDER}:
     *    AppointmentController::updateProviderWorkflow(), which already enforced this
     *    exact sub-graph locally before this change — centralized here, not altered.
     *  - CANCELLED/COMPLETED are reachable from every non-terminal status: both are
     *    administrative visit-closure actions available to front desk/reception at
     *    any point in the visit (confirmed by AppointmentApiTest's audit-log test,
     *    which completes a still-SCHEDULED appointment directly), not steps confined
     *    to the clinical sequence the other statuses represent.
     *  - NO_SHOW is intentionally SCHEDULED-only: it means the patient never arrived,
     *    which is meaningless once any check-in/triage/consultation step has occurred.
     *  - WAITING_TRIAGE -> WAITING_PROVIDER is deliberately NOT included here: that
     *    transition only ever happens through RecordAppointmentTriageUseCase, which
     *    writes it directly (bypassing this use case) together with the department/
     *    clinician routing triage handoff requires. Allowing it here would let the
     *    generic status endpoint skip that routing requirement entirely.
     *
     * @return array<string, string[]>
     */
    public static function allowedForwardTransitions(): array
    {
        return [
            self::SCHEDULED->value => [
                self::WAITING_TRIAGE->value,
                self::CANCELLED->value,
                self::NO_SHOW->value,
                self::COMPLETED->value,
            ],
            self::WAITING_TRIAGE->value => [self::CANCELLED->value, self::COMPLETED->value],
            self::WAITING_PROVIDER->value => [
                self::IN_CONSULTATION->value,
                self::WAITING_TRIAGE->value,
                self::CANCELLED->value,
                self::COMPLETED->value,
            ],
            self::IN_CONSULTATION->value => [
                self::WAITING_PROVIDER->value,
                self::WAITING_TRIAGE->value,
                self::COMPLETED->value,
                self::CANCELLED->value,
            ],
            self::COMPLETED->value => [],
            self::CANCELLED->value => [],
            self::NO_SHOW->value => [],
        ];
    }

    /**
     * Same-status is always allowed and is not a "transition" — e.g.
     * AppointmentController::startConsultation()'s consultation-owner takeover
     * re-issues status: in_consultation while already in_consultation.
     */
    public function canTransitionTo(string $newStatus): bool
    {
        if ($this->value === $newStatus) {
            return true;
        }

        return in_array($newStatus, self::allowedForwardTransitions()[$this->value] ?? [], true);
    }
}
