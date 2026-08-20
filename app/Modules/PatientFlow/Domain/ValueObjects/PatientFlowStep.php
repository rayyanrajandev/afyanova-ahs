<?php

namespace App\Modules\PatientFlow\Domain\ValueObjects;

use App\Modules\Appointment\Domain\ValueObjects\AppointmentStatus;

/**
 * The board's step vocabulary, promoted from string literals scattered across
 * GetActiveVisitJourneyUseCase and ResolveConsultationDiagnosticStepsUseCase
 * into one enum, so the append-only log and the read-time projection speak
 * the same language and cannot drift apart.
 *
 * Every case here already existed as a literal in one of those two use cases,
 * with four deliberate additions marked below. Nothing was renamed: the
 * frontend already switches on these exact strings.
 *
 * Why steps rather than AppointmentStatus — a status of `waiting_provider`
 * means either WAITING_CLINICIAN (never yet seen a doctor) or
 * WAITING_CLINICIAN_REVIEW (sent out for orders, coming back), and the
 * difference is only recoverable today by inspecting consultation_started_at.
 * That inference is precisely what the log exists to replace with a recorded
 * fact, so the log stores the resolved step, not the raw status.
 */
enum PatientFlowStep: string
{
    // --- Reception / cashier ------------------------------------------------
    /**
     * Arrived and standing at the cashier. New with the prepaid model: a visit
     * is no longer clinical the moment it arrives, because the service has to
     * be paid for before it is provided.
     */
    case AWAITING_PAYMENT = 'awaiting_payment';

    // --- Reception / triage -------------------------------------------------
    case WAITING_TRIAGE = 'waiting_triage';
    case IN_TRIAGE = 'in_triage';

    // --- Clinician ----------------------------------------------------------
    case WAITING_CLINICIAN = 'waiting_clinician';
    case WAITING_CLINICIAN_REVIEW = 'waiting_clinician_review';
    case WITH_CLINICIAN = 'with_clinician';

    // --- Diagnostics (derived from open orders) -----------------------------
    case WAITING_LAB = 'waiting_lab';
    case IN_LAB = 'in_lab';
    case WAITING_IMAGING = 'waiting_imaging';
    case IN_IMAGING = 'in_imaging';
    case WAITING_LAB_AND_IMAGING = 'waiting_lab_and_imaging';
    case IN_LAB_AND_IMAGING = 'in_lab_and_imaging';
    case WAITING_PHARMACY = 'waiting_pharmacy';

    // --- Direct-service walk-ins -------------------------------------------
    case WAITING_DIRECT_SERVICE = 'waiting_direct_service';
    case IN_DIRECT_SERVICE = 'in_direct_service';

    /**
     * New — nursing had no "picked up" state at all, which is why nursing
     * steps were invisible on the board. Triage has had this since Phase 2
     * (triage_owner_user_id); this is its nursing equivalent.
     */
    case WITH_NURSE = 'with_nurse';

    // --- Terminal -----------------------------------------------------------
    case ADMITTED = 'admitted';
    case RETURNED_TO_RECEPTION = 'returned_to_reception';
    case COMPLETED = 'completed';
    case CANCELLED = 'cancelled';
    case NO_SHOW = 'no_show';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $step): string => $step->value, self::cases());
    }

    /**
     * Staff-facing label for the activity log. Deliberately phrased as "what
     * happened," not "what state the row is in" — the log is read by nurses and
     * doctors mid-shift, not by engineers.
     */
    public function label(): string
    {
        return match ($this) {
            // Added 2026-08-19. The case was declared and mapped in
            // fromAppointmentStatus() but never given a label, and PHP `match`
            // throws rather than returning null — so releasing a nurse's claim
            // on an unpaid visit, or rendering its flow timeline, died with an
            // UnhandledMatchError instead of showing a phrase.
            self::AWAITING_PAYMENT => 'Awaiting payment',
            self::WAITING_TRIAGE => 'Waiting for triage',
            self::IN_TRIAGE => 'In triage',
            self::WAITING_CLINICIAN => 'Waiting for doctor',
            self::WAITING_CLINICIAN_REVIEW => 'Waiting for doctor review',
            self::WITH_CLINICIAN => 'With doctor',
            self::WAITING_LAB => 'Waiting for lab',
            self::IN_LAB => 'In lab',
            self::WAITING_IMAGING => 'Waiting for imaging',
            self::IN_IMAGING => 'In imaging',
            self::WAITING_LAB_AND_IMAGING => 'Waiting for lab and imaging',
            self::IN_LAB_AND_IMAGING => 'In lab and imaging',
            self::WAITING_PHARMACY => 'Waiting for pharmacy',
            self::WAITING_DIRECT_SERVICE => 'Waiting for service',
            self::IN_DIRECT_SERVICE => 'In service',
            self::WITH_NURSE => 'With nurse',
            self::ADMITTED => 'Admitted to ward',
            self::RETURNED_TO_RECEPTION => 'Returned to reception',
            self::COMPLETED => 'Visit completed',
            self::CANCELLED => 'Visit cancelled',
            self::NO_SHOW => 'Did not attend',
        };
    }

    /**
     * True when the patient is actively being worked on by a named member of
     * staff, rather than waiting in a queue. This is the property the ticket's
     * acceptance criterion turns on — "no patient can be actively with a doctor
     * or nurse while still showing an earlier waiting status" — so it is stated
     * here once instead of being re-derived per screen.
     */
    public function isActiveContact(): bool
    {
        return match ($this) {
            self::IN_TRIAGE, self::WITH_CLINICIAN, self::WITH_NURSE,
            self::IN_LAB, self::IN_IMAGING, self::IN_LAB_AND_IMAGING,
            self::IN_DIRECT_SERVICE => true,
            default => false,
        };
    }

    public function isTerminal(): bool
    {
        return match ($this) {
            self::COMPLETED, self::CANCELLED, self::NO_SHOW, self::ADMITTED => true,
            default => false,
        };
    }

    /**
     * The single status -> step mapping for the whole system.
     *
     * Before this was promoted, the same waiting_triage/waiting_provider
     * branching existed in three near-identical copies —
     * ReceptionQueueEntryResponseTransformer, NurseQueueController::
     * deriveVisitStage() and GetActiveVisitJourneyUseCase::
     * deriveAppointmentStep() — plus a fourth, differently-behaved rule in the
     * clinician queue's frontend. Every one of them now calls this.
     *
     * Ownership arguments, all read from real transactional columns on
     * `appointments`, never from the best-effort flow log (see
     * 2026_08_16_000003's docblock for why current state must not depend on a
     * log that is allowed to silently miss writes):
     *
     *  - $hasNursingContact  (nursing_contact_user_id)   -> WITH_NURSE
     *  - $hasTriageOwner     (triage_owner_user_id)      -> IN_TRIAGE
     *  - $hasConsultationStarted (consultation_started_at) -> distinguishes
     *    WAITING_CLINICIAN_REVIEW from WAITING_CLINICIAN
     *
     * Precedence: nursing contact wins over the queue the patient is waiting
     * in, but never over being with a doctor. A nurse taking observations from
     * someone who is waiting for a clinician should show as WITH_NURSE — that
     * is the whole point of the ticket's "no patient can be actively with a
     * doctor or nurse while still showing an earlier waiting status". But a
     * patient physically in a consultation room is with the doctor even if a
     * nurse is also present, so IN_CONSULTATION is checked first and a stale
     * nursing claim can never mask it.
     */
    public static function fromAppointmentStatus(
        string $status,
        bool $hasTriageOwner = false,
        bool $hasConsultationStarted = false,
        bool $hasNursingContact = false,
    ): ?self {
        $appointmentStatus = AppointmentStatus::tryFrom(strtolower(trim($status)));

        // Terminal and in-consultation states are never overridden by a
        // nursing claim — see the precedence note above.
        $resolved = match ($appointmentStatus) {
            // Outranks a nursing claim for the same reason the terminal states
            // do: nothing clinical may start until the charge clears.
            AppointmentStatus::AWAITING_PAYMENT => self::AWAITING_PAYMENT,
            AppointmentStatus::IN_CONSULTATION => self::WITH_CLINICIAN,
            AppointmentStatus::COMPLETED => self::COMPLETED,
            AppointmentStatus::CANCELLED => self::CANCELLED,
            AppointmentStatus::NO_SHOW => self::NO_SHOW,
            default => null,
        };

        if ($resolved !== null) {
            return $resolved;
        }

        if ($hasNursingContact) {
            return self::WITH_NURSE;
        }

        return match ($appointmentStatus) {
            AppointmentStatus::WAITING_TRIAGE => $hasTriageOwner ? self::IN_TRIAGE : self::WAITING_TRIAGE,
            AppointmentStatus::WAITING_PROVIDER => $hasConsultationStarted
                ? self::WAITING_CLINICIAN_REVIEW
                : self::WAITING_CLINICIAN,
            // SCHEDULED is not a flow step — the patient has not arrived, so
            // there is nothing to place on the board yet.
            default => null,
        };
    }

    /**
     * Convenience wrapper for the common case: an appointment row (model or
     * array) resolved to its current step. Keeps the four call sites from each
     * re-deriving which columns feed which flag.
     *
     * @param  array<string, mixed>|object|null  $appointment
     */
    public static function forAppointment(mixed $appointment): ?self
    {
        if ($appointment === null) {
            return null;
        }

        $get = static function (string $key) use ($appointment): mixed {
            if (is_array($appointment)) {
                return $appointment[$key] ?? null;
            }

            return $appointment->{$key} ?? null;
        };

        $status = $get('status');
        if (! is_string($status) || trim($status) === '') {
            return null;
        }

        return self::fromAppointmentStatus(
            status: $status,
            hasTriageOwner: $get('triage_owner_user_id') !== null,
            hasConsultationStarted: $get('consultation_started_at') !== null,
            hasNursingContact: $get('nursing_contact_user_id') !== null,
        );
    }
}
