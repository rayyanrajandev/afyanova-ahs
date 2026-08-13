<?php

namespace App\Modules\Billing\Application\UseCases;

use App\Modules\Billing\Domain\Repositories\BillingWriteOffRepositoryInterface;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;
use RuntimeException;

class ApproveBillingWriteOffUseCase
{
    public function __construct(
        private readonly BillingWriteOffRepositoryInterface $repository,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    public function execute(string $id, string $status, ?string $notes, ?int $actorId = null): array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $existing = $this->repository->findById($id);

        if ($existing === null) {
            throw new RuntimeException('Write-off not found.');
        }

        if ($existing['status'] !== 'pending') {
            throw new RuntimeException('Write-off is not in pending status.');
        }

        $attributes = [
            'status' => $status,
            'approved_by_user_id' => $actorId,
            'approved_at' => now()->toISOString(),
        ];

        if ($notes !== null) {
            $attributes['notes'] = $notes;
        }

        $updated = $this->repository->update($id, $attributes);

        if ($updated === null) {
            throw new RuntimeException('Failed to update write-off.');
        }

        return $updated;
    }
}
