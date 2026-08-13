<?php

namespace App\Modules\InventoryProcurement\Application\UseCases;

use App\Modules\InventoryProcurement\Application\Exceptions\InventoryProcurementWorkflowException;
use App\Modules\InventoryProcurement\Domain\Repositories\InventoryProcurementRequestAuditLogRepositoryInterface;
use App\Modules\InventoryProcurement\Domain\Repositories\InventoryProcurementRequestRepositoryInterface;
use App\Modules\InventoryProcurement\Domain\ValueObjects\InventoryProcurementRequestStatus;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;

class PlaceInventoryProcurementOrderUseCase
{
    public function __construct(
        private readonly InventoryProcurementRequestRepositoryInterface $inventoryProcurementRequestRepository,
        private readonly InventoryProcurementRequestAuditLogRepositoryInterface $auditLogRepository,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    public function execute(string $id, array $payload, ?int $actorId = null): ?array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $existing = $this->inventoryProcurementRequestRepository->findById($id);
        if (! $existing) {
            return null;
        }

        if (($existing['status'] ?? null) !== InventoryProcurementRequestStatus::APPROVED->value) {
            throw new InventoryProcurementWorkflowException('Only approved requests can be placed as purchase orders.');
        }

        $orderedQuantity = (float) $payload['ordered_quantity'];
        $unitCostEstimate = array_key_exists('unit_cost_estimate', $payload) && $payload['unit_cost_estimate'] !== null
            ? (float) $payload['unit_cost_estimate']
            : null;

        $updatePayload = [
            'purchase_order_number' => trim((string) $payload['purchase_order_number']),
            'ordered_quantity' => $orderedQuantity,
            'status' => InventoryProcurementRequestStatus::ORDERED->value,
            'status_reason' => null,
            'ordered_at' => now(),
            'needed_by' => $payload['needed_by'] ?? ($existing['needed_by'] ?? null),
            'supplier_id' => array_key_exists('supplier_id', $payload) ? ($payload['supplier_id'] ?? null) : ($existing['supplier_id'] ?? null),
            'notes' => $payload['notes'] ?? ($existing['notes'] ?? null),
        ];

        if ($unitCostEstimate !== null) {
            $updatePayload['unit_cost_estimate'] = $unitCostEstimate;
            $updatePayload['total_cost_estimate'] = $orderedQuantity * $unitCostEstimate;
        }

        $updated = $this->inventoryProcurementRequestRepository->update($id, $updatePayload);
        if (! $updated) {
            return null;
        }

        $changes = $this->extractChanges($existing, $updated);
        $this->auditLogRepository->write(
            inventoryProcurementRequestId: $id,
            action: 'inventory-procurement-request.ordered',
            actorId: $actorId,
            changes: $changes === [] ? ['after' => $updated] : $changes,
            metadata: [
                'transition' => [
                    'from' => $existing['status'] ?? null,
                    'to' => $updated['status'] ?? null,
                ],
                'workflow_status_required' => InventoryProcurementRequestStatus::APPROVED->value,
                'workflow_status_satisfied' => ($existing['status'] ?? null) === InventoryProcurementRequestStatus::APPROVED->value,
                'purchase_order_required' => true,
                'purchase_order_provided' => trim((string) ($updated['purchase_order_number'] ?? '')) !== '',
                'ordered_quantity_required' => true,
                'ordered_quantity_provided' => ($updated['ordered_quantity'] ?? null) !== null,
                'unit_cost_estimate_provided' => ($updated['unit_cost_estimate'] ?? null) !== null,
                'needed_by_provided' => ($updated['needed_by'] ?? null) !== null,
            ],
        );

        return $updated;
    }

    /**
     * @return array<string, mixed>
     */
    private function extractChanges(array $before, array $after): array
    {
        $trackedFields = [
            'purchase_order_number',
            'ordered_quantity',
            'unit_cost_estimate',
            'total_cost_estimate',
            'status',
            'status_reason',
            'ordered_at',
            'needed_by',
            'notes',
        ];

        $changes = [];
        foreach ($trackedFields as $field) {
            $beforeValue = $before[$field] ?? null;
            $afterValue = $after[$field] ?? null;
            if ($beforeValue === $afterValue) {
                continue;
            }

            $changes[$field] = [
                'before' => $beforeValue,
                'after' => $afterValue,
            ];
        }

        return $changes;
    }
}
