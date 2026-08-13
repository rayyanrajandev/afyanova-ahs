<?php

namespace App\Modules\InpatientWard\Application\UseCases;

use App\Modules\InpatientWard\Domain\Repositories\InpatientWardTaskAuditLogRepositoryInterface;
use App\Modules\InpatientWard\Domain\Repositories\InpatientWardTaskRepositoryInterface;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;

class UpdateInpatientWardTaskUseCase
{
    public function __construct(
        private readonly InpatientWardTaskRepositoryInterface $taskRepository,
        private readonly InpatientWardTaskAuditLogRepositoryInterface $auditLogRepository,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    public function execute(string $id, array $payload, ?int $actorId = null): ?array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $existing = $this->taskRepository->findById($id);
        if (! $existing) {
            return null;
        }

        $updatePayload = [];
        $changes = [];

        if (array_key_exists('assigned_to_user_id', $payload)) {
            $updatePayload['assigned_to_user_id'] = $payload['assigned_to_user_id'];
            if (($existing['assigned_to_user_id'] ?? null) !== $payload['assigned_to_user_id']) {
                $changes['assigned_to_user_id'] = [
                    'before' => $existing['assigned_to_user_id'] ?? null,
                    'after' => $payload['assigned_to_user_id'],
                ];
            }
        }

        if (array_key_exists('due_at', $payload)) {
            $updatePayload['due_at'] = $payload['due_at'];
            if (($existing['due_at'] ?? null) !== $payload['due_at']) {
                $changes['due_at'] = [
                    'before' => $existing['due_at'] ?? null,
                    'after' => $payload['due_at'],
                ];
            }
        }

        if ($updatePayload === []) {
            return $existing;
        }

        $updated = $this->taskRepository->update($id, $updatePayload);
        if (! $updated) {
            return null;
        }

        if ($changes !== []) {
            $this->auditLogRepository->write(
                inpatientWardTaskId: $id,
                action: 'inpatient-ward-task.updated',
                actorId: $actorId,
                changes: $changes,
                metadata: [
                    'assignment_changed' => array_key_exists('assigned_to_user_id', $changes),
                    'due_at_changed' => array_key_exists('due_at', $changes),
                ],
            );
        }

        return $updated;
    }
}