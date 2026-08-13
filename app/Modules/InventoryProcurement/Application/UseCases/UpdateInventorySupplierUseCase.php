<?php

namespace App\Modules\InventoryProcurement\Application\UseCases;

use App\Modules\InventoryProcurement\Application\Exceptions\DuplicateInventorySupplierCodeException;
use App\Modules\InventoryProcurement\Domain\Repositories\InventorySupplierAuditLogRepositoryInterface;
use App\Modules\InventoryProcurement\Domain\Repositories\InventorySupplierRepositoryInterface;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;

class UpdateInventorySupplierUseCase
{
    public function __construct(
        private readonly InventorySupplierRepositoryInterface $inventorySupplierRepository,
        private readonly InventorySupplierAuditLogRepositoryInterface $auditLogRepository,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    public function execute(string $id, array $payload, ?int $actorId = null): ?array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $existing = $this->inventorySupplierRepository->findById($id);
        if (! $existing) {
            return null;
        }

        $updatePayload = [];

        if (array_key_exists('supplier_code', $payload)) {
            $supplierCode = $this->normalizeSupplierCode((string) $payload['supplier_code']);

            if ($this->inventorySupplierRepository->existsBySupplierCodeInScope(
                supplierCode: $supplierCode,
                tenantId: $existing['tenant_id'] ?? null,
                facilityId: $existing['facility_id'] ?? null,
                excludeId: $id,
            )) {
                throw new DuplicateInventorySupplierCodeException('Supplier code already exists for the current scope.');
            }

            $updatePayload['supplier_code'] = $supplierCode;
        }

        if (array_key_exists('supplier_name', $payload)) {
            $updatePayload['supplier_name'] = trim((string) $payload['supplier_name']);
        }

        if (array_key_exists('tin_number', $payload)) {
            $updatePayload['tin_number'] = $this->nullableTrimmedValue($payload['tin_number']);
        }

        if (array_key_exists('bank_account_number', $payload)) {
            $updatePayload['bank_account_number'] = $this->nullableTrimmedValue($payload['bank_account_number']);
        }

        if (array_key_exists('lipa_number', $payload)) {
            $updatePayload['lipa_number'] = $this->nullableTrimmedValue($payload['lipa_number']);
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

        if (array_key_exists('address_line', $payload)) {
            $updatePayload['address_line'] = $this->nullableTrimmedValue($payload['address_line']);
        }

        if (array_key_exists('country_code', $payload)) {
            $updatePayload['country_code'] = $this->nullableUpperValue($payload['country_code']);
        }

        if (array_key_exists('notes', $payload)) {
            $updatePayload['notes'] = $this->nullableTrimmedValue($payload['notes']);
        }

        $updated = $this->inventorySupplierRepository->update($id, $updatePayload);
        if (! $updated) {
            return null;
        }

        $changes = $this->extractChanges($existing, $updated);
        if ($changes !== []) {
            $this->auditLogRepository->write(
                inventorySupplierId: $id,
                action: 'inventory-supplier.updated',
                actorId: $actorId,
                changes: $changes,
            );
        }

        return $updated;
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
    private function extractChanges(array $before, array $after): array
    {
        $trackedFields = [
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
