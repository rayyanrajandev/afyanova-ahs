<?php

namespace App\Modules\Patient\Application\UseCases;

use App\Modules\Patient\Domain\Repositories\PatientAllergyRepositoryInterface;
use App\Modules\Patient\Domain\Repositories\PatientAuditLogRepositoryInterface;
use App\Modules\Patient\Domain\Repositories\PatientRepositoryInterface;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;

class UpdatePatientAllergyUseCase
{
    public function __construct(
        private readonly PatientRepositoryInterface $patientRepository,
        private readonly PatientAllergyRepositoryInterface $patientAllergyRepository,
        private readonly PatientAuditLogRepositoryInterface $auditLogRepository,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    public function execute(
        string $patientId,
        string $allergyId,
        array $payload,
        ?int $actorId = null
    ): ?array {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        if ($this->patientRepository->findById($patientId) === null) {
            return null;
        }

        $before = $this->patientAllergyRepository->findById($allergyId);
        if ($before === null || (string) ($before['patient_id'] ?? '') !== $patientId) {
            return null;
        }

        $updated = $this->patientAllergyRepository->update($allergyId, $payload);
        if ($updated === null) {
            return null;
        }

        $changes = $this->extractChanges($before, $updated);
        if ($changes !== []) {
            $this->auditLogRepository->write(
                patientId: $patientId,
                action: 'patient.allergy.updated',
                actorId: $actorId,
                changes: $changes,
            );
        }

        return $updated;
    }

    private function extractChanges(array $before, array $after): array
    {
        $trackedFields = [
            'substance_code',
            'substance_name',
            'reaction',
            'severity',
            'status',
            'noted_at',
            'last_reaction_at',
            'notes',
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
}
