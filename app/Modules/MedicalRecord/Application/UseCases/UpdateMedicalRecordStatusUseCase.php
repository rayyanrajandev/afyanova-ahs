<?php

namespace App\Modules\MedicalRecord\Application\UseCases;

use App\Modules\Encounter\Application\Services\EncounterLifecycleService;
use App\Modules\MedicalRecord\Application\Exceptions\ConsultationOwnerConflictForMedicalRecordException;
use App\Modules\MedicalRecord\Application\Exceptions\InvalidMedicalRecordStatusTransitionException;
use App\Modules\MedicalRecord\Domain\Repositories\MedicalRecordAuditLogRepositoryInterface;
use App\Modules\MedicalRecord\Domain\Repositories\MedicalRecordRepositoryInterface;
use App\Modules\MedicalRecord\Domain\Repositories\MedicalRecordVersionRepositoryInterface;
use App\Modules\MedicalRecord\Domain\Services\AppointmentLookupServiceInterface;
use App\Modules\MedicalRecord\Domain\ValueObjects\MedicalRecordStatus;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;
use Illuminate\Support\Facades\DB;

class UpdateMedicalRecordStatusUseCase
{
    public function __construct(
        private readonly MedicalRecordAuditLogRepositoryInterface $auditLogRepository,
        private readonly MedicalRecordRepositoryInterface $medicalRecordRepository,
        private readonly MedicalRecordVersionRepositoryInterface $medicalRecordVersionRepository,
        private readonly AppointmentLookupServiceInterface $appointmentLookupService,
        private readonly EncounterLifecycleService $encounterLifecycleService,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    public function execute(string $id, string $status, ?string $reason, ?int $actorId = null): ?array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $requestedStatus = strtolower(trim($status));

        // C-7 (reports/clinical-note-audit/15-critical-system-integrity-review.md):
        // the primary write, audit log, version snapshot, and encounter-status
        // sync used to be four independently-committed operations — a failure
        // between steps (e.g. after the note committed as finalized but before
        // the encounter sync ran) left the note and its encounter permanently
        // out of sync, with nothing to detect or reconcile the drift. Wrapping
        // the whole sequence in one transaction means a failure anywhere in it
        // rolls back everything, including the row-locked write inside
        // updateWithLock() (nested via a savepoint, not a second top-level
        // transaction).
        return DB::transaction(function () use ($id, $status, $reason, $requestedStatus, $actorId): ?array {
            // The ownership check, transition validation, and payload construction all run
            // inside the mutator, against the record as read under a row lock — not against
            // a value read before any lock was taken. This closes the race where a concurrent
            // content autosave (which also locks the row, via updateWithOptimisticLock) could
            // change status/content between an earlier stale read and this write. See
            // reports/clinical-note-audit/15-critical-system-integrity-review.md, finding C-1.
            $result = $this->medicalRecordRepository->updateWithLock(
                $id,
                function (array $existing) use ($status, $reason, $requestedStatus, $actorId): array {
                    $this->assertConsultationOwnershipForEncounterWrite(
                        $existing['appointment_id'] ?? null,
                        $actorId,
                    );

                    $currentStatus = strtolower(trim((string) ($existing['status'] ?? '')));
                    $this->assertValidStatusTransition($currentStatus, $requestedStatus);

                    $statusUpdatePayload = [
                        'status' => $status,
                        'status_reason' => $reason,
                    ];

                    if ($requestedStatus === MedicalRecordStatus::AMENDED->value) {
                        $statusUpdatePayload['status'] = MedicalRecordStatus::DRAFT->value;
                    }

                    if ($requestedStatus === MedicalRecordStatus::FINALIZED->value) {
                        $wasPreviouslySigned = ($existing['signed_at'] ?? null) !== null;
                        if ($wasPreviouslySigned) {
                            $statusUpdatePayload['status'] = MedicalRecordStatus::AMENDED->value;
                        }

                        $statusUpdatePayload['signed_by_user_id'] = $actorId;
                        $statusUpdatePayload['signed_at'] = now();
                    }

                    return $statusUpdatePayload;
                },
            );

            if ($result['outcome'] === 'missing') {
                return null;
            }

            $existing = $result['before'];
            $updated = $result['record'];

            $reasonRequired = in_array($status, [
                MedicalRecordStatus::AMENDED->value,
                MedicalRecordStatus::ARCHIVED->value,
            ], true);

            $statusChanges = $this->extractStatusLifecycleChanges($existing, $updated);

            $this->auditLogRepository->write(
                medicalRecordId: $id,
                action: 'medical-record.status.updated',
                actorId: $actorId,
                changes: $statusChanges,
                metadata: array_merge([
                    'transition' => [
                        'from' => $existing['status'] ?? null,
                        'to' => $updated['status'] ?? null,
                    ],
                    'reason_required' => $reasonRequired,
                    'reason_provided' => trim((string) ($updated['status_reason'] ?? '')) !== '',
                ]),
            );

            if ($statusChanges !== []) {
                $this->medicalRecordVersionRepository->create(
                    medicalRecordId: $id,
                    snapshot: $this->extractVersionSnapshot($updated),
                    changedFields: array_keys($statusChanges),
                    createdByUserId: $actorId,
                );
            }

            $encounterId = trim((string) ($updated['encounter_id'] ?? ''));
            if ($encounterId !== '') {
                $this->encounterLifecycleService->syncFromMedicalRecordStatus(
                    encounterId: $encounterId,
                    medicalRecordStatus: (string) ($updated['status'] ?? ''),
                    reason: is_string($updated['status_reason'] ?? null)
                        ? $updated['status_reason']
                        : null,
                    actorId: $actorId,
                );

                // Signing off (finalize — whether it lands as finalized, or as
                // amended when re-signing an already-signed note) is the moment
                // the note's single diagnosisCode should flow into the
                // encounter's structured problem list, not while still a draft.
                if ($requestedStatus === MedicalRecordStatus::FINALIZED->value) {
                    $this->encounterLifecycleService->syncPrimaryDiagnosisFromMedicalRecord(
                        encounterId: $encounterId,
                        diagnosisCode: is_string($updated['diagnosis_code'] ?? null)
                            ? $updated['diagnosis_code']
                            : null,
                        actorId: $actorId,
                    );
                }
            }

            return $updated;
        });
    }

    private function assertValidStatusTransition(string $fromStatus, string $toStatus): void
    {
        if ($fromStatus === $toStatus) {
            return;
        }

        $allowed = match ($toStatus) {
            MedicalRecordStatus::FINALIZED->value => $fromStatus === MedicalRecordStatus::DRAFT->value,
            MedicalRecordStatus::AMENDED->value => $fromStatus === MedicalRecordStatus::FINALIZED->value,
            MedicalRecordStatus::ARCHIVED->value => in_array($fromStatus, [
                MedicalRecordStatus::DRAFT->value,
                MedicalRecordStatus::FINALIZED->value,
                MedicalRecordStatus::AMENDED->value,
            ], true),
            default => false,
        };

        if (! $allowed) {
            throw new InvalidMedicalRecordStatusTransitionException($fromStatus, $toStatus);
        }
    }

    private function assertConsultationOwnershipForEncounterWrite(?string $appointmentId, ?int $actorId): void
    {
        $normalizedAppointmentId = trim((string) $appointmentId);
        if ($normalizedAppointmentId === '') {
            return;
        }

        $appointment = $this->appointmentLookupService->findById($normalizedAppointmentId);
        if ($appointment === null) {
            return;
        }

        $status = strtolower(trim((string) ($appointment['status'] ?? '')));
        if ($status !== 'in_consultation') {
            return;
        }

        $ownerUserId = $this->resolveConsultationOwnerUserId($appointment);
        if ($ownerUserId <= 0) {
            return;
        }

        if ($actorId !== null && $ownerUserId === $actorId) {
            return;
        }

        throw new ConsultationOwnerConflictForMedicalRecordException($ownerUserId);
    }

    /**
     * @param  array<string, mixed>  $appointment
     */
    private function resolveConsultationOwnerUserId(array $appointment): int
    {
        $ownerUserId = (int) ($appointment['consultation_owner_user_id'] ?? 0);
        if ($ownerUserId > 0) {
            return $ownerUserId;
        }

        return (int) ($appointment['clinician_user_id'] ?? 0);
    }

    /**
     * @return array<string, mixed>
     */
    private function extractStatusLifecycleChanges(array $before, array $after): array
    {
        $trackedFields = [
            'status',
            'status_reason',
            'signed_by_user_id',
            'signed_at',
        ];

        $changes = [];
        foreach ($trackedFields as $field) {
            $beforeValue = $before[$field] ?? null;
            $afterValue = $after[$field] ?? null;

            if ($beforeValue === $afterValue) {
                continue;
            }

            $changes[$field] = [
                'before' => $beforeValue,
                'after' => $afterValue,
            ];
        }

        return $changes;
    }

    /**
     * @return array<string, mixed>
     */
    private function extractVersionSnapshot(array $record): array
    {
        $tracked = [
            'patient_id',
            'admission_id',
            'appointment_id',
            'author_user_id',
            'encounter_at',
            'record_type',
            'subjective',
            'objective',
            'assessment',
            'plan',
            'diagnosis_code',
            'status',
            'status_reason',
            'signed_by_user_id',
            'signed_at',
        ];

        $snapshot = [];
        foreach ($tracked as $field) {
            $snapshot[$field] = $record[$field] ?? null;
        }

        return $snapshot;
    }
}
