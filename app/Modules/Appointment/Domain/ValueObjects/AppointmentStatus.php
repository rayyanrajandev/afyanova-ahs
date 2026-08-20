<?php

namespace App\Modules\Appointment\Domain\ValueObjects;

enum AppointmentStatus: string
{
    case SCHEDULED = 'scheduled';

    /**
     * Arrived, but not yet cleared into the clinical queue.
     *
     * Deliberately not a copy of "the charge is unpaid" — that lives on the
     * charge, and duplicating it here would give two answers to one question.
     * This records a different fact: the patient is physically present and
     * standing at the cashier. A SCHEDULED visit with an unpaid charge is
     * someone who has not arrived yet; the two are not interchangeable, and
     * reception's queue only shows patients from WAITING_TRIAGE onwards, so
     * without this state an arrived patient would be invisible until they paid.
     */
    case AWAITING_PAYMENT = 'awaiting_payment';

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
     * The patient is physically here and their visit is not yet resolved.
     *
     * AWAITING_PAYMENT belongs in this set and was missing from both callers
     * until 2026-08-19. The omission dated from the prepaid model adding the
     * status without revisiting the queries that enumerate "active", and one
     * list produced three failures:
     *
     *  - The Reception patient profile crashed on a checked-in patient. The
     *    summary reported no active appointment while the encounter was open,
     *    and the template assumed those two could never disagree.
     *  - A patient standing at the cashier could be registered and checked in
     *    a *second* time, because the duplicate-arrival guard could not see
     *    them — a duplicate visit and a duplicate consultation charge.
     *  - Reception could not proactively disable Check-In for such a patient,
     *    which is the reason the summary exposes this field at all.
     *
     * Stated once, here, so the next status added to this enum has one place
     * to be considered rather than several to be forgotten in.
     *
     * SCHEDULED is deliberately absent: a booking is not an arrival. The
     * same-day double-booking check adds it explicitly for its own question.
     *
     * @return array<int, string>
     */
    public static function arrivedAndUnresolved(): array
    {
        return [
            self::AWAITING_PAYMENT->value,
            self::WAITING_TRIAGE->value,
            self::WAITING_PROVIDER->value,
            self::IN_CONSULTATION->value,
        ];
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
                // Check-in routes to one or the other depending on whether the
                // visit's charge is authorized (Reception\CheckInUseCase).
                self::AWAITING_PAYMENT->value,
                self::WAITING_TRIAGE->value,
                self::CANCELLED->value,
                self::NO_SHOW->value,
                self::COMPLETED->value,
            ],
            // Promoted when the charge clears — by payment, waiver or
            // emergency override. NO_SHOW stays unreachable from here: the
            // patient demonstrably arrived.
            self::AWAITING_PAYMENT->value => [
                self::WAITING_TRIAGE->value,
                self::CANCELLED->value,
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
