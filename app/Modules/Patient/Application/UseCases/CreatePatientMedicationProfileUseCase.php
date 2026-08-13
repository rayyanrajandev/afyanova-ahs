<?php

namespace App\Modules\Patient\Application\UseCases;

use App\Modules\Patient\Domain\Repositories\PatientAuditLogRepositoryInterface;
use App\Modules\Patient\Domain\Repositories\PatientMedicationProfileRepositoryInterface;
use App\Modules\Patient\Domain\Repositories\PatientRepositoryInterface;
use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;

class CreatePatientMedicationProfileUseCase
{
    public function __construct(
        private readonly PatientRepositoryInterface $patientRepository,
        private readonly PatientMedicationProfileRepositoryInterface $patientMedicationProfileRepository,
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
        $this->normalizeLifecycleDates($payload);

        $created = $this->patientMedicationProfileRepository->create($payload);

        $this->auditLogRepository->write(
            patientId: $patientId,
            action: 'patient.medication-profile.created',
            actorId: $actorId,
            changes: [
                'after' => $this->extractTrackedFields($created),
            ],
        );

        return $created;
    }

    private function normalizeLifecycleDates(array &$payload): void
    {
        $status = trim((string) ($payload['status'] ?? 'active'));
        if ($status === 'active') {
            $payload['stopped_at'] = null;
            return;
        }

        if (
            in_array($status, ['stopped', 'completed'], true)
            && empty($payload['stopped_at'])
        ) {
            $payload['stopped_at'] = now();
        }
    }

    private function extractTrackedFields(array $record): array
    {
        $tracked = [
            'medication_code',
            'medication_name',
            'dose',
            'route',
            'frequency',
            'source',
            'status',
            'started_at',
            'stopped_at',
            'indication',
            'notes',
            'last_reconciled_at',
            'reconciliation_note',
        ];

        $result = [];
        foreach ($tracked as $field) {
            $result[$field] = $record[$field] ?? null;
        }

        return $result;
    }
}
