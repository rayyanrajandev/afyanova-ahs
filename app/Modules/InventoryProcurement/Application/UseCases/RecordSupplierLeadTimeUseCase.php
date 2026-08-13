<?php

namespace App\Modules\InventoryProcurement\Application\UseCases;

use App\Modules\InventoryProcurement\Domain\Repositories\InventorySupplierLeadTimeRepositoryInterface;
use App\Modules\InventoryProcurement\Infrastructure\Models\InventorySupplierModel;
use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;

class RecordSupplierLeadTimeUseCase
{
    public function __construct(
        private readonly InventorySupplierLeadTimeRepositoryInterface $leadTimeRepository,
        private readonly CurrentPlatformScopeContextInterface $platformScopeContext,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    public function execute(array $payload): array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $orderDate = $payload['order_date'];
        $expectedDeliveryDate = $payload['expected_delivery_date'] ?? null;
        $expectedLeadTimeDays = null;

        if ($expectedDeliveryDate && $orderDate) {
            $expectedLeadTimeDays = (int) \Carbon\Carbon::parse($orderDate)
                ->diffInDays(\Carbon\Carbon::parse($expectedDeliveryDate));
        }

        $record = $this->leadTimeRepository->create([
            'tenant_id' => $this->platformScopeContext->tenantId(),
            'facility_id' => $this->platformScopeContext->facilityId(),
            'supplier_id' => $payload['supplier_id'],
            'item_id' => $payload['item_id'] ?? null,
            'procurement_request_id' => $payload['procurement_request_id'] ?? null,
            'order_date' => $orderDate,
            'expected_delivery_date' => $expectedDeliveryDate,
            'expected_lead_time_days' => $expectedLeadTimeDays,
            'quantity_ordered' => $payload['quantity_ordered'] ?? null,
            'delivery_status' => 'pending',
            'notes' => $payload['notes'] ?? null,
        ]);

        return $record;
    }
}
