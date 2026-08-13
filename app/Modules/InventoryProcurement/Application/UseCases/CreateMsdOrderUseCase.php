<?php

namespace App\Modules\InventoryProcurement\Application\UseCases;

use App\Modules\InventoryProcurement\Domain\Repositories\InventoryMsdOrderRepositoryInterface;
use App\Modules\InventoryProcurement\Domain\Services\MsdApiClientInterface;
use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;

class CreateMsdOrderUseCase
{
    public function __construct(
        private readonly InventoryMsdOrderRepositoryInterface $msdOrderRepository,
        private readonly MsdApiClientInterface $msdApiClient,
        private readonly CurrentPlatformScopeContextInterface $platformScopeContext,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    public function execute(array $payload, ?string $actorId, bool $submitImmediately = false): array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $orderNumber = 'MSD-' . now()->format('Ymd') . '-' . strtoupper(substr(md5(uniqid()), 0, 5));

        $order = $this->msdOrderRepository->create([
            'tenant_id' => $this->platformScopeContext->tenantId(),
            'facility_id' => $this->platformScopeContext->facilityId(),
            'msd_order_number' => $orderNumber,
            'facility_msd_code' => $payload['facility_msd_code'] ?? null,
            'procurement_request_id' => $payload['procurement_request_id'] ?? null,
            'supplier_id' => $payload['supplier_id'] ?? null,
            'order_lines' => $payload['order_lines'],
            'currency_code' => $payload['currency_code'] ?? 'TZS',
            'total_amount' => $payload['total_amount'] ?? null,
            'order_date' => $payload['order_date'],
            'expected_delivery_date' => $payload['expected_delivery_date'] ?? null,
            'status' => 'draft',
            'notes' => $payload['notes'] ?? null,
            'created_by_user_id' => $actorId,
        ]);

        if ($submitImmediately) {
            return $this->submitToMsd($order);
        }

        return $order;
    }

    private function submitToMsd(array $order): array
    {
        $response = $this->msdApiClient->submitOrder([
            'facility_msd_code' => $order['facility_msd_code'] ?? '',
            'order_lines' => $order['order_lines'] ?? [],
            'order_date' => $order['order_date'],
            'notes' => $order['notes'] ?? null,
        ]);

        $updateData = [
            'api_response_log' => $response,
        ];

        if ($response['success'] ?? false) {
            $updateData['status'] = 'submitted';
            $updateData['submitted_at'] = now()->toIso8601String();
            $updateData['submission_reference'] = $response['submission_reference'] ?? null;
        }

        return $this->msdOrderRepository->update($order['id'], $updateData) ?? $order;
    }
}
