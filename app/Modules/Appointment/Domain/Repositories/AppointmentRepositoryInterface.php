<?php

namespace App\Modules\Appointment\Domain\Repositories;

interface AppointmentRepositoryInterface
{
    public function create(array $attributes): array;

    public function findById(string $id): ?array;

    public function update(string $id, array $attributes): ?array;

    public function existsByAppointmentNumber(string $appointmentNumber): bool;

    public function findActiveForPatientOnDate(
        string $patientId,
        string $scheduledDate,
        ?string $excludeAppointmentId = null,
    ): ?array;

    /**
     * Not date-scoped (2026-08-12, duplicate-check-in fix): a visit that
     * started on a previous calendar day and is still waiting_triage/
     * waiting_provider/in_consultation is a real active visit regardless
     * of which day "now" happens to be — findActiveForPatientOnDate()'s
     * same-day scoping is only correct for its own *future-appointment
     * scheduling* conflict check, not for "does this patient already have
     * an unresolved visit right now."
     *
     * Also deliberately narrower than findActiveForPatientOnDate()'s
     * status whitelist: 'scheduled' (a future booking the patient hasn't
     * arrived for yet) is excluded here on purpose. This method answers
     * "has the patient already arrived and not yet been resolved," for
     * blocking a duplicate walk-in/emergency check-in — a scheduled slot
     * later today must not block an emergency walk-in from being checked
     * in right now.
     */
    public function findActiveForPatient(
        string $patientId,
        ?string $excludeAppointmentId = null,
    ): ?array;

    /**
     * Soft-warning check for CreateAppointmentUseCase (2026-08-12, Reception
     * scheduling-duplicate audit) — unlike findActiveForPatientOnDate()'s
     * hard same-day block, this deliberately has NO date scoping and
     * includes 'scheduled' in its whitelist: it exists purely to surface
     * "heads up, this patient already has another unresolved appointment
     * somewhere on the books" as a non-blocking warning after a *different*
     * day's appointment is created, not to prevent the create. Two future
     * visits in the same or different departments are frequently legitimate
     * (follow-ups, multi-department workups) — the receptionist just wasn't
     * being told about the other one. $excludeAppointmentId is required
     * (not optional) here since every caller already has the
     * just-created appointment's id to exclude — this method has no
     * "check before creating" use, only "check after."
     */
    public function findOtherUpcomingForPatient(
        string $patientId,
        string $excludeAppointmentId,
    ): ?array;

    /**
     * Candidate rows for a clinician-overlap check: same clinician,
     * scheduled_at within [$windowStart, $windowEnd], non-terminal status.
     * Callers do the exact time-range overlap comparison in PHP (not SQL)
     * since "scheduled_at + duration" date arithmetic isn't portable across
     * the sqlite test driver and production's DB engine. $windowStart/
     * $windowEnd should be wide enough (e.g. the reference time +/- the max
     * possible appointment duration) to guarantee no true overlap falls
     * outside the window.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findActiveForClinicianInWindow(
        int $clinicianUserId,
        string $windowStart,
        string $windowEnd,
        ?string $excludeAppointmentId = null,
    ): array;

    public function search(
        ?string $query,
        ?string $patientId,
        ?int $clinicianUserId,
        ?string $department,
        bool $unassignedClinicianOnly,
        ?string $status,
        ?string $triageCategory,
        ?string $fromDateTime,
        ?string $toDateTime,
        int $page,
        int $perPage,
        ?string $sortBy,
        string $sortDirection
    ): array;

    public function statusCounts(
        ?string $query,
        ?string $patientId,
        ?int $clinicianUserId,
        ?string $department,
        bool $unassignedClinicianOnly,
        ?string $status,
        ?string $triageCategory,
        ?string $fromDateTime,
        ?string $toDateTime
    ): array;

    /**
     * Find the most recent completed appointment for the patient at the given
     * facility whose scheduled_at date falls within the $withinDays window
     * before $scheduledAt. Null facilityId means the single-facility/global
     * appointment scope.
     *
     * @return array<string, mixed>|null
     */
    public function findLastCompletedForPatientWithinDays(
        string $patientId,
        ?string $facilityId,
        string $scheduledAt,
        int $withinDays,
    ): ?array;
}
