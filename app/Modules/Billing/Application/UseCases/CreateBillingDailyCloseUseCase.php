<?php

namespace App\Modules\Billing\Application\UseCases;

use App\Modules\Billing\Domain\Repositories\BillingDailyCloseRepositoryInterface;
use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;

class CreateBillingDailyCloseUseCase
{
    public function __construct(
        private readonly BillingDailyCloseRepositoryInterface $repository,
        private readonly CurrentPlatformScopeContextInterface $platformScopeContext,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    public function execute(array $payload, ?int $actorId = null): array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $scope = $this->platformScopeContext->current();

        $totalCash = (float) ($payload['total_cash_amount'] ?? 0);
        $totalCard = (float) ($payload['total_card_amount'] ?? 0);
        $totalMpesa = (float) ($payload['total_mpesa_amount'] ?? 0);
        $totalOther = (float) ($payload['total_other_amount'] ?? 0);
        $totalRefunds = (float) ($payload['total_refunds'] ?? 0);

        $totalRevenue = $totalCash + $totalCard + $totalMpesa + $totalOther;
        $netRevenue = round($totalRevenue - $totalRefunds, 2);

        $attributes = [
            'tenant_id' => $scope['tenant_id'] ?? $payload['tenant_id'] ?? null,
            'facility_id' => $scope['facility_id'] ?? $payload['facility_id'] ?? null,
            'closed_by_user_id' => $actorId,
            'closed_at' => $payload['closed_at'],
            'opened_at' => $payload['opened_at'],
            'total_cash_amount' => $totalCash,
            'total_card_amount' => $totalCard,
            'total_mpesa_amount' => $totalMpesa,
            'total_other_amount' => $totalOther,
            'total_revenue' => $totalRevenue,
            'total_refunds' => $totalRefunds,
            'net_revenue' => $netRevenue,
            'notes' => $payload['notes'] ?? null,
            'status' => 'draft',
        ];

        return $this->repository->create($attributes);
    }
}
