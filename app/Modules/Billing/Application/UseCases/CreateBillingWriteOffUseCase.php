<?php

namespace App\Modules\Billing\Application\UseCases;

use App\Modules\Billing\Domain\Repositories\BillingWriteOffRepositoryInterface;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;

class CreateBillingWriteOffUseCase
{
    public function __construct(
        private readonly BillingWriteOffRepositoryInterface $repository,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    public function execute(array $payload, ?int $actorId = null): array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $attributes = array_merge($payload, [
            'created_by_user_id' => $actorId,
            'status' => 'pending',
        ]);

        return $this->repository->create($attributes);
    }
}
