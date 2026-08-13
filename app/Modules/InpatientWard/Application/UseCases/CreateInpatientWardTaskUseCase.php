<?php

namespace App\Modules\InpatientWard\Application\UseCases;

use App\Modules\InpatientWard\Application\Exceptions\InpatientWardAdmissionNotFoundException;
use App\Modules\InpatientWard\Domain\Repositories\InpatientWardCensusRepositoryInterface;
use App\Modules\InpatientWard\Domain\Repositories\InpatientWardTaskAuditLogRepositoryInterface;
use App\Modules\InpatientWard\Domain\Repositories\InpatientWardTaskRepositoryInterface;
use App\Modules\InpatientWard\Domain\ValueObjects\InpatientWardTaskStatus;
use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;
use Illuminate\Support\Str;
use RuntimeException;

class CreateInpatientWardTaskUseCase
{
    public function __construct(
        private readonly InpatientWardCensusRepositoryInterface $censusRepository,
        private readonly InpatientWardTaskRepositoryInterface $taskRepository,
        private readonly InpatientWardTaskAuditLogRepositoryInterface $auditLogRepository,
        private readonly CurrentPlatformScopeContextInterface $platformScopeContext,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    public function execute(array $payload, ?int $actorId = null): array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $admissionId = (string) $payload['admission_id'];
        $admission = $this->censusRepository->findCurrentAdmissionById($admissionId);
        if (! $admission) {
            throw new InpatientWardAdmissionNotFoundException(
                'Inpatient admission not found in current ward census.',
            );
        }

        $createPayload = [
            'task_number' => $this->generateTaskNumber(),
            'tenant_id' => $this->platformScopeContext->tenantId(),
            'facility_id' => $this->platformScopeContext->facilityId(),
            'admission_id' => $admission['id'],
            'patient_id' => $admission['patient_id'],
            'task_type' => $payload['task_type'],
            'title' => $payload['title'] ?? null,
            'priority' => $payload['priority'],
            'status' => InpatientWardTaskStatus::PENDING->value,
            'status_reason' => null,
            'assigned_to_user_id' => $payload['assigned_to_user_id'] ?? null,
            'created_by_user_id' => $actorId,
            'due_at' => $payload['due_at'] ?? null,
            'started_at' => null,
            'completed_at' => null,
            'escalated_at' => null,
            'notes' => $payload['notes'] ?? null,
            'metadata' => $payload['metadata'] ?? null,
        ];

        $created = $this->taskRepository->create($createPayload);

        $this->auditLogRepository->write(
            inpatientWardTaskId: $created['id'],
            action: 'inpatient-ward-task.created',
            actorId: $actorId,
            changes: [
                'after' => $created,
            ],
        );

        return $created;
    }

    private function generateTaskNumber(): string
    {
        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $candidate = 'WTK'.now()->format('Ymd').strtoupper(Str::random(5));
            if (! $this->taskRepository->existsByTaskNumber($candidate)) {
                return $candidate;
            }
        }

        throw new RuntimeException('Unable to generate unique ward task number.');
    }
}
