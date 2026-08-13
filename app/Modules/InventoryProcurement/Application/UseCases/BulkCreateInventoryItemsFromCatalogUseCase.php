<?php

namespace App\Modules\InventoryProcurement\Application\UseCases;

use App\Modules\InventoryProcurement\Domain\Repositories\InventoryItemAuditLogRepositoryInterface;
use App\Modules\InventoryProcurement\Domain\Repositories\InventoryItemRepositoryInterface;
use App\Modules\InventoryProcurement\Domain\Services\CatalogIdentityResolver;
use App\Modules\InventoryProcurement\Domain\ValueObjects\InventoryItemCategory;
use App\Modules\InventoryProcurement\Domain\ValueObjects\InventoryWarehouseStatus;
use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryItemModel;
use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryItemUnitModel;
use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryWarehouseModel;
use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use App\Modules\Platform\Domain\Services\FeatureFlagResolverInterface;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;
use App\Modules\Platform\Domain\ValueObjects\ClinicalCatalogItemStatus;
use App\Modules\Platform\Domain\ValueObjects\ClinicalCatalogType;
use App\Modules\Platform\Infrastructure\Models\ClinicalCatalogItemModel;
use App\Modules\Platform\Infrastructure\Support\PlatformScopeQueryApplier;
use App\Support\CatalogGovernance\InventoryClinicalLinkGuard;
use Illuminate\Validation\ValidationException;

class BulkCreateInventoryItemsFromCatalogUseCase
{
    public function __construct(
        private readonly InventoryItemRepositoryInterface $inventoryItemRepository,
        private readonly InventoryItemAuditLogRepositoryInterface $auditLogRepository,
        private readonly CurrentPlatformScopeContextInterface $platformScopeContext,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
        private readonly InventoryClinicalLinkGuard $clinicalLinkGuard,
        private readonly FeatureFlagResolverInterface $featureFlagResolver,
        private readonly CatalogIdentityResolver $catalogIdentityResolver,
    ) {}

    /**
     * Bulk-create inventory items from active formulary (clinical catalog) items,
     * and update existing linked items with the latest catalog data.
     *
     * Creates items for formulary entries that do NOT already have
     * a corresponding inventory item. Updates items that already have one.
     *
     * @param  list<string>|null  $catalogItemIds  Optional subset of catalog item IDs to sync.
     *                                             If null, syncs all eligible active formulary items.
     * @param  string|null  $defaultWarehouseId  Required default warehouse UUID for all created items.
     * @param  string|null  $defaultSupplierId  Default supplier UUID for all created items.
     * @param  list<string>|null  $catalogTypes  Optional subset of catalog types; null = formulary_item only
     * @return array{created: positive-int, updated: positive-int, errors: list<array{catalogItemId: string, code: string, name: string, error: string}>}
     */
    public function execute(
        ?array $catalogItemIds = null,
        ?string $defaultWarehouseId = null,
        ?string $defaultSupplierId = null,
        ?int $actorId = null,
        ?array $catalogTypes = null,
    ): array {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $tenantId = $this->platformScopeContext->tenantId();
        $facilityId = $this->platformScopeContext->facilityId();
        $defaultWarehouseId = trim((string) ($defaultWarehouseId ?? ''));
        if ($defaultWarehouseId === '') {
            throw ValidationException::withMessages([
                'defaultWarehouseId' => ['Choose a default warehouse before syncing catalog items to inventory.'],
            ]);
        }
        if (! $this->defaultWarehouseIsUsable($defaultWarehouseId)) {
            throw ValidationException::withMessages([
                'defaultWarehouseId' => ['The selected warehouse is not active or is outside the current facility scope.'],
            ]);
        }

        $catalogTypeFilter = (is_array($catalogTypes) && $catalogTypes !== [])
            ? $catalogTypes
            : [ClinicalCatalogType::FORMULARY_ITEM->value];

        // 1. Fetch eligible active formulary items from the clinical catalog
        $catalogQuery = ClinicalCatalogItemModel::query()
            ->select(['id', 'catalog_type', 'code', 'name', 'category', 'unit', 'description', 'metadata', 'codes', 'status', 'storage_conditions', 'requires_cold_chain'])
            ->whereIn('catalog_type', $catalogTypeFilter)
            ->where('status', ClinicalCatalogItemStatus::ACTIVE->value)
            ->orderBy('name');

        if ($catalogItemIds !== null && $catalogItemIds !== []) {
            $catalogQuery->whereIn('id', $catalogItemIds);
        }

        if ($this->isPlatformScopingEnabled()) {
            app(PlatformScopeQueryApplier::class)->apply($catalogQuery);
        }

        $catalogItems = $catalogQuery->get();

        if ($catalogItems->isEmpty()) {
            return [
                'created' => 0,
                'updated' => 0,
                'errors' => [],
            ];
        }

        // 2. Bulk preload: linked inventory items (1 query instead of N)
        $catalogItemIdsAll = $catalogItems->pluck('id')->map(fn ($id): string => (string) $id)->values()->all();
        $existingLinkedMap = $this->inventoryItemRepository->listLinkedByClinicalCatalogIds($catalogItemIdsAll);

        // 3. Bulk preload: existing item codes for uniqueness checks (1 query instead of N×while)
        $existingItemCodes = array_flip($this->inventoryItemRepository->listExistingItemCodes());

        // 4. Process each catalog item
        $created = 0;
        $updated = 0;
        $errors = [];

        foreach ($catalogItems as $catalogItem) {
            $catalogId = (string) $catalogItem->id;

            // generic_name/dosage_form/strength dropped from inventory_items in Phase 3
            // (Inventory_MasterData_Alignment_Plan.md) -- always read from the catalog
            // relation now, nothing to resolve or write onto the inventory row here.
            // Phase 9: unit/dispensing_unit/conversion_factor/codes derivation now
            // shared with CreateInventoryItemUseCase, UpdateInventoryItemUseCase, and
            // CatalogDownstreamSyncService via CatalogIdentityResolver.
            $identity = $this->catalogIdentityResolver->resolve($catalogItem);
            $stockUnit = $identity['unit'];
            $dispensingUnit = $identity['dispensing_unit'];
            $conversionFactor = $identity['conversion_factor'];
            $codes = $identity['codes'];

            try {
                if (isset($existingLinkedMap[$catalogId])) {
                    // Update existing linked inventory item
                    $existingItem = $existingLinkedMap[$catalogId];
                    $inventoryItem = InventoryItemModel::query()->find($existingItem['id']);

                    if ($inventoryItem === null) {
                        $errors[] = [
                            'catalogItemId' => $catalogId,
                            'code' => $catalogItem->code,
                            'name' => $catalogItem->name,
                            'error' => 'Linked inventory item not found by clinical_catalog_item_id.',
                        ];

                        continue;
                    }

                    $updatePayload = [
                        'codes' => $codes,
                        'item_name' => $catalogItem->name,
                        'subcategory' => $catalogItem->category,
                        'unit' => $stockUnit ?? 'Each',
                        'dispensing_unit' => $dispensingUnit ? (is_string($dispensingUnit) ? $dispensingUnit : null) : null,
                        'conversion_factor' => $conversionFactor ? (is_numeric($conversionFactor) ? (float) $conversionFactor : null) : null,
                        'storage_conditions' => $catalogItem->storage_conditions,
                        'requires_cold_chain' => (bool) $catalogItem->requires_cold_chain,
                    ];

                    $before = $this->extractTrackedFields($inventoryItem->toArray());
                    $inventoryItem->fill($updatePayload);
                    $inventoryItem->save();
                    $after = $this->extractTrackedFields($inventoryItem->toArray());

                    $this->auditLogRepository->write(
                        inventoryItemId: $inventoryItem->id,
                        action: 'inventory-item.synced-from-catalog',
                        actorId: $actorId,
                        changes: [
                            'before' => $before,
                            'after' => $after,
                            'source_catalog_item_id' => $catalogId,
                            'source_catalog_code' => $catalogItem->code,
                        ],
                    );

                    $updated++;
                } else {
                    // Create new inventory item
                    $itemCode = $this->generateItemCode($catalogItem->code, $catalogItem->name);

                    // Resolve unique code using preloaded set (no DB queries)
                    $originalCode = $itemCode;
                    $suffix = 1;
                    while (isset($existingItemCodes[$itemCode])) {
                        $itemCode = $originalCode.'-'.$suffix;
                        $suffix++;
                    }
                    $existingItemCodes[$itemCode] = true;

                    $createPayload = [
                        'tenant_id' => $tenantId,
                        'facility_id' => $facilityId,
                        'clinical_catalog_item_id' => $catalogId,
                        'item_code' => $itemCode,
                        'codes' => $codes,
                        'item_name' => $catalogItem->name,
                        'category' => InventoryItemCategory::PHARMACEUTICAL->value,
                        'subcategory' => $catalogItem->category,
                        'unit' => $stockUnit ?? 'Each',
                        'dispensing_unit' => $dispensingUnit ? (is_string($dispensingUnit) ? $dispensingUnit : null) : null,
                        'conversion_factor' => $conversionFactor ? (is_numeric($conversionFactor) ? (float) $conversionFactor : null) : null,
                        'storage_conditions' => $catalogItem->storage_conditions,
                        'requires_cold_chain' => (bool) $catalogItem->requires_cold_chain,
                        'current_stock' => 0,
                        'reorder_level' => 0,
                        'default_warehouse_id' => $defaultWarehouseId,
                        'default_supplier_id' => $defaultSupplierId,
                        'status' => 'active',
                    ];

                    $this->clinicalLinkGuard->assertPayloadCanPersist($createPayload);

                    $createdItem = $this->inventoryItemRepository->create($createPayload);

                    // Auto-seed base unit
                    $unitName = trim((string) ($stockUnit ?: 'Each'));
                    if ($unitName !== '') {
                        InventoryItemUnitModel::query()->create([
                            'tenant_id' => $tenantId,
                            'facility_id' => $facilityId,
                            'item_id' => $createdItem['id'],
                            'unit_name' => $unitName,
                            'unit_code' => $unitName,
                            'base_quantity' => 1.0,
                            'is_base_unit' => true,
                            'is_default_sales_unit' => true,
                            'is_default_purchase_unit' => true,
                            'is_active' => true,
                        ]);

                        $dispUnitName = $createPayload['dispensing_unit'] ?? null;
                        $convFactor = $createPayload['conversion_factor'] ?? null;
                        if (
                            $dispUnitName !== null
                            && strtolower(trim($dispUnitName)) !== strtolower($unitName)
                            && is_numeric($convFactor)
                            && (float) $convFactor > 0
                        ) {
                            InventoryItemUnitModel::query()->create([
                                'tenant_id' => $tenantId,
                                'facility_id' => $facilityId,
                                'item_id' => $createdItem['id'],
                                'unit_name' => trim($dispUnitName),
                                'unit_code' => trim($dispUnitName),
                                'base_quantity' => round(1.0 / (float) $convFactor, 6),
                                'is_base_unit' => false,
                                'is_default_sales_unit' => false,
                                'is_default_purchase_unit' => false,
                                'is_active' => true,
                            ]);
                        }
                    }

                    $this->auditLogRepository->write(
                        inventoryItemId: $createdItem['id'],
                        action: 'inventory-item.bulk-created-from-catalog',
                        actorId: $actorId,
                        changes: [
                            'after' => $this->extractTrackedFields($createdItem),
                            'source_catalog_item_id' => $catalogId,
                            'source_catalog_code' => $catalogItem->code,
                        ],
                    );

                    $created++;
                }
            } catch (\Throwable $e) {
                $errors[] = [
                    'catalogItemId' => $catalogId,
                    'code' => $catalogItem->code,
                    'name' => $catalogItem->name,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return [
            'created' => $created,
            'updated' => $updated,
            'errors' => $errors,
        ];
    }

    private function generateItemCode(string $catalogCode, string $catalogName): string
    {
        // Use the catalog code as-is if it's not empty, otherwise derive from name
        $code = trim($catalogCode);
        if ($code === '') {
            // Derive from name: uppercase, take first letters of significant words
            $parts = preg_split('/[\s\-]+/', $catalogName);
            $abbr = '';
            foreach (array_slice($parts ?: [], 0, 3) as $part) {
                $abbr .= strtoupper(substr($part, 0, 3));
            }
            $code = $abbr ?: 'MED';
        }

        return $code;
    }

    private function isPlatformScopingEnabled(): bool
    {
        return $this->featureFlagResolver->isEnabled('platform.multi_facility_scoping')
            || $this->featureFlagResolver->isEnabled('platform.multi_tenant_isolation');
    }

    private function defaultWarehouseIsUsable(string $warehouseId): bool
    {
        $query = InventoryWarehouseModel::query()
            ->whereKey($warehouseId)
            ->where('status', InventoryWarehouseStatus::ACTIVE->value);

        if ($this->isPlatformScopingEnabled()) {
            app(PlatformScopeQueryApplier::class)->apply($query);
        }

        return $query->exists();
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function extractTrackedFields(array $item): array
    {
        $tracked = [
            'clinical_catalog_item_id',
            'item_code',
            'item_name',
            'category',
            'subcategory',
            'unit',
            'dispensing_unit',
            'conversion_factor',
            'current_stock',
            'reorder_level',
            'default_warehouse_id',
            'default_supplier_id',
            'status',
        ];

        $result = [];
        foreach ($tracked as $field) {
            if (array_key_exists($field, $item)) {
                $result[$field] = $item[$field];
            }
        }

        return $result;
    }
}
