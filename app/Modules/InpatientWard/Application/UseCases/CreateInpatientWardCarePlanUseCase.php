<?php

namespace App\Modules\InpatientWard\Application\UseCases;

use App\Modules\InpatientWard\Application\Exceptions\InpatientWardAdmissionNotFoundException;
use App\Modules\InpatientWard\Domain\Repositories\InpatientWardCarePlanAuditLogRepositoryInterface;
use App\Modules\InpatientWard\Domain\Repositories\InpatientWardCarePlanRepositoryInterface;
use App\Modules\InpatientWard\Domain\Repositories\InpatientWardCensusRepositoryInterface;
use App\Modules\InpatientWard\Domain\ValueObjects\InpatientWardCarePlanStatus;
use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;
use Illuminate\Support\Str;
use RuntimeException;

class CreateInpatientWardCarePlanUseCase
{
    public function __construct(
        private readonly InpatientWardCensusRepositoryInterface $censusRepository,
        private readonly InpatientWardCarePlanRepositoryInterface $carePlanRepository,
        private readonly InpatientWardCarePlanAuditLogRepositoryInterface $auditLogRepository,
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
            'care_plan_number' => $this->generateCarePlanNumber(),
            'tenant_id' => $this->platformScopeContext->tenantId(),
            'facility_id' => $this->platformScopeContext->facilityId(),
            'admission_id' => $admission['id'],
            'patient_id' => $admission['patient_id'],
            'title' => $payload['title'],
            'plan_text' => $payload['plan_text'] ?? null,
            'goals' => $payload['goals'] ?? null,
            'interventions' => $payload['interventions'] ?? null,
            'target_discharge_at' => $payload['target_discharge_at'] ?? null,
            'review_due_at' => $payload['review_due_at'] ?? null,
            'status' => InpatientWardCarePlanStatus::ACTIVE->value,
            'status_reason' => null,
            'author_user_id' => $actorId,
            'last_updated_by_user_id' => $actorId,
            'metadata' => $payload['metadata'] ?? null,
        ];

        $created = $this->carePlanRepository->create($createPayload);

        $this->auditLogRepository->write(
            inpatientWardCarePlanId: $created['id'],
            action: 'inpatient-ward-care-plan.created',
            actorId: $actorId,
            changes: [
                'after' => $created,
            ],
        );

        return $created;
    }

    private function generateCarePlanNumber(): string
    {
        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $candidate = 'WCP'.now()->format('Ymd').strtoupper(Str::random(5));
            if (! $this->carePlanRepository->existsByCarePlanNumber($candidate)) {
                return $candidate;
            }
        }

        throw new RuntimeException('Unable to generate unique care plan number.');
    }
}

