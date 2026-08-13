<?php

namespace App\Modules\InventoryProcurement\Application\UseCases;

use App\Modules\InventoryProcurement\Application\Exceptions\InventoryProcurementWorkflowException;
use App\Modules\InventoryProcurement\Application\Services\DepartmentStockService;
use App\Modules\InventoryProcurement\Domain\Repositories\InventoryDepartmentRequisitionAuditLogRepositoryInterface;
use App\Modules\InventoryProcurement\Domain\Repositories\InventoryDepartmentRequisitionLineRepositoryInterface;
use App\Modules\InventoryProcurement\Domain\Repositories\InventoryDepartmentRequisitionRepositoryInterface;
use App\Modules\InventoryProcurement\Domain\Repositories\InventoryItemRepositoryInterface;
use App\Modules\InventoryProcurement\Domain\Repositories\InventoryStockMovementRepositoryInterface;
use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;

class UpdateInventoryDepartmentRequisitionStatusUseCase
{
    private const ALLOWED_TRANSITIONS = [
        'draft' => ['submitted', 'cancelled'],
        'submitted' => ['approved', 'rejected', 'cancelled'],
        'approved' => ['partially_issued', 'issued', 'cancelled'],
        'partially_issued' => ['partially_issued', 'issued', 'cancelled'],
    ];

    public function __construct(
        private readonly InventoryDepartmentRequisitionRepositoryInterface $requisitionRepository,
        private readonly InventoryDepartmentRequisitionLineRepositoryInterface $lineRepository,
        private readonly InventoryDepartmentRequisitionAuditLogRepositoryInterface $auditLogRepository,
        private readonly InventoryItemRepositoryInterface $itemRepository,
        private readonly InventoryStockMovementRepositoryInterface $stockMovementRepository,
        private readonly CurrentPlatformScopeContextInterface $platformScopeContext,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
        private readonly DepartmentStockService $departmentStockService,
    ) {}

    public function execute(string $id, string $newStatus, array $payload, ?int $actorId = null): ?array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $existing = $this->requisitionRepository->findById($id);
        if (! $existing) {
            return null;
        }

        $currentStatus = $existing['status'];
        $allowedTargets = self::ALLOWED_TRANSITIONS[$currentStatus] ?? [];

        if (! in_array($newStatus, $allowedTargets, true)) {
            throw new InventoryProcurementWorkflowException(
                sprintf('Cannot transition from "%s" to "%s".', $currentStatus, $newStatus)
            );
        }

        $updateData = ['status' => $newStatus];
        $lines = $this->lineRepository->listByRequisitionId($id);
        $linePayloadById = collect($payload['lines'] ?? [])
            ->filter(fn (array $line): bool => isset($line['id']))
            ->keyBy('id');

        if ($newStatus === 'approved') {
            $updateData['approved_by_user_id'] = $actorId;
            $updateData['approved_at'] = now();
            $approvedLineUpdates = [];

            foreach ($lines as $line) {
                $lineUpdate = $linePayloadById->get($line['id'], []);
                $approvedQuantity = $lineUpdate['approved_quantity'] ?? $line['requested_quantity'];
                $requestedQuantity = (float) $line['requested_quantity'];

                if ((float) $approvedQuantity > $requestedQuantity) {
                    throw new InventoryProcurementWorkflowException(
                        'Approved quantity cannot exceed requested quantity for '.$this->lineLabel($line).'.'
                    );
                }

                $approvedLineUpdates[] = [
                    'id' => $line['id'],
                    'approved_quantity' => (float) $approvedQuantity,
                ];
            }

            foreach ($approvedLineUpdates as $lineUpdate) {
                $this->lineRepository->update($lineUpdate['id'], [
                    'approved_quantity' => $lineUpdate['approved_quantity'],
                ]);
            }
        }

        if ($newStatus === 'rejected') {
            $updateData['rejection_reason'] = $payload['rejection_reason'] ?? null;
        }

        if (in_array($newStatus, ['issued', 'partially_issued'], true)) {
            $updateData['issued_by_user_id'] = $actorId;
            $updateData['issued_at'] = now();
            $issueLineUpdates = [];

            foreach ($lines as $line) {
                $lineUpdate = $linePayloadById->get($line['id'], []);
                $issuedQuantity = $lineUpdate['issued_quantity']
                    ?? $line['approved_quantity']
                    ?? $line['requested_quantity'];
                $approvedQuantity = (float) ($line['approved_quantity'] ?? $line['requested_quantity']);
                $previousIssuedQuantity = (float) ($line['issued_quantity'] ?? 0);
                $issuedQuantity = (float) $issuedQuantity;

                if ($issuedQuantity < $previousIssuedQuantity) {
                    throw new InventoryProcurementWorkflowException(
                        'Issued quantity cannot be reduced for '.$this->lineLabel($line).'.'
                    );
                }

                if ($issuedQuantity > $approvedQuantity) {
                    throw new InventoryProcurementWorkflowException(
                        'Issued quantity cannot exceed approved quantity for '.$this->lineLabel($line).'.'
                    );
                }

                if ($newStatus === 'issued' && $issuedQuantity < $approvedQuantity) {
                    throw new InventoryProcurementWorkflowException(
                        'Use a partial issue when issued quantity is less than approved quantity.'
                    );
                }

                $additionalIssueQuantity = $issuedQuantity - $previousIssuedQuantity;
                $item = null;
                $beforeStock = 0.0;
                $afterStock = 0.0;

                if ($additionalIssueQuantity > 0) {
                    $item = $this->itemRepository->findById($line['item_id']);
                    if (! $item) {
                        throw new InventoryProcurementWorkflowException(
                            'Cannot issue '.$this->lineLabel($line).' because the inventory item no longer exists.'
                        );
                    }

                    $beforeStock = (float) ($item['current_stock'] ?? 0);
                    if ($additionalIssueQuantity > $beforeStock) {
                        throw new InventoryProcurementWorkflowException(
                            sprintf(
                                'Cannot issue %s of %s. Only %s available in stock.',
                                $this->formatQuantity($additionalIssueQuantity),
                                $this->lineLabel($line),
                                $this->formatQuantity($beforeStock)
                            )
                        );
                    }

                    $afterStock = $beforeStock - $additionalIssueQuantity;
                }

                $issueLineUpdates[] = [
                    'line' => $line,
                    'approved_quantity' => $approvedQuantity,
                    'issued_quantity' => $issuedQuantity,
                    'additional_issue_quantity' => $additionalIssueQuantity,
                    'item' => $item,
                    'stock_before' => $beforeStock,
                    'stock_after' => $afterStock,
                ];
            }

            foreach ($issueLineUpdates as $lineUpdate) {
                $line = $lineUpdate['line'];

                $this->lineRepository->update($line['id'], [
                    'approved_quantity' => $lineUpdate['approved_quantity'],
                    'issued_quantity' => $lineUpdate['issued_quantity'],
                ]);

                if ($lineUpdate['additional_issue_quantity'] > 0 && $lineUpdate['item']) {
                    $delta = -1 * $lineUpdate['additional_issue_quantity'];

                    $this->itemRepository->update($line['item_id'], ['current_stock' => $lineUpdate['stock_after']]);

                    $this->stockMovementRepository->create([
                        'tenant_id' => $this->platformScopeContext->tenantId(),
                        'facility_id' => $this->platformScopeContext->facilityId(),
                        'item_id' => $line['item_id'],
                        'batch_id' => $line['batch_id'] ?? null,
                        'source_warehouse_id' => $existing['issuing_warehouse_id'] ?? null,
                        'destination_department_id' => $existing['requesting_department_id'] ?? null,
                        'source_type' => 'inventory_department_requisition',
                        'source_id' => $id,
                        'movement_type' => 'issue',
                        'quantity' => $lineUpdate['additional_issue_quantity'],
                        'quantity_delta' => $delta,
                        'stock_before' => $lineUpdate['stock_before'],
                        'stock_after' => $lineUpdate['stock_after'],
                        'reason' => 'Department requisition '.$existing['requisition_number'],
                        'actor_id' => $actorId,
                        'metadata' => [
                            'source' => 'department_requisition',
                            'requisition_id' => $id,
                            'requisition_number' => $existing['requisition_number'],
                            'department' => $existing['requesting_department'],
                            'requesting_department_id' => $existing['requesting_department_id'] ?? null,
                            'issuing_warehouse_id' => $existing['issuing_warehouse_id'] ?? null,
                        ],
                    'occurred_at' => now(),
                    'created_at' => now(),
                ]);

                $this->departmentStockService->recordIssue(
                    tenantId: $this->platformScopeContext->tenantId(),
                    facilityId: $this->platformScopeContext->facilityId(),
                    departmentId: $existing['requesting_department_id'] ?? '',
                    itemId: $line['item_id'],
                    quantity: $lineUpdate['additional_issue_quantity'],
                    batchId: $line['batch_id'] ?? null,
                    unit: $line['unit'] ?? null,
                    source: 'department_requisition',
                    sourceId: $id,
                    actorId: $actorId,
                    notes: 'Department requisition '.$existing['requisition_number'],
                );
            }
            }
        }

        $updated = $this->requisitionRepository->update($id, $updateData);
        if (! $updated) {
            return null;
        }

        $updated['lines'] = $this->lineRepository->listByRequisitionId($id);

        $this->auditLogRepository->write(
            requisitionId: $id,
            action: 'department-requisition.status-changed',
            actorId: $actorId,
            changes: [
                'before' => ['status' => $currentStatus],
                'after' => ['status' => $newStatus],
            ],
        );

        return $updated;
    }

    private function lineLabel(array $line): string
    {
        return (string) ($line['item_name'] ?? $line['item_code'] ?? $line['item_id'] ?? 'requisition line');
    }

    private function formatQuantity(float $quantity): string
    {
        return rtrim(rtrim(number_format($quantity, 3, '.', ''), '0'), '.');
    }
}
