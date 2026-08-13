<?php

namespace App\Modules\InventoryProcurement\Application\UseCases;

use App\Modules\InventoryProcurement\Application\Exceptions\DuplicateInventoryWarehouseCodeException;
use App\Modules\InventoryProcurement\Domain\Repositories\InventoryWarehouseAuditLogRepositoryInterface;
use App\Modules\InventoryProcurement\Domain\Repositories\InventoryWarehouseRepositoryInterface;
use App\Modules\InventoryProcurement\Domain\ValueObjects\InventoryWarehouseStatus;
use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;

class CreateInventoryWarehouseUseCase
{
    public function __construct(
        private readonly InventoryWarehouseRepositoryInterface $inventoryWarehouseRepository,
        private readonly InventoryWarehouseAuditLogRepositoryInterface $auditLogRepository,
        private readonly CurrentPlatformScopeContextInterface $platformScopeContext,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    public function execute(array $payload, ?int $actorId = null): array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $tenantId = $this->platformScopeContext->tenantId();
        $facilityId = $this->platformScopeContext->facilityId();
        $warehouseCode = $this->normalizeWarehouseCode((string) $payload['warehouse_code']);

        if ($this->inventoryWarehouseRepository->existsByWarehouseCodeInScope($warehouseCode, $tenantId, $facilityId)) {
            throw new DuplicateInventoryWarehouseCodeException('Warehouse code already exists for the current scope.');
        }

        $created = $this->inventoryWarehouseRepository->create([
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'warehouse_code' => $warehouseCode,
            'warehouse_name' => trim((string) $payload['warehouse_name']),
            'warehouse_type' => $this->nullableTrimmedValue($payload['warehouse_type'] ?? null),
            'location' => $this->nullableTrimmedValue($payload['location'] ?? null),
            'contact_person' => $this->nullableTrimmedValue($payload['contact_person'] ?? null),
            'phone' => $this->nullableTrimmedValue($payload['phone'] ?? null),
            'email' => $this->nullableTrimmedValue($payload['email'] ?? null),
            'status' => InventoryWarehouseStatus::ACTIVE->value,
            'status_reason' => null,
            'notes' => $this->nullableTrimmedValue($payload['notes'] ?? null),
        ]);

        $this->auditLogRepository->write(
            inventoryWarehouseId: $created['id'],
            action: 'inventory-warehouse.created',
            actorId: $actorId,
            changes: [
                'after' => $this->extractTrackedFields($created),
            ],
        );

        return $created;
    }

    private function normalizeWarehouseCode(string $value): string
    {
        return strtoupper(trim($value));
    }

    private function nullableTrimmedValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    /**
     * @return array<string, mixed>
     */
    private function extractTrackedFields(array $warehouse): array
    {
        $tracked = [
            'tenant_id',
            'facility_id',
            'warehouse_code',
            'warehouse_name',
            'warehouse_type',
            'location',
            'contact_person',
            'phone',
            'email',
            'status',
            'status_reason',
            'notes',
        ];

        $result = [];
        foreach ($tracked as $field) {
            $result[$field] = $warehouse[$field] ?? null;
        }

        return $result;
    }
}

