<?php

namespace App\Modules\Billing\Application\Support;

use App\Modules\Billing\Domain\Services\ChargeResolverInterface;
use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryItemModel;
use App\Modules\Platform\Infrastructure\Models\ChargeableItemModel;

/**
 * PricingEngine_Migration_Plan.md Phase 5: payer-contract price overrides
 * and manual invoice auto-pricing still identify a service by a typed-in
 * service code, but must resolve its price and metadata from
 * chargeable_items/price_book_entries now, not the legacy
 * billing_service_catalog_items table. This returns the same shape the old
 * BillingServiceCatalogItemRepositoryInterface::findActivePricingByServiceCode()
 * produced, so the three call sites that still key off service_code
 * (payer override create/update, and the payer-contract admin's pricing-
 * impact preview) needed minimal changes -- only where the lookup reads
 * from, not the surrounding business logic (override math, authorization
 * rule matching, tax calculation).
 */
class ChargeableItemPricingLookup
{
    public function __construct(
        private readonly ChargeResolverInterface $chargeResolver,
        private readonly BillingCatalogDepartmentResolver $departmentResolver,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function findByServiceCode(
        string $serviceCode,
        string $currencyCode,
        ?string $asOfDateTime,
        ?string $tenantId = null,
        ?string $facilityId = null,
    ): ?array {
        $normalizedCode = strtoupper(trim($serviceCode));
        if ($normalizedCode === '') {
            return null;
        }

        $item = ChargeableItemModel::query()
            ->whereRaw('UPPER(code) = ?', [$normalizedCode])
            ->where('status', 'active')
            ->first();

        if ($item === null) {
            return null;
        }

        return $this->resolve($item, $currencyCode, $asOfDateTime, $tenantId, $facilityId);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(
        string $chargeableItemId,
        string $currencyCode,
        ?string $asOfDateTime,
        ?string $tenantId = null,
        ?string $facilityId = null,
    ): ?array {
        $item = ChargeableItemModel::query()
            ->where('status', 'active')
            ->find($chargeableItemId);

        if ($item === null) {
            return null;
        }

        return $this->resolve($item, $currencyCode, $asOfDateTime, $tenantId, $facilityId);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolve(
        ChargeableItemModel $item,
        string $currencyCode,
        ?string $asOfDateTime,
        ?string $tenantId,
        ?string $facilityId,
    ): ?array {
        $resolved = $this->chargeResolver->resolvePrice(
            chargeableItemId: (string) $item->id,
            quantityOrDuration: 1.0,
            asOfDate: $asOfDateTime,
            tenantId: $tenantId,
            facilityId: $facilityId,
            payerContractId: null,
            currencyCode: $currencyCode,
        );

        if ($resolved['pricingStatus'] !== 'priced') {
            return null;
        }

        $departmentId = $item->department_id === null ? null : (string) $item->department_id;

        return [
            'id' => (string) $item->id,
            'service_code' => strtoupper($item->code),
            'service_name' => $item->name,
            'service_type' => $this->billingServiceType($item->catalog_type),
            'department_id' => $departmentId,
            // BillingCatalogDepartmentResolver::resolveDepartmentName() throws
            // when a department id doesn't resolve in the current tenant/
            // facility scope (it's designed for validating admin input, not
            // a best-effort read) -- a department label is a display/rule-
            // matching nicety here, never worth failing pricing over.
            'department' => $departmentId !== null ? $this->resolveDepartmentNameSafely($departmentId) : null,
            'unit' => $item->default_unit,
            'base_price' => $resolved['unitPrice'],
            'currency_code' => $resolved['currencyCode'],
            'is_taxable' => (bool) $item->is_taxable,
            'tax_rate_percent' => $item->tax_rate_percent === null ? null : (float) $item->tax_rate_percent,
            'inventory_item_id' => $this->resolveInventoryItemId($item),
        ];
    }

    /**
     * PricingEngine_Migration_Plan.md Phase 5: formulary/pharmacy chargeable
     * items reuse their linked clinical_catalog_item_id as their own id
     * (Phase 1 ID-reuse convention) -- inventory_items links to that same
     * clinical catalog item, so this is how BillingInvoiceLineItemAutoPricingResolver
     * still finds the linked inventory item for medicine-specific pricing
     * (InventoryItemUnitPricingService), same as the old catalog's
     * inventory_item_id column used to provide directly.
     */
    private function resolveInventoryItemId(ChargeableItemModel $item): ?string
    {
        if ($item->catalog_type !== 'formulary_item' || $item->clinical_catalog_item_id === null) {
            return null;
        }

        $inventoryItemId = InventoryItemModel::query()
            ->where('clinical_catalog_item_id', $item->clinical_catalog_item_id)
            ->value('id');

        return $inventoryItemId === null ? null : (string) $inventoryItemId;
    }

    private function resolveDepartmentNameSafely(string $departmentId): ?string
    {
        try {
            return $this->departmentResolver->resolveDepartmentName($departmentId);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    private function billingServiceType(string $catalogType): string
    {
        return match ($catalogType) {
            'lab_test' => 'laboratory',
            'radiology_procedure' => 'radiology',
            'theatre_procedure' => 'theatre',
            'clinical_procedure' => 'procedure',
            'formulary_item' => 'pharmacy',
            default => $catalogType,
        };
    }
}
