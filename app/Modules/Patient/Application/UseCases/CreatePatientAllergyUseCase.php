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
        $payload['status'] = trim((string) ($payload['status'] ?? 'active')) ?: 'active';

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
            'severity',
            'status',
            'noted_at',
            'last_reaction_at',
            'notes',
        ];

        $result = [];
        foreach ($tracked as $field) {
            $result[$field] = $record[$field] ?? null;
        }

        return $result;
    }
}
