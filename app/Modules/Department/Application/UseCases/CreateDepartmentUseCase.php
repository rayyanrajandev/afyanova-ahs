<?php

namespace App\Modules\Department\Application\UseCases;

use App\Modules\Department\Application\Exceptions\DuplicateDepartmentCodeException;
use App\Modules\Department\Domain\Repositories\DepartmentAuditLogRepositoryInterface;
use App\Modules\Department\Domain\Repositories\DepartmentRepositoryInterface;
use App\Modules\Department\Domain\ValueObjects\DepartmentStatus;
use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;

class CreateDepartmentUseCase
{
    public function __construct(
        private readonly DepartmentRepositoryInterface $departmentRepository,
        private readonly DepartmentAuditLogRepositoryInterface $auditLogRepository,
        private readonly CurrentPlatformScopeContextInterface $platformScopeContext,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    public function execute(array $payload, ?int $actorId = null): array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $tenantId = $this->platformScopeContext->tenantId();
        $facilityId = $this->platformScopeContext->facilityId();
        $code = $this->normalizeCode((string) $payload['code']);

        if ($this->departmentRepository->existsByCodeInScope($code, $tenantId, $facilityId)) {
            throw new DuplicateDepartmentCodeException('Department code already exists for the current scope.');
        }

        $isPatientFacing = (bool) ($payload['is_patient_facing'] ?? false);
        $isAppointmentable = (bool) ($payload['is_appointmentable'] ?? false);

        if ($isAppointmentable) {
            $isPatientFacing = true;
        }

        $createPayload = [
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'code' => $code,
            'name' => trim((string) $payload['name']),
            'service_type' => $this->nullableTrimmedValue($payload['service_type'] ?? null),
            'is_patient_facing' => $isPatientFacing,
            'is_appointmentable' => $isAppointmentable,
            'manager_user_id' => isset($payload['manager_user_id']) && $payload['manager_user_id'] !== null
                ? (int) $payload['manager_user_id']
                : null,
            'status' => DepartmentStatus::ACTIVE->value,
            'status_reason' => null,
            'description' => $this->nullableTrimmedValue($payload['description'] ?? null),
        ];

        $created = $this->departmentRepository->create($createPayload);

        $this->auditLogRepository->write(
            departmentId: $created['id'],
            action: 'department.created',
            actorId: $actorId,
            changes: [
                'after' => $this->extractTrackedFields($created),
            ],
        );

        return $created;
    }

    private function normalizeCode(string $value): string
    {
        return strtoupper(trim($value));
    }

    private function nullableTrimmedValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    /**
     * @return array<string, mixed>
     */
    private function extractTrackedFields(array $department): array
    {
        $tracked = [
            'tenant_id',
            'facility_id',
            'code',
            'name',
            'service_type',
            'is_patient_facing',
            'is_appointmentable',
            'manager_user_id',
            'status',
            'status_reason',
            'description',
        ];

        $result = [];
        foreach ($tracked as $field) {
            $result[$field] = $department[$field] ?? null;
        }

        return $result;
    }
}

