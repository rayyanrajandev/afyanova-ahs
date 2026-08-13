<?php

namespace App\Modules\EmergencyTriage\Application\UseCases;

use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;
use App\Modules\EmergencyTriage\Domain\Repositories\EmergencyTriageCaseAuditLogRepositoryInterface;
use App\Modules\EmergencyTriage\Domain\Repositories\EmergencyTriageCaseTransferAuditLogRepositoryInterface;
use App\Modules\EmergencyTriage\Domain\Repositories\EmergencyTriageCaseTransferRepositoryInterface;

class UpdateEmergencyTriageCaseTransferUseCase
{
    public function __construct(
        private readonly EmergencyTriageCaseTransferRepositoryInterface $transferRepository,
        private readonly EmergencyTriageCaseTransferAuditLogRepositoryInterface $transferAuditLogRepository,
        private readonly EmergencyTriageCaseAuditLogRepositoryInterface $caseAuditLogRepository,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    public function execute(
        string $emergencyTriageCaseId,
        string $transferId,
        array $payload,
        ?int $actorId = null
    ): ?array {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $existing = $this->transferRepository->findByCaseAndId($emergencyTriageCaseId, $transferId);
        if (! $existing) {
            return null;
        }

        $updated = $this->transferRepository->update($transferId, $payload);
        if (! $updated) {
            return null;
        }

        $changes = $this->extractChanges($existing, $updated);
        if ($changes !== []) {
            $metadata = [
                'transfer_number' => $updated['transfer_number'] ?? null,
            ];

            $this->transferAuditLogRepository->write(
                transferId: $transferId,
                emergencyTriageCaseId: $emergencyTriageCaseId,
                action: 'emergency-triage-case.transfer.updated',
                actorId: $actorId,
                changes: $changes,
                metadata: $metadata,
            );

            $this->caseAuditLogRepository->write(
                emergencyTriageCaseId: $emergencyTriageCaseId,
                action: 'emergency-triage-case.transfer.updated',
                actorId: $actorId,
                changes: $changes,
                metadata: [
                    'transfer_id' => $transferId,
                    'transfer_number' => $metadata['transfer_number'],
                ],
            );
        }

        return $updated;
    }

    /**
     * @return array<string, mixed>
     */
    private function extractChanges(array $before, array $after): array
    {
        $trackedFields = [
            'transfer_type',
            'priority',
            'source_location',
            'destination_location',
            'destination_facility_name',
            'accepting_clinician_user_id',
            'requested_at',
            'accepted_at',
            'departed_at',
            'arrived_at',
            'completed_at',
            'status',
            'status_reason',
            'clinical_handoff_notes',
            'transport_mode',
            'metadata',
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
