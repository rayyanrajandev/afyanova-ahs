<?php

namespace App\Modules\InventoryProcurement\Application\UseCases;

use App\Modules\InventoryProcurement\Application\Exceptions\DuplicateInventoryItemCodeException;
use App\Modules\InventoryProcurement\Domain\Repositories\InventoryItemAuditLogRepositoryInterface;
use App\Modules\InventoryProcurement\Domain\Repositories\InventoryItemRepositoryInterface;
use App\Modules\InventoryProcurement\Domain\Services\CatalogIdentityResolver;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;
use App\Modules\Platform\Infrastructure\Models\ClinicalCatalogItemModel;
use App\Support\CatalogGovernance\InventoryClinicalLinkGuard;
use App\Support\CatalogGovernance\StandardsCodeSupport;

class UpdateInventoryItemUseCase
{
    public function __construct(
        private readonly InventoryItemRepositoryInterface $inventoryItemRepository,
        private readonly InventoryItemAuditLogRepositoryInterface $auditLogRepository,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
        private readonly InventoryClinicalLinkGuard $clinicalLinkGuard,
        private readonly StandardsCodeSupport $standardsCodeSupport,
        private readonly CatalogIdentityResolver $catalogIdentityResolver,
    ) {}

    public function execute(string $id, array $payload, ?int $actorId = null): ?array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $existing = $this->inventoryItemRepository->findById($id);
        if (! $existing) {
            return null;
        }

        if (! empty($existing['clinical_catalog_item_id'])) {
            $identityFields = ['item_name', 'generic_name', 'dosage_form', 'strength', 'unit', 'dispensing_unit', 'codes', 'subcategory'];
            $changedIdentityFields = array_intersect($identityFields, array_keys($payload));
            if ($changedIdentityFields !== []) {
                throw new \InvalidArgumentException(
                    'Identity fields cannot be modified when the item is linked to a Clinical Catalog entry. '
                    .'Update the source Clinical Catalog item instead.'
                );
            }
        }

        // When linked to a clinical catalog, identity fields are owned by the catalog.
        // Strip them from the update payload and read from the catalog item instead.
        $effectiveCatalogId = $payload['clinical_catalog_item_id']
            ?? $existing['clinical_catalog_item_id']
            ?? null;

        $catalogIdentity = $effectiveCatalogId !== null
            ? $this->resolveCatalogIdentity((string) $effectiveCatalogId)
            : null;

        $updatePayload = [];

        if (array_key_exists('item_code', $payload)) {
            $itemCode = $this->normalizeItemCode((string) $payload['item_code']);
            if ($this->inventoryItemRepository->existsByItemCode($itemCode, $id)) {
                throw new DuplicateInventoryItemCodeException('Item code already exists.');
            }

            $updatePayload['item_code'] = $itemCode;
        }

        // Identity fields: when catalog-linked, always read from catalog; otherwise allow user input
        if ($catalogIdentity !== null) {
            $updatePayload['item_name'] = $catalogIdentity['item_name'];
            // generic_name/dosage_form/strength dropped from inventory_items in Phase 3 --
            // always read from the Clinical Catalog relation now, nothing to write here.
            $updatePayload['unit'] = $catalogIdentity['unit'];
            $updatePayload['dispensing_unit'] = $catalogIdentity['dispensing_unit'];
            $updatePayload['codes'] = $catalogIdentity['codes'];
            $updatePayload['subcategory'] = $catalogIdentity['subcategory'];
            // conversion_factor has no structured catalog column (still metadata-only,
            // see CatalogIdentityResolver) but is otherwise treated the same as the
            // fields above: the catalog wins whenever it has an opinion, re-syncing any
            // drift on every save, and only the payload's value is used as a fallback
            // when the catalog doesn't define one for this drug yet.
            if ($catalogIdentity['conversion_factor'] !== null) {
                $updatePayload['conversion_factor'] = $catalogIdentity['conversion_factor'];
            } elseif (array_key_exists('conversion_factor', $payload)) {
                $updatePayload['conversion_factor'] = $this->nullableNumericValue($payload['conversion_factor']);
            }
        } else {
            if (array_key_exists('item_name', $payload)) {
                $updatePayload['item_name'] = trim((string) $payload['item_name']);
            }

            if (array_key_exists('codes', $payload)) {
                $updatePayload['codes'] = $this->standardsCodeSupport->normalize(is_array($payload['codes']) ? $payload['codes'] : null);
            }

            if (array_key_exists('unit', $payload)) {
                $updatePayload['unit'] = trim((string) $payload['unit']);
            }

            if (array_key_exists('conversion_factor', $payload)) {
                $updatePayload['conversion_factor'] = $this->nullableNumericValue($payload['conversion_factor']);
            }
        }

        if (array_key_exists('category', $payload)) {
            $updatePayload['category'] = $this->nullableTrimmedValue($payload['category']);
        }

        // storage_conditions stays on inventory_items (see CreateInventoryItemUseCase's
        // comment) -- controlled_substance_schedule dropped in Phase 3, catalog-only now.
        $nullableStringFields = [
            'clinical_catalog_item_id',
            'msd_code', 'nhif_code', 'barcode',
            'ven_classification', 'abc_classification',
            'bin_location', 'manufacturer', 'storage_conditions',
            'default_warehouse_id', 'default_supplier_id',
        ];
        foreach ($nullableStringFields as $field) {
            if (array_key_exists($field, $payload)) {
                $updatePayload[$field] = $this->nullableTrimmedValue($payload[$field]);
            }
        }

        // requires_cold_chain stays (see above); is_controlled_substance dropped in
        // Phase 3, catalog-only now.
        $booleanFields = ['requires_cold_chain'];
        foreach ($booleanFields as $field) {
            if (array_key_exists($field, $payload)) {
                $updatePayload[$field] = (bool) $payload[$field];
            }
        }

        if (array_key_exists('reorder_level', $payload)) {
            $updatePayload['reorder_level'] = (float) $payload['reorder_level'];
        }

        if (array_key_exists('max_stock_level', $payload)) {
            $updatePayload['max_stock_level'] = $this->nullableNumericValue($payload['max_stock_level']);
        }

        $this->clinicalLinkGuard->assertPayloadCanPersist($updatePayload, $existing);

        $updated = $this->inventoryItemRepository->update($id, $updatePayload);
        if (! $updated) {
            return null;
        }

        $changes = $this->extractChanges($existing, $updated);
        if ($changes !== []) {
            $this->auditLogRepository->write(
                inventoryItemId: $id,
                action: 'inventory-item.updated',
                actorId: $actorId,
                changes: $changes,
            );
        }

        return $updated;
    }

    private function normalizeItemCode(string $value): string
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

    private function nullableNumericValue(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (float) $value;
    }

    /**
     * Read identity fields from the clinical catalog item to avoid duplicating data.
     *
     * @return array<string, mixed>
     */
    private function resolveCatalogIdentity(string $clinicalCatalogItemId): array
    {
        $catalogItem = ClinicalCatalogItemModel::query()->find($clinicalCatalogItemId);

        if ($catalogItem === null) {
            return [];
        }

        return $this->catalogIdentityResolver->resolve($catalogItem);
    }

    /**
     * @return array<string, mixed>
     */
    private function extractChanges(array $before, array $after): array
    {
        $trackedFields = [
            'item_code',
            'clinical_catalog_item_id',
            'codes',
            'item_name',
            'category',
            'unit',
            'reorder_level',
            'max_stock_level',
            'status',
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
