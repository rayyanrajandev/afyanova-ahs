<?php

namespace App\Modules\Department\Application\UseCases;

use App\Modules\Department\Application\Exceptions\DuplicateDepartmentCodeException;
use App\Modules\Department\Domain\Repositories\DepartmentAuditLogRepositoryInterface;
use App\Modules\Department\Domain\Repositories\DepartmentRepositoryInterface;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;

class UpdateDepartmentUseCase
{
    public function __construct(
        private readonly DepartmentRepositoryInterface $departmentRepository,
        private readonly DepartmentAuditLogRepositoryInterface $auditLogRepository,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    public function execute(string $id, array $payload, ?int $actorId = null): ?array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $existing = $this->departmentRepository->findById($id);
        if (! $existing) {
            return null;
        }

        $updatePayload = [];

        if (array_key_exists('code', $payload)) {
            $normalizedCode = $this->normalizeCode((string) $payload['code']);
            if ($this->departmentRepository->existsByCodeInScope(
                code: $normalizedCode,
                tenantId: $existing['tenant_id'] ?? null,
                facilityId: $existing['facility_id'] ?? null,
                excludeId: $id,
            )) {
                throw new DuplicateDepartmentCodeException('Department code already exists for the current scope.');
            }
            $updatePayload['code'] = $normalizedCode;
        }

        if (array_key_exists('name', $payload)) {
            $updatePayload['name'] = trim((string) $payload['name']);
        }

        if (array_key_exists('service_type', $payload)) {
            $updatePayload['service_type'] = $this->nullableTrimmedValue($payload['service_type']);
        }

        if (array_key_exists('manager_user_id', $payload)) {
            $updatePayload['manager_user_id'] = $payload['manager_user_id'] === null
                ? null
                : (int) $payload['manager_user_id'];
        }

        if (array_key_exists('description', $payload)) {
            $updatePayload['description'] = $this->nullableTrimmedValue($payload['description']);
        }

        if (array_key_exists('is_patient_facing', $payload)) {
            $updatePayload['is_patient_facing'] = (bool) $payload['is_patient_facing'];
        }

        if (array_key_exists('is_appointmentable', $payload)) {
            $updatePayload['is_appointmentable'] = (bool) $payload['is_appointmentable'];
        }

        $nextPatientFacing = array_key_exists('is_patient_facing', $updatePayload)
            ? (bool) $updatePayload['is_patient_facing']
            : (bool) ($existing['is_patient_facing'] ?? false);
        $nextAppointmentable = array_key_exists('is_appointmentable', $updatePayload)
            ? (bool) $updatePayload['is_appointmentable']
            : (bool) ($existing['is_appointmentable'] ?? false);

        if ($nextAppointmentable) {
            $updatePayload['is_patient_facing'] = true;
            $nextPatientFacing = true;
        }

        if (! $nextPatientFacing) {
            $updatePayload['is_appointmentable'] = false;
        }

        $updated = $this->departmentRepository->update($id, $updatePayload);
        if (! $updated) {
            return null;
        }

        $changes = $this->extractChanges($existing, $updated);
        if ($changes !== []) {
            $this->auditLogRepository->write(
                departmentId: $id,
                action: 'department.updated',
                actorId: $actorId,
                changes: $changes,
            );
        }

        return $updated;
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
    private function extractChanges(array $before, array $after): array
    {
        $trackedFields = [
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

