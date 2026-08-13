<?php

namespace App\Modules\InventoryProcurement\Application\UseCases;

use App\Modules\InventoryProcurement\Application\Exceptions\DuplicateInventorySupplierCodeException;
use App\Modules\InventoryProcurement\Domain\Repositories\InventorySupplierAuditLogRepositoryInterface;
use App\Modules\InventoryProcurement\Domain\Repositories\InventorySupplierRepositoryInterface;
use App\Modules\InventoryProcurement\Domain\ValueObjects\InventorySupplierStatus;
use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;

class CreateInventorySupplierUseCase
{
    public function __construct(
        private readonly InventorySupplierRepositoryInterface $inventorySupplierRepository,
        private readonly InventorySupplierAuditLogRepositoryInterface $auditLogRepository,
        private readonly CurrentPlatformScopeContextInterface $platformScopeContext,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    public function execute(array $payload, ?int $actorId = null): array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $tenantId = $this->platformScopeContext->tenantId();
        $facilityId = $this->platformScopeContext->facilityId();
        $supplierCode = $this->normalizeSupplierCode((string) $payload['supplier_code']);

        if ($this->inventorySupplierRepository->existsBySupplierCodeInScope($supplierCode, $tenantId, $facilityId)) {
            throw new DuplicateInventorySupplierCodeException('Supplier code already exists for the current scope.');
        }

        $created = $this->inventorySupplierRepository->create([
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'supplier_code' => $supplierCode,
            'supplier_name' => trim((string) $payload['supplier_name']),
            'tin_number' => $this->nullableTrimmedValue($payload['tin_number'] ?? null),
            'bank_account_number' => $this->nullableTrimmedValue($payload['bank_account_number'] ?? null),
            'lipa_number' => $this->nullableTrimmedValue($payload['lipa_number'] ?? null),
            'contact_person' => $this->nullableTrimmedValue($payload['contact_person'] ?? null),
            'phone' => $this->nullableTrimmedValue($payload['phone'] ?? null),
            'email' => $this->nullableTrimmedValue($payload['email'] ?? null),
            'address_line' => $this->nullableTrimmedValue($payload['address_line'] ?? null),
            'country_code' => $this->nullableUpperValue($payload['country_code'] ?? null),
            'status' => InventorySupplierStatus::ACTIVE->value,
            'status_reason' => null,
            'notes' => $this->nullableTrimmedValue($payload['notes'] ?? null),
        ]);

        $this->auditLogRepository->write(
            inventorySupplierId: $created['id'],
            action: 'inventory-supplier.created',
            actorId: $actorId,
            changes: [
                'after' => $this->extractTrackedFields($created),
            ],
        );

        return $created;
    }

    private function normalizeSupplierCode(string $value): string
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

    private function nullableUpperValue(mixed $value): ?string
    {
        $normalized = $this->nullableTrimmedValue($value);

        return $normalized === null ? null : strtoupper($normalized);
    }

    /**
     * @return array<string, mixed>
     */
    private function extractTrackedFields(array $supplier): array
    {
        $tracked = [
            'tenant_id',
            'facility_id',
            'supplier_code',
            'supplier_name',
            'tin_number',
            'bank_account_number',
            'lipa_number',
            'contact_person',
            'phone',
            'email',
            'address_line',
            'country_code',
            'status',
            'status_reason',
            'notes',
        ];

        $result = [];
        foreach ($tracked as $field) {
            $result[$field] = $supplier[$field] ?? null;
        }

        return $result;
    }
}
