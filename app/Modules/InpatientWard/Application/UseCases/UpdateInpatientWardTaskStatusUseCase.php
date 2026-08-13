<?php

namespace App\Modules\InpatientWard\Application\UseCases;

use App\Modules\InpatientWard\Domain\Repositories\InpatientWardTaskAuditLogRepositoryInterface;
use App\Modules\InpatientWard\Domain\Repositories\InpatientWardTaskRepositoryInterface;
use App\Modules\InpatientWard\Domain\ValueObjects\InpatientWardTaskStatus;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;

class UpdateInpatientWardTaskStatusUseCase
{
    public function __construct(
        private readonly InpatientWardTaskRepositoryInterface $taskRepository,
        private readonly InpatientWardTaskAuditLogRepositoryInterface $auditLogRepository,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    public function execute(string $id, string $status, ?string $reason, ?int $actorId = null): ?array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $existing = $this->taskRepository->findById($id);
        if (! $existing) {
            return null;
        }

        $payload = [
            'status' => $status,
            'status_reason' => $reason,
        ];

        if ($status === InpatientWardTaskStatus::IN_PROGRESS->value) {
            $payload['started_at'] = $existing['started_at'] ?? now();
            $payload['completed_at'] = null;
            $payload['escalated_at'] = null;
        }
        if ($status === InpatientWardTaskStatus::COMPLETED->value) {
            $payload['started_at'] = $existing['started_at'] ?? now();
            $payload['completed_at'] = now();
            $payload['escalated_at'] = null;
        }
        if ($status === InpatientWardTaskStatus::ESCALATED->value) {
            $payload['started_at'] = $existing['started_at'] ?? now();
            $payload['escalated_at'] = now();
            $payload['completed_at'] = null;
        }
        if ($status === InpatientWardTaskStatus::PENDING->value) {
            $payload['started_at'] = null;
            $payload['completed_at'] = null;
            $payload['escalated_at'] = null;
        }
        if ($status === InpatientWardTaskStatus::CANCELLED->value) {
            $payload['completed_at'] = null;
        }

        $updated = $this->taskRepository->update($id, $payload);
        if (! $updated) {
            return null;
        }

        $this->auditLogRepository->write(
            inpatientWardTaskId: $id,
            action: 'inpatient-ward-task.status.updated',
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
                'started_at' => [
                    'before' => $existing['started_at'] ?? null,
                    'after' => $updated['started_at'] ?? null,
                ],
                'completed_at' => [
                    'before' => $existing['completed_at'] ?? null,
                    'after' => $updated['completed_at'] ?? null,
                ],
                'escalated_at' => [
                    'before' => $existing['escalated_at'] ?? null,
                    'after' => $updated['escalated_at'] ?? null,
                ],
            ],
            metadata: [
                'transition' => [
                    'from' => $existing['status'] ?? null,
                    'to' => $updated['status'] ?? null,
                ],
                'completion_timestamp_required' => $status === InpatientWardTaskStatus::COMPLETED->value,
                'completion_timestamp_provided' => ($updated['completed_at'] ?? null) !== null,
                'escalation_reason_required' => $status === InpatientWardTaskStatus::ESCALATED->value,
                'escalation_reason_provided' => trim((string) ($updated['status_reason'] ?? '')) !== '',
                'cancellation_reason_required' => $status === InpatientWardTaskStatus::CANCELLED->value,
                'cancellation_reason_provided' => trim((string) ($updated['status_reason'] ?? '')) !== '',
            ],
        );

        return $updated;
    }
}
