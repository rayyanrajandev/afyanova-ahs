<?php

namespace App\Modules\InpatientWard\Application\UseCases;

use App\Modules\InpatientWard\Application\Exceptions\InpatientWardAdmissionNotFoundException;
use App\Modules\InpatientWard\Domain\Repositories\InpatientWardCensusRepositoryInterface;
use App\Modules\InpatientWard\Domain\Repositories\InpatientWardRoundNoteRepositoryInterface;
use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;

class CreateInpatientWardRoundNoteUseCase
{
    public function __construct(
        private readonly InpatientWardCensusRepositoryInterface $censusRepository,
        private readonly InpatientWardRoundNoteRepositoryInterface $roundNoteRepository,
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

        return $this->roundNoteRepository->create([
            'tenant_id' => $this->platformScopeContext->tenantId(),
            'facility_id' => $this->platformScopeContext->facilityId(),
            'admission_id' => $admission['id'],
            'patient_id' => $admission['patient_id'],
            'author_user_id' => $actorId,
            'rounded_at' => $payload['rounded_at'] ?? now(),
            'shift_label' => $payload['shift_label'] ?? null,
            'round_note' => $payload['round_note'],
            'care_plan' => $payload['care_plan'] ?? null,
            'handoff_notes' => $payload['handoff_notes'] ?? null,
            'acknowledged_by_user_id' => null,
            'acknowledged_at' => null,
            'metadata' => $payload['metadata'] ?? null,
        ]);
    }
}
