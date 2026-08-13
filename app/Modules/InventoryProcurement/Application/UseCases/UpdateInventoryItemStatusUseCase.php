<?php

namespace App\Modules\InventoryProcurement\Application\UseCases;

use App\Modules\InventoryProcurement\Domain\Repositories\InventoryItemAuditLogRepositoryInterface;
use App\Modules\InventoryProcurement\Domain\Repositories\InventoryItemRepositoryInterface;
use App\Modules\InventoryProcurement\Domain\ValueObjects\InventoryItemStatus;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;

class UpdateInventoryItemStatusUseCase
{
    public function __construct(
        private readonly InventoryItemRepositoryInterface $inventoryItemRepository,
        private readonly InventoryItemAuditLogRepositoryInterface $auditLogRepository,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    public function execute(string $id, string $status, ?string $reason, ?int $actorId = null): ?array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $existing = $this->inventoryItemRepository->findById($id);
        if (! $existing) {
            return null;
        }

        $normalizedReason = trim((string) $reason);
        if ($normalizedReason === '') {
            $normalizedReason = null;
        }

        if ($status !== InventoryItemStatus::INACTIVE->value) {
            $normalizedReason = null;
        }

        $updated = $this->inventoryItemRepository->update($id, [
            'status' => $status,
            'status_reason' => $normalizedReason,
        ]);
        if (! $updated) {
            return null;
        }

        $statusChanged = ($existing['status'] ?? null) !== ($updated['status'] ?? null);
        $reasonChanged = ($existing['status_reason'] ?? null) !== ($updated['status_reason'] ?? null);

        if ($statusChanged || $reasonChanged) {
            $reasonRequired = $status === InventoryItemStatus::INACTIVE->value;

            $this->auditLogRepository->write(
                inventoryItemId: $id,
                action: 'inventory-item.status.updated',
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
                ],
                metadata: [
                    'reason' => $updated['status_reason'] ?? null,
                    'transition' => [
                        'from' => $existing['status'] ?? null,
                        'to' => $updated['status'] ?? null,
                    ],
                    'reason_required' => $reasonRequired,
                    'reason_provided' => trim((string) ($updated['status_reason'] ?? '')) !== '',
                ],
            );
        }

        return $updated;
    }
}
