<?php

namespace App\Modules\MedicalRecord\Application\UseCases;

use App\Modules\MedicalRecord\Application\Exceptions\MedicalRecordSignerAttestationNotAllowedException;
use App\Modules\MedicalRecord\Domain\Repositories\MedicalRecordAuditLogRepositoryInterface;
use App\Modules\MedicalRecord\Domain\Repositories\MedicalRecordRepositoryInterface;
use App\Modules\MedicalRecord\Domain\Repositories\MedicalRecordSignerAttestationRepositoryInterface;
use App\Modules\MedicalRecord\Domain\ValueObjects\MedicalRecordStatus;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;

class CreateMedicalRecordSignerAttestationUseCase
{
    public function __construct(
        private readonly MedicalRecordAuditLogRepositoryInterface $auditLogRepository,
        private readonly MedicalRecordRepositoryInterface $medicalRecordRepository,
        private readonly MedicalRecordSignerAttestationRepositoryInterface $medicalRecordSignerAttestationRepository,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    public function execute(string $medicalRecordId, string $attestationNote, ?int $actorId): ?array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        if ($actorId === null) {
            throw new MedicalRecordSignerAttestationNotAllowedException(
                'Signer attestation requires an authenticated user context.',
            );
        }

        $record = $this->medicalRecordRepository->findById($medicalRecordId);
        if (! $record) {
            return null;
        }

        $status = (string) ($record['status'] ?? '');
        if (! in_array($status, [
            MedicalRecordStatus::FINALIZED->value,
            MedicalRecordStatus::AMENDED->value,
        ], true)) {
            throw new MedicalRecordSignerAttestationNotAllowedException(
                'Signer attestation is only allowed when medical record status is finalized or amended.',
            );
        }

        $note = trim($attestationNote);
        if ($note === '') {
            throw new MedicalRecordSignerAttestationNotAllowedException(
                'Attestation note is required.',
            );
        }

        $created = $this->medicalRecordSignerAttestationRepository->create(
            medicalRecordId: $medicalRecordId,
            attestedByUserId: $actorId,
            attestationNote: $note,
        );

        $this->auditLogRepository->write(
            medicalRecordId: $medicalRecordId,
            action: 'medical-record.signer-attested',
            actorId: $actorId,
            changes: [
                'attestation' => [
                    'after' => [
                        'id' => $created['id'] ?? null,
                        'attested_by_user_id' => $created['attested_by_user_id'] ?? null,
                        'attested_at' => $created['attested_at'] ?? null,
                        'attestation_note' => $created['attestation_note'] ?? null,
                    ],
                ],
            ],
            metadata: array_merge([
                'record_status' => $record['status'] ?? null,
                'signed_by_user_id' => $record['signed_by_user_id'] ?? null,
                'signed_at' => $record['signed_at'] ?? null,
            ]),
        );

        return $created;
    }
}
