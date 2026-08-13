<?php

namespace App\Modules\Department\Application\UseCases;

use App\Modules\Department\Domain\Repositories\DepartmentAuditLogRepositoryInterface;
use App\Modules\Department\Domain\Repositories\DepartmentRepositoryInterface;
use App\Modules\Department\Domain\ValueObjects\DepartmentStatus;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;

class UpdateDepartmentStatusUseCase
{
    public function __construct(
        private readonly DepartmentRepositoryInterface $departmentRepository,
        private readonly DepartmentAuditLogRepositoryInterface $auditLogRepository,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    public function execute(string $id, string $status, ?string $reason, ?int $actorId = null): ?array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $existing = $this->departmentRepository->findById($id);
        if (! $existing) {
            return null;
        }

        $updated = $this->departmentRepository->update($id, [
            'status' => $status,
            'status_reason' => $reason,
        ]);
        if (! $updated) {
            return null;
        }

        $reasonRequired = $status === DepartmentStatus::INACTIVE->value;

        $this->auditLogRepository->write(
            departmentId: $id,
            action: 'department.status.updated',
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
            ],
            metadata: [
                'transition' => [
                    'from' => $existing['status'] ?? null,
                    'to' => $updated['status'] ?? null,
                ],
                'reason_required' => $reasonRequired,
                'reason_provided' => trim((string) ($updated['status_reason'] ?? '')) !== '',
            ],
        );

        return $updated;
    }
}
