<?php

namespace App\Modules\Admission\Application\UseCases;

use App\Modules\Admission\Domain\Repositories\AdmissionAuditLogRepositoryInterface;
use App\Modules\Admission\Domain\Repositories\AdmissionRepositoryInterface;
use App\Modules\Admission\Domain\Services\AdmissionPlacementLookupServiceInterface;
use App\Modules\Admission\Domain\ValueObjects\AdmissionStatus;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;

class UpdateAdmissionStatusUseCase
{
    public function __construct(
        private readonly AdmissionAuditLogRepositoryInterface $auditLogRepository,
        private readonly AdmissionRepositoryInterface $admissionRepository,
        private readonly AdmissionPlacementLookupServiceInterface $admissionPlacementLookupService,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    public function execute(
        string $id,
        string $status,
        ?string $reason,
        ?string $dischargeDestination = null,
        ?string $followUpPlan = null,
        ?string $receivingWard = null,
        ?string $receivingBed = null,
        ?string $receivingBedResourceId = null,
        ?int $actorId = null,
    ): ?array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $existing = $this->admissionRepository->findById($id);
        if (! $existing) {
            return null;
        }

        $payload = [
            'status' => $status,
            'status_reason' => $reason,
        ];

        if ($status === AdmissionStatus::TRANSFERRED->value) {
            $normalizedReceivingBedResourceId = trim((string) $receivingBedResourceId);

            if ($normalizedReceivingBedResourceId !== '') {
                $normalizedPlacement = $this->admissionPlacementLookupService->validatePlacementByResource(
                    bedResourceId: $normalizedReceivingBedResourceId,
                    fieldName: 'receivingBedResourceId',
                    excludeAdmissionId: $id,
                );

                $payload['bed_resource_id'] = $normalizedPlacement['bed_resource_id'];
                $payload['ward'] = $normalizedPlacement['ward'];
                $payload['bed'] = $normalizedPlacement['bed'];
            } else {
                $normalizedPlacement = $this->admissionPlacementLookupService->validatePlacement(
                    ward: $receivingWard,
                    bed: $receivingBed,
                    wardField: 'receivingWard',
                    bedField: 'receivingBed',
                    excludeAdmissionId: $id,
                );

                $payload['bed_resource_id'] = null;
                $payload['ward'] = $normalizedPlacement['ward'];
                $payload['bed'] = $normalizedPlacement['bed'];
            }
        }

        if ($status === AdmissionStatus::DISCHARGED->value) {
            $payload['discharged_at'] = now();
            $payload['discharge_destination'] = $dischargeDestination;
            $payload['follow_up_plan'] = $followUpPlan;
        }

        $updated = $this->admissionRepository->update($id, $payload);
        if (! $updated) {
            return null;
        }

        $reasonRequired = in_array($status, [
            AdmissionStatus::DISCHARGED->value,
            AdmissionStatus::TRANSFERRED->value,
            AdmissionStatus::CANCELLED->value,
        ], true);

        $this->auditLogRepository->write(
            admissionId: $id,
            action: 'admission.status.updated',
            actorId: $actorId,
            changes: [
                'status' => [
                    'before' => $existing['status'] ?? null,
                    'after' => $updated['status'] ?? null,
                ],
                'status_reason' => [
                    'before' => $existing['status_reason'] ?? null,
                    'after' => $updated['status_reason'] ?? null,
                ],
                'discharged_at' => [
                    'before' => $existing['discharged_at'] ?? null,
                    'after' => $updated['discharged_at'] ?? null,
                ],
                'discharge_destination' => [
                    'before' => $existing['discharge_destination'] ?? null,
                    'after' => $updated['discharge_destination'] ?? null,
                ],
                'follow_up_plan' => [
                    'before' => $existing['follow_up_plan'] ?? null,
                    'after' => $updated['follow_up_plan'] ?? null,
                ],
                'ward' => [
                    'before' => $existing['ward'] ?? null,
                    'after' => $updated['ward'] ?? null,
                ],
                'bed' => [
                    'before' => $existing['bed'] ?? null,
                    'after' => $updated['bed'] ?? null,
                ],
                'bed_resource_id' => [
                    'before' => $existing['bed_resource_id'] ?? null,
                    'after' => $updated['bed_resource_id'] ?? null,
                ],
            ],
            metadata: [
                'transition' => [
                    'from' => $existing['status'] ?? null,
                    'to' => $updated['status'] ?? null,
                ],
                'reason_required' => $reasonRequired,
                'reason_provided' => trim((string) ($updated['status_reason'] ?? '')) !== '',
                'receiving_placement' => $status === AdmissionStatus::TRANSFERRED->value
                    ? [
                        'ward' => $updated['ward'] ?? null,
                        'bed' => $updated['bed'] ?? null,
                    ]
                    : null,
                'discharge_handoff' => $status === AdmissionStatus::DISCHARGED->value
                    ? [
                        'destination' => $updated['discharge_destination'] ?? null,
                        'follow_up_plan' => $updated['follow_up_plan'] ?? null,
                    ]
                    : null,
            ],
        );

        return $updated;
    }
}

