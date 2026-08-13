<?php

namespace App\Modules\InventoryProcurement\Application\UseCases;

use App\Modules\InventoryProcurement\Domain\Repositories\InventoryProcurementRequestAuditLogRepositoryInterface;
use App\Modules\InventoryProcurement\Domain\Repositories\InventoryProcurementRequestRepositoryInterface;
use App\Modules\InventoryProcurement\Domain\ValueObjects\InventoryProcurementRequestStatus;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;

class UpdateInventoryProcurementRequestStatusUseCase
{
    public function __construct(
        private readonly InventoryProcurementRequestRepositoryInterface $inventoryProcurementRequestRepository,
        private readonly InventoryProcurementRequestAuditLogRepositoryInterface $auditLogRepository,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    public function execute(string $id, string $status, ?string $reason, ?int $actorId = null): ?array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $existing = $this->inventoryProcurementRequestRepository->findById($id);
        if (! $existing) {
            return null;
        }

        $payload = [
            'status' => $status,
            'status_reason' => $reason,
        ];

        if ($status === InventoryProcurementRequestStatus::APPROVED->value) {
            $payload['approved_at'] = now();
            $payload['approved_by_user_id'] = $actorId;
        }
        if ($status === InventoryProcurementRequestStatus::ORDERED->value) {
            $payload['ordered_at'] = now();
        }
        if ($status === InventoryProcurementRequestStatus::RECEIVED->value) {
            $payload['received_at'] = now();
        }

        $updated = $this->inventoryProcurementRequestRepository->update($id, $payload);
        if (! $updated) {
            return null;
        }

        $reasonRequired = in_array($status, [
            InventoryProcurementRequestStatus::REJECTED->value,
            InventoryProcurementRequestStatus::CANCELLED->value,
        ], true);

        $this->auditLogRepository->write(
            inventoryProcurementRequestId: $id,
            action: 'inventory-procurement-request.status.updated',
            actorId: $actorId,
            changes: [
                'status' => [
                    'before' => $existing['status'] ?? null,
                    'after' => $updated['status'] ?? null,
                ],
                'status_reason' => [
                    'before' => $existing['status_reason'] ?? null,
                    'after' => $updated['status_reason'] ?? null,
                ],
                'approved_at' => [
                    'before' => $existing['approved_at'] ?? null,
                    'after' => $updated['approved_at'] ?? null,
                ],
                'ordered_at' => [
                    'before' => $existing['ordered_at'] ?? null,
                    'after' => $updated['ordered_at'] ?? null,
                ],
                'received_at' => [
                    'before' => $existing['received_at'] ?? null,
                    'after' => $updated['received_at'] ?? null,
                ],
            ],
            metadata: [
                'transition' => [
                    'from' => $existing['status'] ?? null,
                    'to' => $updated['status'] ?? null,
                ],
                'reason_required' => $reasonRequired,
                'reason_provided' => trim((string) ($updated['status_reason'] ?? '')) !== '',
                'approval_timestamp_required' => $status === InventoryProcurementRequestStatus::APPROVED->value,
                'approval_timestamp_provided' => ($updated['approved_at'] ?? null) !== null,
                'approved_by_required' => $status === InventoryProcurementRequestStatus::APPROVED->value,
                'approved_by_provided' => ($updated['approved_by_user_id'] ?? null) !== null,
                'order_timestamp_required' => $status === InventoryProcurementRequestStatus::ORDERED->value,
                'order_timestamp_provided' => ($updated['ordered_at'] ?? null) !== null,
                'receipt_timestamp_required' => $status === InventoryProcurementRequestStatus::RECEIVED->value,
                'receipt_timestamp_provided' => ($updated['received_at'] ?? null) !== null,
            ],
        );

        return $updated;
    }
}
