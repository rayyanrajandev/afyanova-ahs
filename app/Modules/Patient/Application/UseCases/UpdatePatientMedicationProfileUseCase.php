<?php

namespace App\Modules\Patient\Application\UseCases;

use App\Modules\Patient\Domain\Repositories\PatientAuditLogRepositoryInterface;
use App\Modules\Patient\Domain\Repositories\PatientMedicationProfileRepositoryInterface;
use App\Modules\Patient\Domain\Repositories\PatientRepositoryInterface;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;

class UpdatePatientMedicationProfileUseCase
{
    public function __construct(
        private readonly PatientRepositoryInterface $patientRepository,
        private readonly PatientMedicationProfileRepositoryInterface $patientMedicationProfileRepository,
        private readonly PatientAuditLogRepositoryInterface $auditLogRepository,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    public function execute(
        string $patientId,
        string $medicationId,
        array $payload,
        ?int $actorId = null
    ): ?array {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        if ($this->patientRepository->findById($patientId) === null) {
            return null;
        }

        $before = $this->patientMedicationProfileRepository->findById($medicationId);
        if ($before === null || (string) ($before['patient_id'] ?? '') !== $patientId) {
            return null;
        }

        $this->normalizeLifecycleDates($payload, $before);
        $updated = $this->patientMedicationProfileRepository->update($medicationId, $payload);
        if ($updated === null) {
            return null;
        }

        $changes = $this->extractChanges($before, $updated);
        if ($changes !== []) {
            $this->auditLogRepository->write(
                patientId: $patientId,
                action: 'patient.medication-profile.updated',
                actorId: $actorId,
                changes: $changes,
            );
        }

        return $updated;
    }

    private function normalizeLifecycleDates(array &$payload, array $before): void
    {
        if (! array_key_exists('status', $payload)) {
            return;
        }

        $status = trim((string) ($payload['status'] ?? ($before['status'] ?? 'active')));
        if ($status === 'active') {
            $payload['stopped_at'] = null;
            return;
        }

        if (
            in_array($status, ['stopped', 'completed'], true)
            && empty($payload['stopped_at'])
            && empty($before['stopped_at'])
        ) {
            $payload['stopped_at'] = now();
        }
    }

    private function extractChanges(array $before, array $after): array
    {
        $trackedFields = [
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
