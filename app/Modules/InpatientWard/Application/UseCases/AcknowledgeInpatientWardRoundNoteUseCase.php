<?php

namespace App\Modules\InpatientWard\Application\UseCases;

use App\Modules\InpatientWard\Application\Exceptions\InpatientWardRoundNoteNotEligibleForAcknowledgementException;
use App\Modules\InpatientWard\Domain\Repositories\InpatientWardRoundNoteRepositoryInterface;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;

class AcknowledgeInpatientWardRoundNoteUseCase
{
    public function __construct(
        private readonly InpatientWardRoundNoteRepositoryInterface $roundNoteRepository,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    public function execute(string $id, ?int $actorId = null): ?array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $existing = $this->roundNoteRepository->findById($id);
        if (! $existing) {
            return null;
        }

        if (trim((string) ($existing['handoff_notes'] ?? '')) === '') {
            throw new InpatientWardRoundNoteNotEligibleForAcknowledgementException(
                'This round note does not contain handoff guidance to acknowledge.',
            );
        }

        if (($existing['acknowledged_at'] ?? null) !== null) {
            return $existing;
        }

        return $this->roundNoteRepository->acknowledge($id, [
            'acknowledged_by_user_id' => $actorId,
            'acknowledged_at' => now(),
        ]);
    }
}
