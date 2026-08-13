<?php

namespace App\Modules\Billing\Presentation\Http\Transformers;

class BillingPayerContractPriceOverrideResponseTransformer
{
    public static function transform(array $override): array
    {
        return [
            'id' => $override['id'] ?? null,
            'billingPayerContractId' => $override['billing_payer_contract_id'] ?? null,
            'tenantId' => $override['tenant_id'] ?? null,
            'facilityId' => $override['facility_id'] ?? null,
            'billingServiceCatalogItemId' => $override['billing_service_catalog_item_id'] ?? null,
            'chargeableItemId' => $override['chargeable_item_id'] ?? null,
            'serviceCode' => $override['service_code'] ?? null,
            'serviceName' => $override['service_name'] ?? null,
            'serviceType' => $override['service_type'] ?? null,
            'department' => $override['department'] ?? null,
            'currencyCode' => $override['currency_code'] ?? null,
            'pricingStrategy' => $override['pricing_strategy'] ?? null,
            'overrideValue' => $override['override_value'] ?? null,
            'catalogPricingStatus' => $override['catalog_pricing_status'] ?? null,
            'catalogBasePrice' => $override['catalog_base_price'] ?? null,
            'catalogCurrencyCode' => $override['catalog_currency_code'] ?? null,
            'resolvedNegotiatedPrice' => $override['resolved_negotiated_price'] ?? null,
            'varianceAmount' => $override['variance_amount'] ?? null,
            'variancePercent' => $override['variance_percent'] ?? null,
            'varianceDirection' => $override['variance_direction'] ?? null,
            'effectiveFrom' => $override['effective_from'] ?? null,
            'effectiveTo' => $override['effective_to'] ?? null,
            'overrideNotes' => $override['override_notes'] ?? null,
            'metadata' => $override['metadata'] ?? null,
            'status' => $override['status'] ?? null,
            'statusReason' => $override['status_reason'] ?? null,
            'createdAt' => $override['created_at'] ?? null,
            'updatedAt' => $override['updated_at'] ?? null,
        ];
    }
}
