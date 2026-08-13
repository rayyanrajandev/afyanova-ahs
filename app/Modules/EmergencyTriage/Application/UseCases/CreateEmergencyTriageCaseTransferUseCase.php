<?php

namespace App\Modules\EmergencyTriage\Application\UseCases;

use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;
use App\Modules\EmergencyTriage\Domain\Repositories\EmergencyTriageCaseAuditLogRepositoryInterface;
use App\Modules\EmergencyTriage\Domain\Repositories\EmergencyTriageCaseRepositoryInterface;
use App\Modules\EmergencyTriage\Domain\Repositories\EmergencyTriageCaseTransferAuditLogRepositoryInterface;
use App\Modules\EmergencyTriage\Domain\Repositories\EmergencyTriageCaseTransferRepositoryInterface;
use App\Modules\EmergencyTriage\Domain\ValueObjects\EmergencyTriageCaseTransferStatus;
use Illuminate\Support\Str;
use RuntimeException;

class CreateEmergencyTriageCaseTransferUseCase
{
    public function __construct(
        private readonly EmergencyTriageCaseRepositoryInterface $emergencyTriageCaseRepository,
        private readonly EmergencyTriageCaseTransferRepositoryInterface $transferRepository,
        private readonly EmergencyTriageCaseTransferAuditLogRepositoryInterface $transferAuditLogRepository,
        private readonly EmergencyTriageCaseAuditLogRepositoryInterface $caseAuditLogRepository,
        private readonly CurrentPlatformScopeContextInterface $platformScopeContext,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    public function execute(string $emergencyTriageCaseId, array $payload, ?int $actorId = null): ?array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $case = $this->emergencyTriageCaseRepository->findById($emergencyTriageCaseId);
        if (! $case) {
            return null;
        }

        $status = $payload['status'] ?? EmergencyTriageCaseTransferStatus::REQUESTED->value;
        if (! in_array($status, EmergencyTriageCaseTransferStatus::values(), true)) {
            $status = EmergencyTriageCaseTransferStatus::REQUESTED->value;
        }

        $requestedAt = $payload['requested_at'] ?? now();

        $createPayload = [
            'emergency_triage_case_id' => $emergencyTriageCaseId,
            'transfer_number' => $this->generateTransferNumber(),
            'tenant_id' => $this->platformScopeContext->tenantId(),
            'facility_id' => $this->platformScopeContext->facilityId(),
            'transfer_type' => $payload['transfer_type'],
            'priority' => $payload['priority'],
            'source_location' => $payload['source_location'] ?? null,
            'destination_location' => $payload['destination_location'],
            'destination_facility_name' => $payload['destination_facility_name'] ?? null,
            'accepting_clinician_user_id' => $payload['accepting_clinician_user_id'] ?? null,
            'requested_at' => $requestedAt,
            'accepted_at' => null,
            'departed_at' => null,
            'arrived_at' => null,
            'completed_at' => null,
            'status' => $status,
            'status_reason' => $payload['status_reason'] ?? null,
            'clinical_handoff_notes' => $payload['clinical_handoff_notes'] ?? null,
            'transport_mode' => $payload['transport_mode'] ?? null,
            'metadata' => $payload['metadata'] ?? null,
        ];

        if (in_array($status, [
            EmergencyTriageCaseTransferStatus::ACCEPTED->value,
            EmergencyTriageCaseTransferStatus::IN_TRANSIT->value,
            EmergencyTriageCaseTransferStatus::COMPLETED->value,
        ], true)) {
            $createPayload['accepted_at'] = $payload['accepted_at'] ?? now();
        }

        if (in_array($status, [
            EmergencyTriageCaseTransferStatus::IN_TRANSIT->value,
            EmergencyTriageCaseTransferStatus::COMPLETED->value,
        ], true)) {
            $createPayload['departed_at'] = $payload['departed_at'] ?? now();
        }

        if ($status === EmergencyTriageCaseTransferStatus::COMPLETED->value) {
            $createPayload['arrived_at'] = $payload['arrived_at'] ?? now();
            $createPayload['completed_at'] = $payload['completed_at'] ?? now();
        }

        if (in_array($status, [
            EmergencyTriageCaseTransferStatus::CANCELLED->value,
            EmergencyTriageCaseTransferStatus::REJECTED->value,
        ], true)) {
            $createPayload['completed_at'] = $payload['completed_at'] ?? now();
        }

        $created = $this->transferRepository->create($createPayload);

        $changes = [
            'after' => $this->extractTrackedFields($created),
        ];

        $metadata = [
            'transfer_number' => $created['transfer_number'] ?? null,
        ];

        $this->transferAuditLogRepository->write(
            transferId: $created['id'],
            emergencyTriageCaseId: $emergencyTriageCaseId,
            action: 'emergency-triage-case.transfer.created',
            actorId: $actorId,
            changes: $changes,
            metadata: $metadata,
        );

        $this->caseAuditLogRepository->write(
            emergencyTriageCaseId: $emergencyTriageCaseId,
            action: 'emergency-triage-case.transfer.created',
            actorId: $actorId,
            changes: $changes,
            metadata: [
                'transfer_id' => $created['id'] ?? null,
                'transfer_number' => $metadata['transfer_number'],
            ],
        );

        return $created;
    }

    private function generateTransferNumber(): string
    {
        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $candidate = 'ETT'.now()->format('Ymd').strtoupper(Str::random(6));

            if (! $this->transferRepository->existsByTransferNumber($candidate)) {
                return $candidate;
            }
        }

        throw new RuntimeException('Unable to generate unique emergency transfer number.');
    }

    /**
     * @return array<string, mixed>
     */
    private function extractTrackedFields(array $transfer): array
    {
        $tracked = [
            'transfer_number',
            'tenant_id',
            'facility_id',
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

        $result = [];
        foreach ($tracked as $field) {
            $result[$field] = $transfer[$field] ?? null;
        }

        return $result;
    }
}
