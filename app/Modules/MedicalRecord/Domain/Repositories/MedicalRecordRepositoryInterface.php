<?php

namespace App\Modules\MedicalRecord\Domain\Repositories;

interface MedicalRecordRepositoryInterface
{
    public function create(array $attributes): array;

    public function findById(string $id): ?array;

    public function findLatestDraftForAppointment(
        string $patientId,
        string $appointmentId,
        string $recordType,
    ): ?array;

    /**
     * Broader than findLatestDraftForAppointment(): scoped by encounter_id rather than
     * appointment_id, so it also covers admission-based visits (which have no
     * appointment_id at all) and every note type, not only consultation notes. See
     * reports/clinical-note-audit/15-critical-system-integrity-review.md, finding C-16.
     */
    public function findLatestDraftForEncounter(
        string $patientId,
        string $encounterId,
        string $recordType,
    ): ?array;

    public function hasDraftConsultationNoteForAppointment(string $appointmentId): bool;

    public function hasSignedConsultationNoteForAppointment(string $appointmentId): bool;

    /**
     * Batched form of hasSignedConsultationNoteForAppointment() — one query
     * for N appointments, not N queries. Backs clinician/Queue.vue's "note
     * signed" indicator (reports/appointments-scheduling-workspace-
     * modernization-plan.md); calling the single-appointment method per
     * queue row would be N+1.
     *
     * @param  array<int, string>  $appointmentIds
     * @return array<string, bool> keyed by appointment id; every id in
     *         $appointmentIds is present, false when no signed note exists.
     */
    public function hasSignedConsultationNoteForAppointments(array $appointmentIds): array;

    public function update(string $id, array $attributes): ?array;

    /**
     * @return array{outcome: 'updated', record: array<string, mixed>}|array{outcome: 'conflict', record: array<string, mixed>}|array{outcome: 'missing'}
     */
    public function updateWithOptimisticLock(
        string $id,
        array $attributes,
        ?string $expectedUpdatedAt,
        bool $forceDraftSave,
    ): array;

    /**
     * Reads the record under a row lock (`SELECT ... FOR UPDATE`) inside a
     * transaction, passes the freshly-locked current attributes to $mutator,
     * applies whatever it returns, and commits — all as one atomic unit. This
     * closes the gap where a caller validates business rules (e.g. a status
     * transition) against a value read *before* acquiring any lock, which a
     * concurrent writer could have already changed by the time the write
     * actually happens. $mutator may throw to abort without writing; the
     * transaction rolls back and the exception propagates to the caller.
     *
     * @param  callable(array<string, mixed>): array<string, mixed>  $mutator
     * @return array{outcome: 'updated', before: array<string, mixed>, record: array<string, mixed>}|array{outcome: 'missing'}
     */
    public function updateWithLock(string $id, callable $mutator): array;

    public function existsByRecordNumber(string $recordNumber): bool;

    public function search(
        ?string $query,
        ?string $patientId,
        ?string $encounterId,
        ?string $appointmentId,
        ?string $appointmentReferralId,
        ?string $admissionId,
        ?string $theatreProcedureId,
        ?int $authorUserId,
        ?string $status,
        ?string $recordType,
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
        ?string $encounterId,
        ?string $appointmentReferralId,
        ?string $admissionId,
        ?string $theatreProcedureId,
        ?string $recordType,
        ?string $fromDateTime,
        ?string $toDateTime
    ): array;
}
