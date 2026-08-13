<?php

namespace App\Modules\InventoryProcurement\Application\UseCases;

use App\Modules\InventoryProcurement\Application\Exceptions\InventoryItemNotFoundException;
use App\Modules\InventoryProcurement\Application\Exceptions\InventoryProcurementWorkflowException;
use App\Modules\InventoryProcurement\Application\Exceptions\InventoryWarehouseNotFoundException;
use App\Modules\InventoryProcurement\Application\Services\InventoryBatchStockService;
use App\Modules\InventoryProcurement\Domain\Repositories\InventoryItemRepositoryInterface;
use App\Modules\InventoryProcurement\Domain\Repositories\InventoryProcurementRequestAuditLogRepositoryInterface;
use App\Modules\InventoryProcurement\Domain\Repositories\InventoryProcurementRequestRepositoryInterface;
use App\Modules\InventoryProcurement\Domain\Repositories\InventoryWarehouseRepositoryInterface;
use App\Modules\InventoryProcurement\Domain\ValueObjects\InventoryProcurementRequestStatus;
use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;
use Illuminate\Support\Facades\DB;

class ReceiveInventoryProcurementRequestUseCase
{
    public function __construct(
        private readonly InventoryProcurementRequestRepositoryInterface $inventoryProcurementRequestRepository,
        private readonly InventoryProcurementRequestAuditLogRepositoryInterface $auditLogRepository,
        private readonly InventoryItemRepositoryInterface $inventoryItemRepository,
        private readonly InventoryWarehouseRepositoryInterface $inventoryWarehouseRepository,
        private readonly InventoryBatchStockService $inventoryBatchStockService,
        private readonly CurrentPlatformScopeContextInterface $platformScopeContext,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    public function execute(string $id, array $payload, ?int $actorId = null): ?array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $existing = $this->inventoryProcurementRequestRepository->findById($id);
        if (! $existing) {
            return null;
        }

        if (($existing['status'] ?? null) !== InventoryProcurementRequestStatus::ORDERED->value) {
            throw new InventoryProcurementWorkflowException('Only ordered requests can be received.');
        }

        $itemId = (string) ($existing['item_id'] ?? '');
        $item = $this->inventoryItemRepository->findById($itemId);
        if (! $item) {
            throw new InventoryItemNotFoundException('Inventory item not found for procurement receipt.');
        }

        $warehouseId = $payload['receiving_warehouse_id'] ?? null;
        if (is_string($warehouseId) && trim($warehouseId) !== '') {
            $warehouse = $this->inventoryWarehouseRepository->findById($warehouseId);
            if (! $warehouse) {
                throw new InventoryWarehouseNotFoundException('Warehouse not found for goods receipt.');
            }
        } else {
            $warehouseId = null;
        }

        $receivedQuantity = (float) $payload['received_quantity'];
        $orderedQuantity = (float) ($existing['ordered_quantity'] ?? 0);
        if ($orderedQuantity > 0 && $receivedQuantity > $orderedQuantity) {
            throw new InventoryProcurementWorkflowException('Received quantity cannot exceed ordered quantity.');
        }

        $receivedUnitCost = array_key_exists('received_unit_cost', $payload) && $payload['received_unit_cost'] !== null
            ? (float) $payload['received_unit_cost']
            : null;

        return DB::transaction(function () use (
            $actorId,
            $existing,
            $id,
            $item,
            $itemId,
            $orderedQuantity,
            $payload,
            $receivedQuantity,
            $receivedUnitCost,
            $warehouseId,
        ): ?array {
            $movement = $this->inventoryBatchStockService->receive([
                'tenant_id' => $this->platformScopeContext->tenantId(),
                'facility_id' => $this->platformScopeContext->facilityId(),
                'item_id' => $itemId,
                'procurement_request_id' => $id,
                'source_supplier_id' => $existing['supplier_id'] ?? ($item['default_supplier_id'] ?? null),
                'destination_warehouse_id' => $warehouseId,
                'unit_id' => $payload['unit_id'] ?? null,
                'unit' => $payload['unit'] ?? null,
                'quantity' => $receivedQuantity,
                'received_unit_cost' => $receivedUnitCost,
                'batch_number' => $payload['batch_number'] ?? null,
                'lot_number' => $payload['lot_number'] ?? null,
                'manufacture_date' => $payload['manufacture_date'] ?? null,
                'expiry_date' => $payload['expiry_date'] ?? null,
                'bin_location' => $payload['bin_location'] ?? null,
                'reason' => $payload['reason'] ?? 'Goods receipt',
                'notes' => $payload['notes'] ?? null,
                'metadata' => [
                    'requestNumber' => $existing['request_number'] ?? null,
                    'purchaseOrderNumber' => $existing['purchase_order_number'] ?? null,
                ],
                'occurred_at' => $payload['occurred_at'] ?? now(),
            ], $actorId);

            $updatePayload = [
                'status' => InventoryProcurementRequestStatus::RECEIVED->value,
                'status_reason' => $payload['reason'] ?? null,
                'received_at' => now(),
                'received_quantity' => $receivedQuantity,
                'received_unit_cost' => $receivedUnitCost,
                'receiving_warehouse_id' => $warehouseId,
                'receiving_notes' => $payload['notes'] ?? null,
            ];

            if ($receivedUnitCost !== null) {
                $updatePayload['total_cost_estimate'] = $receivedUnitCost * $receivedQuantity;
            }

            if (! isset($existing['ordered_quantity']) || $existing['ordered_quantity'] === null) {
                $updatePayload['ordered_quantity'] = (float) ($existing['requested_quantity'] ?? $receivedQuantity);
            }

            $updated = $this->inventoryProcurementRequestRepository->update($id, $updatePayload);
            if (! $updated) {
                throw new InventoryProcurementWorkflowException('Unable to finalize procurement receipt.');
            }

            $movementMetadata = is_array($movement['metadata'] ?? null) ? $movement['metadata'] : [];
            $batch = is_array($movement['batch'] ?? null) ? $movement['batch'] : [];

            $this->auditLogRepository->write(
                inventoryProcurementRequestId: $id,
                action: 'inventory-procurement-request.received',
                actorId: $actorId,
                changes: [
                    'status' => [
                        'before' => $existing['status'] ?? null,
                        'after' => $updated['status'] ?? null,
                    ],
                    'received_quantity' => [
                        'before' => $existing['received_quantity'] ?? null,
                        'after' => $updated['received_quantity'] ?? null,
                    ],
                    'received_unit_cost' => [
                        'before' => $existing['received_unit_cost'] ?? null,
                        'after' => $updated['received_unit_cost'] ?? null,
                    ],
                    'received_at' => [
                        'before' => $existing['received_at'] ?? null,
                        'after' => $updated['received_at'] ?? null,
                    ],
                    'receiving_warehouse_id' => [
                        'before' => $existing['receiving_warehouse_id'] ?? null,
                        'after' => $updated['receiving_warehouse_id'] ?? null,
                    ],
                ],
                metadata: [
                    'stockMovementId' => $movement['id'] ?? null,
                    'transition' => [
                        'from' => $existing['status'] ?? null,
                        'to' => $updated['status'] ?? null,
                    ],
                    'workflow_status_required' => InventoryProcurementRequestStatus::ORDERED->value,
                    'workflow_status_satisfied' => ($existing['status'] ?? null) === InventoryProcurementRequestStatus::ORDERED->value,
                    'ordered_quantity_ceiling' => $orderedQuantity > 0 ? $orderedQuantity : null,
                    'received_quantity_submitted' => $receivedQuantity,
                    'received_quantity_within_ordered' => ! ($orderedQuantity > 0 && $receivedQuantity > $orderedQuantity),
                    'received_timestamp_required' => true,
                    'received_timestamp_provided' => ($updated['received_at'] ?? null) !== null,
                    'received_unit_cost_provided' => ($updated['received_unit_cost'] ?? null) !== null,
                    'warehouse_provided' => ($updated['receiving_warehouse_id'] ?? null) !== null,
                    'stock_before' => $movement['stock_before'] ?? null,
                    'stock_after' => $movement['stock_after'] ?? null,
                    'batch_tracking_mode' => $movementMetadata['batchMode'] ?? null,
                    'batch_id' => $batch['id'] ?? null,
                    'batch_number' => $batch['batch_number'] ?? null,
                    'lot_number' => $batch['lot_number'] ?? null,
                    'expiry_date' => $batch['expiry_date'] ?? null,
                    'manufacture_date' => $batch['manufacture_date'] ?? null,
                ],
            );

            return $updated;
        });
    }
}
