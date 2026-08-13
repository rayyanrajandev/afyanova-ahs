<?php

namespace App\Modules\InventoryProcurement\Application\UseCases;

use App\Modules\InventoryProcurement\Domain\Repositories\InventorySupplierLeadTimeRepositoryInterface;
use App\Modules\InventoryProcurement\Infrastructure\Models\InventorySupplierModel;

class RecordSupplierDeliveryUseCase
{
    public function __construct(
        private readonly InventorySupplierLeadTimeRepositoryInterface $leadTimeRepository,
    ) {}

    public function execute(string $leadTimeId, array $payload): array
    {
        $record = $this->leadTimeRepository->recordDelivery($leadTimeId, [
            'actual_delivery_date' => $payload['actual_delivery_date'],
            'quantity_received' => $payload['quantity_received'] ?? null,
        ]);

        if (! $record) {
            throw new \RuntimeException('Lead time record not found.');
        }

        // Recalculate supplier summary stats
        $this->recalculateSupplierStats($record['supplier_id']);

        return $record;
    }

    private function recalculateSupplierStats(string $supplierId): void
    {
        $avgLeadTime = $this->leadTimeRepository->averageLeadTime($supplierId);
        $avgFulfillment = $this->leadTimeRepository->averageFulfillmentRate($supplierId);

        $supplier = InventorySupplierModel::find($supplierId);
        if (! $supplier) {
            return;
        }

        $totalDeliveries = \DB::table('inventory_supplier_lead_times')
            ->where('supplier_id', $supplierId)
            ->whereNotNull('actual_delivery_date')
            ->count();

        $onTimeDeliveries = \DB::table('inventory_supplier_lead_times')
            ->where('supplier_id', $supplierId)
            ->where('delivery_status', 'on_time')
            ->count();

        $supplier->update([
            'avg_lead_time_days' => $avgLeadTime,
            'avg_fulfillment_rate' => $avgFulfillment,
            'total_deliveries' => $totalDeliveries,
            'on_time_deliveries' => $onTimeDeliveries,
            'lead_time_last_calculated_at' => now(),
        ]);
    }
}
