<?php

namespace App\Modules\InventoryProcurement\Application\UseCases;

use App\Modules\InventoryProcurement\Application\Exceptions\DuplicateInventoryWarehouseCodeException;
use App\Modules\InventoryProcurement\Domain\Repositories\InventoryWarehouseAuditLogRepositoryInterface;
use App\Modules\InventoryProcurement\Domain\Repositories\InventoryWarehouseRepositoryInterface;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;

class UpdateInventoryWarehouseUseCase
{
    public function __construct(
        private readonly InventoryWarehouseRepositoryInterface $inventoryWarehouseRepository,
        private readonly InventoryWarehouseAuditLogRepositoryInterface $auditLogRepository,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    public function execute(string $id, array $payload, ?int $actorId = null): ?array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $existing = $this->inventoryWarehouseRepository->findById($id);
        if (! $existing) {
            return null;
        }

        $updatePayload = [];

        if (array_key_exists('warehouse_code', $payload)) {
            $warehouseCode = $this->normalizeWarehouseCode((string) $payload['warehouse_code']);

            if ($this->inventoryWarehouseRepository->existsByWarehouseCodeInScope(
                warehouseCode: $warehouseCode,
                tenantId: $existing['tenant_id'] ?? null,
                facilityId: $existing['facility_id'] ?? null,
                excludeId: $id,
            )) {
                throw new DuplicateInventoryWarehouseCodeException('Warehouse code already exists for the current scope.');
            }

            $updatePayload['warehouse_code'] = $warehouseCode;
        }

        if (array_key_exists('warehouse_name', $payload)) {
            $updatePayload['warehouse_name'] = trim((string) $payload['warehouse_name']);
        }

        if (array_key_exists('warehouse_type', $payload)) {
            $updatePayload['warehouse_type'] = $this->nullableTrimmedValue($payload['warehouse_type']);
        }

        if (array_key_exists('location', $payload)) {
            $updatePayload['location'] = $this->nullableTrimmedValue($payload['location']);
        }

        if (array_key_exists('contact_person', $payload)) {
            $updatePayload['contact_person'] = $this->nullableTrimmedValue($payload['contact_person']);
        }

        if (array_key_exists('phone', $payload)) {
            $updatePayload['phone'] = $this->nullableTrimmedValue($payload['phone']);
        }

        if (array_key_exists('email', $payload)) {
            $updatePayload['email'] = $this->nullableTrimmedValue($payload['email']);
        }

        if (array_key_exists('notes', $payload)) {
            $updatePayload['notes'] = $this->nullableTrimmedValue($payload['notes']);
        }

        $updated = $this->inventoryWarehouseRepository->update($id, $updatePayload);
        if (! $updated) {
            return null;
        }

        $changes = $this->extractChanges($existing, $updated);
        if ($changes !== []) {
            $this->auditLogRepository->write(
                inventoryWarehouseId: $id,
                action: 'inventory-warehouse.updated',
                actorId: $actorId,
                changes: $changes,
            );
        }

        return $updated;
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
    private function extractChanges(array $before, array $after): array
    {
        $trackedFields = [
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

