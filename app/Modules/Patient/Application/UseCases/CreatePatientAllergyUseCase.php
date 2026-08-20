<?php

namespace App\Modules\Patient\Application\UseCases;

use App\Modules\Patient\Domain\Repositories\PatientAllergyRepositoryInterface;
use App\Modules\Patient\Domain\Repositories\PatientAuditLogRepositoryInterface;
use App\Modules\Patient\Domain\Repositories\PatientRepositoryInterface;
use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;

class CreatePatientAllergyUseCase
{
    public function __construct(
        private readonly PatientRepositoryInterface $patientRepository,
        private readonly PatientAllergyRepositoryInterface $patientAllergyRepository,
        private readonly PatientAuditLogRepositoryInterface $auditLogRepository,
        private readonly CurrentPlatformScopeContextInterface $platformScopeContext,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    public function execute(string $patientId, array $payload, ?int $actorId = null): ?array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        if ($this->patientRepository->findById($patientId) === null) {
            return null;
        }

        $payload['patient_id'] = $patientId;
        $payload['tenant_id'] = $this->platformScopeContext->tenantId();
        $payload['clinical_status'] = trim((string) ($payload['clinical_status'] ?? 'active')) ?: 'active';
        $payload['verification_status'] = trim((string) ($payload['verification_status'] ?? 'unconfirmed')) ?: 'unconfirmed';

        $created = $this->patientAllergyRepository->create($payload);

        $this->auditLogRepository->write(
            patientId: $patientId,
            action: 'patient.allergy.created',
            actorId: $actorId,
            changes: [
                'after' => $this->extractTrackedFields($created),
            ],
        );

        return $created;
    }

    private function extractTrackedFields(array $record): array
    {
        $tracked = [
            'substance_code',
            'substance_name',
            'reaction',
            'reaction_code',
            'severity',
            'clinical_status',
            'verification_status',
            'type',
            'category',
            'noted_at',
            'last_reaction_at',
            'notes',
            'source',
        ];

        $result = [];
        foreach ($tracked as $field) {
            $result[$field] = $record[$field] ?? null;
        }

        return $result;
    }
}
