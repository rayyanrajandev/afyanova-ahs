<?php

namespace App\Modules\EmergencyTriage\Application\UseCases;

use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;
use App\Modules\EmergencyTriage\Domain\Repositories\EmergencyTriageCaseAuditLogRepositoryInterface;
use App\Modules\EmergencyTriage\Domain\Repositories\EmergencyTriageCaseTransferAuditLogRepositoryInterface;
use App\Modules\EmergencyTriage\Domain\Repositories\EmergencyTriageCaseTransferRepositoryInterface;
use App\Modules\EmergencyTriage\Domain\ValueObjects\EmergencyTriageCaseTransferStatus;

class UpdateEmergencyTriageCaseTransferStatusUseCase
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
        string $status,
        ?string $reason,
        ?string $clinicalHandoffNotes,
        ?int $actorId = null
    ): ?array {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $existing = $this->transferRepository->findByCaseAndId($emergencyTriageCaseId, $transferId);
        if (! $existing) {
            return null;
        }

        $payload = [
            'status' => $status,
            'status_reason' => $reason,
        ];

        if ($clinicalHandoffNotes !== null) {
            $payload['clinical_handoff_notes'] = $clinicalHandoffNotes;
        }

        if ($status === EmergencyTriageCaseTransferStatus::REQUESTED->value) {
            $payload['accepted_at'] = null;
            $payload['departed_at'] = null;
            $payload['arrived_at'] = null;
            $payload['completed_at'] = null;
        }

        if ($status === EmergencyTriageCaseTransferStatus::ACCEPTED->value) {
            $payload['accepted_at'] = $existing['accepted_at'] ?? now();
            $payload['departed_at'] = null;
            $payload['arrived_at'] = null;
            $payload['completed_at'] = null;
        }

        if ($status === EmergencyTriageCaseTransferStatus::IN_TRANSIT->value) {
            $payload['accepted_at'] = $existing['accepted_at'] ?? now();
            $payload['departed_at'] = $existing['departed_at'] ?? now();
            $payload['arrived_at'] = null;
            $payload['completed_at'] = null;
        }

        if ($status === EmergencyTriageCaseTransferStatus::COMPLETED->value) {
            $payload['accepted_at'] = $existing['accepted_at'] ?? now();
            $payload['departed_at'] = $existing['departed_at'] ?? now();
            $payload['arrived_at'] = $existing['arrived_at'] ?? now();
            $payload['completed_at'] = now();
        }

        if (in_array($status, [
            EmergencyTriageCaseTransferStatus::CANCELLED->value,
            EmergencyTriageCaseTransferStatus::REJECTED->value,
        ], true)) {
            $payload['completed_at'] = now();
        }

        $updated = $this->transferRepository->update($transferId, $payload);
        if (! $updated) {
            return null;
        }

        $changes = [
            'status' => [
                'before' => $existing['status'] ?? null,
                'after' => $updated['status'] ?? null,
            ],
            'status_reason' => [
                'before' => $existing['status_reason'] ?? null,
                'after' => $updated['status_reason'] ?? null,
            ],
            'clinical_handoff_notes' => [
                'before' => $existing['clinical_handoff_notes'] ?? null,
                'after' => $updated['clinical_handoff_notes'] ?? null,
            ],
            'accepted_at' => [
                'before' => $existing['accepted_at'] ?? null,
                'after' => $updated['accepted_at'] ?? null,
            ],
            'departed_at' => [
                'before' => $existing['departed_at'] ?? null,
                'after' => $updated['departed_at'] ?? null,
            ],
            'arrived_at' => [
                'before' => $existing['arrived_at'] ?? null,
                'after' => $updated['arrived_at'] ?? null,
            ],
            'completed_at' => [
                'before' => $existing['completed_at'] ?? null,
                'after' => $updated['completed_at'] ?? null,
            ],
        ];

        $statusMetadata = [
            'transition' => [
                'from' => $existing['status'] ?? null,
                'to' => $updated['status'] ?? null,
            ],
            'accepted_timestamp_required' => in_array($status, [
                EmergencyTriageCaseTransferStatus::ACCEPTED->value,
                EmergencyTriageCaseTransferStatus::IN_TRANSIT->value,
                EmergencyTriageCaseTransferStatus::COMPLETED->value,
            ], true),
            'accepted_timestamp_provided' => ($updated['accepted_at'] ?? null) !== null,
            'departure_timestamp_required' => in_array($status, [
                EmergencyTriageCaseTransferStatus::IN_TRANSIT->value,
                EmergencyTriageCaseTransferStatus::COMPLETED->value,
            ], true),
            'departure_timestamp_provided' => ($updated['departed_at'] ?? null) !== null,
            'arrival_timestamp_required' => $status === EmergencyTriageCaseTransferStatus::COMPLETED->value,
            'arrival_timestamp_provided' => ($updated['arrived_at'] ?? null) !== null,
            'completion_timestamp_required' => in_array($status, [
                EmergencyTriageCaseTransferStatus::COMPLETED->value,
                EmergencyTriageCaseTransferStatus::CANCELLED->value,
                EmergencyTriageCaseTransferStatus::REJECTED->value,
            ], true),
            'completion_timestamp_provided' => ($updated['completed_at'] ?? null) !== null,
            'closure_reason_required' => in_array($status, [
                EmergencyTriageCaseTransferStatus::CANCELLED->value,
                EmergencyTriageCaseTransferStatus::REJECTED->value,
            ], true),
            'closure_reason_provided' => trim((string) ($updated['status_reason'] ?? '')) !== '',
        ];

        $metadata = [
            'transfer_number' => $updated['transfer_number'] ?? null,
        ] + $statusMetadata;

        $this->transferAuditLogRepository->write(
            transferId: $transferId,
            emergencyTriageCaseId: $emergencyTriageCaseId,
            action: 'emergency-triage-case.transfer.status.updated',
            actorId: $actorId,
            changes: $changes,
            metadata: $metadata,
        );

        $this->caseAuditLogRepository->write(
            emergencyTriageCaseId: $emergencyTriageCaseId,
            action: 'emergency-triage-case.transfer.status.updated',
            actorId: $actorId,
            changes: $changes,
            metadata: [
                'transfer_id' => $transferId,
                'transfer_number' => $metadata['transfer_number'],
            ] + $statusMetadata,
        );

        return $updated;
    }
}
