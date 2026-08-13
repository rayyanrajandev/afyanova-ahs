<?php

namespace App\Modules\Billing\Application\UseCases;

use App\Modules\Billing\Domain\Repositories\PatientInsuranceRepositoryInterface;
use App\Modules\Billing\Infrastructure\Repositories\PatientInsuranceAuditEventRepository;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;

class VerifyPatientInsuranceRecordUseCase
{
    public function __construct(
        private readonly PatientInsuranceRepositoryInterface $repository,
        private readonly PatientInsuranceAuditEventRepository $auditEventRepository,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    public function execute(string $patientId, string $recordId, array $payload, ?int $actorId = null): ?array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $existing = $this->repository->findById($recordId);
        if ($existing === null || ($existing['patient_id'] ?? null) !== $patientId) {
            return null;
        }

        $verifiedAt = now();
        $updated = $this->repository->update($recordId, [
            'verification_status' => strtolower(trim((string) ($payload['verification_status'] ?? 'verified'))),
            'verification_date' => $verifiedAt,
            'last_verified_at' => $verifiedAt,
            'verification_source' => $this->nullableText($payload['verification_source'] ?? 'manual'),
            'verification_reference' => $this->nullableText($payload['verification_reference'] ?? null),
            'verified_by_user_id' => $actorId,
        ]);

        $this->auditEventRepository->write(
            patientInsuranceRecordId: $recordId,
            patientId: $patientId,
            action: 'patient-insurance.verified',
            actorId: $actorId,
            changes: [
                'before' => [
                    'verification_status' => $existing['verification_status'] ?? null,
                    'verification_reference' => $existing['verification_reference'] ?? null,
                    'last_verified_at' => $existing['last_verified_at'] ?? null,
                ],
                'after' => [
                    'verification_status' => $updated['verification_status'] ?? null,
                    'verification_reference' => $updated['verification_reference'] ?? null,
                    'last_verified_at' => $updated['last_verified_at'] ?? null,
                ],
            ],
        );

        return $updated;
    }

    private function nullableText(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }
}
