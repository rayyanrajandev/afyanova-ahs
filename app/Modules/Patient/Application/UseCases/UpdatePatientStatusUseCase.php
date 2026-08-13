<?php

namespace App\Modules\Patient\Application\UseCases;

use App\Modules\Patient\Application\Services\PatientDuplicateDetectionService;
use App\Modules\Patient\Domain\Repositories\PatientAuditLogRepositoryInterface;
use App\Modules\Patient\Domain\Repositories\PatientRepositoryInterface;
use App\Modules\Patient\Domain\ValueObjects\PatientStatus;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;

class UpdatePatientStatusUseCase
{
    public function __construct(
        private readonly PatientRepositoryInterface $patientRepository,
        private readonly PatientAuditLogRepositoryInterface $auditLogRepository,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
        private readonly PatientDuplicateDetectionService $duplicateDetectionService,
    ) {}

    public function execute(string $id, string $status, ?string $reason, ?int $actorId = null): ?array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $before = $this->patientRepository->findById($id);
        if (! $before) {
            return null;
        }

        if ($status === PatientStatus::ACTIVE->value) {
            $this->duplicateDetectionService->evaluate($before, $id);
        }

        $updated = $this->patientRepository->update($id, [
            'status' => $status,
            'status_reason' => $reason,
        ]);

        if (! $updated) {
            return null;
        }

        $reasonRequired = $status === PatientStatus::INACTIVE->value;

        $this->auditLogRepository->write(
            patientId: $id,
            action: 'patient.status.updated',
            actorId: $actorId,
            changes: [
                'status' => [
                    'before' => $before['status'] ?? null,
                    'after' => $updated['status'] ?? null,
                ],
                'status_reason' => [
                    'before' => $before['status_reason'] ?? null,
                    'after' => $updated['status_reason'] ?? null,
                ],
            ],
            metadata: [
                'transition' => [
                    'from' => $before['status'] ?? null,
                    'to' => $updated['status'] ?? null,
                ],
                'reason_required' => $reasonRequired,
                'reason_provided' => trim((string) ($updated['status_reason'] ?? '')) !== '',
            ],
        );

        return $updated;
    }
}
