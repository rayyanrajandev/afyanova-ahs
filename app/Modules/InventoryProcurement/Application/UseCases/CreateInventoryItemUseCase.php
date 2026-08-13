<?php

namespace App\Modules\InventoryProcurement\Application\UseCases;

use App\Modules\InventoryProcurement\Application\Exceptions\DuplicateInventoryItemCodeException;
use App\Modules\InventoryProcurement\Domain\Repositories\InventoryItemAuditLogRepositoryInterface;
use App\Modules\InventoryProcurement\Domain\Repositories\InventoryItemRepositoryInterface;
use App\Modules\InventoryProcurement\Domain\Services\CatalogIdentityResolver;
use App\Modules\InventoryProcurement\Domain\ValueObjects\InventoryItemStatus;
use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryItemUnitModel;
use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;
use App\Modules\Platform\Infrastructure\Models\ClinicalCatalogItemModel;
use App\Modules\Platform\Infrastructure\Models\ClinicalCatalogItemPackagingTemplateModel;
use App\Support\CatalogGovernance\InventoryClinicalLinkGuard;
use App\Support\CatalogGovernance\StandardsCodeSupport;
use Illuminate\Validation\ValidationException;

class CreateInventoryItemUseCase
{
    public function __construct(
        private readonly InventoryItemRepositoryInterface $inventoryItemRepository,
        private readonly InventoryItemAuditLogRepositoryInterface $auditLogRepository,
        private readonly CurrentPlatformScopeContextInterface $platformScopeContext,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
        private readonly InventoryClinicalLinkGuard $clinicalLinkGuard,
        private readonly StandardsCodeSupport $standardsCodeSupport,
        private readonly CatalogIdentityResolver $catalogIdentityResolver,
    ) {}

    public function execute(array $payload, ?int $actorId = null): array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $itemCode = $this->normalizeItemCode((string) $payload['item_code']);
        if ($this->inventoryItemRepository->existsByItemCode($itemCode)) {
            throw new DuplicateInventoryItemCodeException('Item code already exists.');
        }

        $clinicalCatalogItemId = $this->nullableTrimmedValue($payload['clinical_catalog_item_id'] ?? null);
        $defaultWarehouseId = $this->nullableTrimmedValue($payload['default_warehouse_id'] ?? null);
        if ($defaultWarehouseId === null) {
            throw ValidationException::withMessages([
                'defaultWarehouseId' => ['Choose a default warehouse before creating an inventory item.'],
            ]);
        }

        // When linked to a clinical catalog item, read identity fields from the catalog
        // to avoid duplicating data. The catalog is the single source of truth.
        $catalogIdentity = $clinicalCatalogItemId !== null
            ? $this->resolveCatalogIdentity($clinicalCatalogItemId)
            : null;

        $createPayload = [
            'tenant_id' => $this->platformScopeContext->tenantId(),
            'facility_id' => $this->platformScopeContext->facilityId(),
            'clinical_catalog_item_id' => $clinicalCatalogItemId,
            'item_code' => $itemCode,
            'msd_code' => $this->nullableTrimmedValue($payload['msd_code'] ?? null),
            'nhif_code' => $this->nullableTrimmedValue($payload['nhif_code'] ?? null),
            'barcode' => $this->nullableTrimmedValue($payload['barcode'] ?? null),
            'codes' => $catalogIdentity['codes'] ?? $this->standardsCodeSupport->normalize(is_array($payload['codes'] ?? null) ? $payload['codes'] : null),
            'item_name' => $catalogIdentity['item_name'] ?? $this->nullableTrimmedValue($payload['item_name'] ?? null) ?? '',
            // generic_name/dosage_form/strength dropped from inventory_items in Phase 3
            // (Inventory_MasterData_Alignment_Plan.md) -- always read from the Clinical
            // Catalog relation now (InventoryItemResponseTransformer), never stored here.
            'category' => $this->nullableTrimmedValue($payload['category'] ?? null),
            'subcategory' => $catalogIdentity['subcategory'] ?? $this->nullableTrimmedValue($payload['subcategory'] ?? null),
            'ven_classification' => $this->nullableTrimmedValue($payload['ven_classification'] ?? null),
            'abc_classification' => $this->nullableTrimmedValue($payload['abc_classification'] ?? null),
            'unit' => $catalogIdentity['unit'] ?? $this->nullableTrimmedValue($payload['unit'] ?? null) ?? '',
            'dispensing_unit' => $catalogIdentity['dispensing_unit'] ?? $this->nullableTrimmedValue($payload['dispensing_unit'] ?? null),
            'conversion_factor' => $catalogIdentity['conversion_factor'] ?? $this->nullableNumericValue($payload['conversion_factor'] ?? null),
            'bin_location' => $this->nullableTrimmedValue($payload['bin_location'] ?? null),
            'manufacturer' => $this->nullableTrimmedValue($payload['manufacturer'] ?? null),
            // storage_conditions/requires_cold_chain stay on inventory_items -- Blood
            // Product, Laboratory, and Food & Nutrition use them and can never
            // catalog-link (only Pharmaceutical can), so Inventory is the only possible
            // owner for those three categories. Phase 2's transformer already prefers
            // the catalog's value for Pharmaceutical items when one is set; this is
            // still the correct write path for every category.
            'storage_conditions' => $this->nullableTrimmedValue($payload['storage_conditions'] ?? null),
            'requires_cold_chain' => (bool) ($payload['requires_cold_chain'] ?? false),
            // is_controlled_substance/controlled_substance_schedule dropped from
            // inventory_items in Phase 3 -- Pharmaceutical-only, always catalog-linked.
            'current_stock' => 0,
            'reorder_level' => (float) ($payload['reorder_level'] ?? 0),
            'max_stock_level' => $this->nullableNumericValue($payload['max_stock_level'] ?? null),
            'default_warehouse_id' => $defaultWarehouseId,
            'default_supplier_id' => $payload['default_supplier_id'] ?? null,
            'status' => InventoryItemStatus::ACTIVE->value,
        ];

        $this->clinicalLinkGuard->assertPayloadCanPersist($createPayload);

        $created = $this->inventoryItemRepository->create($createPayload);

        // Inventory_MasterData_Alignment_Plan.md Phase 4: when the catalog item has a
        // reusable packaging template, seed inventory_item_units from it -- a one-time
        // copy, not a live reference, so this facility can freely diverge afterward
        // (different local pack sizes are legitimate). Falls back to the single/dual
        // unit auto-seed below when no template exists, so behavior for every drug
        // that hasn't been given a template yet is unchanged.
        $seededFromTemplate = $clinicalCatalogItemId !== null
            && $this->seedUnitsFromCatalogTemplates($clinicalCatalogItemId, $created['id']);

        // Auto-seed base unit from the stock unit field
        $unitName = trim((string) ($catalogIdentity['unit'] ?? $payload['unit'] ?? ''));
        if (! $seededFromTemplate && $unitName !== '') {
            InventoryItemUnitModel::query()->create([
                'tenant_id' => $this->platformScopeContext->tenantId(),
                'facility_id' => $this->platformScopeContext->facilityId(),
                'item_id' => $created['id'],
                'unit_name' => $unitName,
                'unit_code' => $unitName,
                'base_quantity' => 1.0,
                'is_base_unit' => true,
                'is_default_sales_unit' => true,
                'is_default_purchase_unit' => true,
                'is_active' => true,
            ]);

            // Auto-seed dispensing unit when it differs from the stock unit
            $dispensingUnit = strtolower(trim((string) ($catalogIdentity['dispensing_unit'] ?? $payload['dispensing_unit'] ?? '')));
            $conversionFactor = (float) ($catalogIdentity['conversion_factor'] ?? $payload['conversion_factor'] ?? 0);
            if ($dispensingUnit !== '' && $dispensingUnit !== strtolower($unitName) && $conversionFactor > 0) {
                InventoryItemUnitModel::query()->create([
                    'tenant_id' => $this->platformScopeContext->tenantId(),
                    'facility_id' => $this->platformScopeContext->facilityId(),
                    'item_id' => $created['id'],
                    'unit_name' => $dispensingUnit,
                    'unit_code' => $dispensingUnit,
                    'base_quantity' => round(1.0 / $conversionFactor, 6),
                    'is_base_unit' => false,
                    'is_default_sales_unit' => false,
                    'is_default_purchase_unit' => false,
                    'is_active' => true,
                ]);
            }
        }

        $this->auditLogRepository->write(
            inventoryItemId: $created['id'],
            action: 'inventory-item.created',
            actorId: $actorId,
            changes: [
                'after' => $this->extractTrackedFields($created),
            ],
        );

        return $created;
    }

    /**
     * @return bool true when at least one unit was seeded from a catalog template
     */
    private function seedUnitsFromCatalogTemplates(string $clinicalCatalogItemId, string $inventoryItemId): bool
    {
        $templates = ClinicalCatalogItemPackagingTemplateModel::query()
            ->where('clinical_catalog_item_id', $clinicalCatalogItemId)
            ->orderByDesc('is_base_unit')
            ->get();

        if ($templates->isEmpty()) {
            return false;
        }

        $tenantId = $this->platformScopeContext->tenantId();
        $facilityId = $this->platformScopeContext->facilityId();

        foreach ($templates as $template) {
            InventoryItemUnitModel::query()->create([
                'tenant_id' => $tenantId,
                'facility_id' => $facilityId,
                'item_id' => $inventoryItemId,
                'unit_name' => $template->unit_name,
                'unit_code' => $template->unit_code,
                'base_quantity' => $template->base_quantity,
                'is_base_unit' => $template->is_base_unit,
                'is_default_sales_unit' => $template->is_default_sales_unit,
                'is_default_purchase_unit' => $template->is_default_purchase_unit,
                'is_active' => true,
            ]);
        }

        return true;
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
    private function extractTrackedFields(array $item): array
    {
        $tracked = [
            'tenant_id',
            'facility_id',
            'clinical_catalog_item_id',
            'item_code',
            'msd_code',
            'nhif_code',
            'barcode',
            'codes',
            'item_name',
            'category',
            'subcategory',
            'ven_classification',
            'abc_classification',
            'unit',
            'dispensing_unit',
            'conversion_factor',
            'bin_location',
            'manufacturer',
            'storage_conditions',
            'requires_cold_chain',
            'current_stock',
            'reorder_level',
            'max_stock_level',
            'default_warehouse_id',
            'default_supplier_id',
            'status',
        ];

        $result = [];
        foreach ($tracked as $field) {
            $result[$field] = $item[$field] ?? null;
        }

        return $result;
    }
}
