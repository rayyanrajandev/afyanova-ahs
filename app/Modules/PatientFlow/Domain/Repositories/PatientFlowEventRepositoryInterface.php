<?php

namespace App\Modules\PatientFlow\Domain\Repositories;

interface PatientFlowEventRepositoryInterface
{
    /**
     * Appends one transition. Never updates — see PatientFlowEventModel.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function append(array $attributes): array;

    /**
     * The most recent event for a visit, or null when the visit has no
     * recorded history yet. Used to resolve `from_step` without the caller
     * having to track it, and to make a repeated transition a no-op.
     *
     * @return array<string, mixed>|null
     */
    public function latestForVisit(?string $appointmentId, ?string $serviceRequestId): ?array;

    /**
     * Chronological timeline for one patient, newest first.
     *
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, mixed>}
     */
    public function listForPatient(string $patientId, int $page, int $perPage): array;

    /**
     * Chronological timeline for a single visit, oldest first — the sequence a
     * staff member reads on the patient card.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listForVisit(?string $appointmentId, ?string $serviceRequestId, int $limit): array;

    /**
     * The occurred_at of the event that put each of these visits into its
     * current step, keyed by appointment id — the recorded answer to the
     * `stepEnteredAt` the board currently derives per-column and cannot supply
     * at all for waiting_clinician / waiting_clinician_review.
     *
     * @param  array<int, string>  $appointmentIds
     * @return array<string, string> appointmentId => ISO-8601 timestamp
     */
    public function currentStepEnteredAtByAppointmentIds(array $appointmentIds): array;
}
