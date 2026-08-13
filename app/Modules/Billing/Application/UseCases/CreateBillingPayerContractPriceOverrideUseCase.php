<?php

namespace App\Modules\Billing\Application\UseCases;

use App\Modules\Billing\Application\Exceptions\OverlappingBillingPayerContractPriceOverrideException;
use App\Modules\Billing\Application\Support\ChargeableItemPricingLookup;
use App\Modules\Billing\Domain\Repositories\BillingPayerContractPriceOverrideAuditLogRepositoryInterface;
use App\Modules\Billing\Domain\Repositories\BillingPayerContractPriceOverrideRepositoryInterface;
use App\Modules\Billing\Domain\Repositories\BillingPayerContractRepositoryInterface;
use App\Modules\Billing\Domain\ValueObjects\BillingPayerContractPriceOverrideStatus;
use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;

class CreateBillingPayerContractPriceOverrideUseCase
{
    public function __construct(
        private readonly BillingPayerContractRepositoryInterface $contractRepository,
        private readonly BillingPayerContractPriceOverrideRepositoryInterface $priceOverrideRepository,
        private readonly BillingPayerContractPriceOverrideAuditLogRepositoryInterface $auditLogRepository,
        private readonly ChargeableItemPricingLookup $chargeableItemPricingLookup,
        private readonly CurrentPlatformScopeContextInterface $platformScopeContext,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    public function execute(string $billingPayerContractId, array $payload, ?int $actorId = null): ?array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $contract = $this->contractRepository->findById($billingPayerContractId);
        if (! $contract) {
            return null;
        }

        $serviceCode = $this->normalizeServiceCode((string) $payload['service_code']);
        $effectiveFrom = $this->normalizeNullableDateTime($payload['effective_from'] ?? null);
        $effectiveTo = $this->normalizeNullableDateTime($payload['effective_to'] ?? null);

        if ($this->priceOverrideRepository->hasOverlappingWindow($billingPayerContractId, $serviceCode, $effectiveFrom, $effectiveTo)) {
            throw new OverlappingBillingPayerContractPriceOverrideException(
                'A payer price override already exists for this service in the selected effective window.',
            );
        }

        $catalogItem = $this->chargeableItemPricingLookup->findByServiceCode(
            serviceCode: $serviceCode,
            currencyCode: strtoupper(trim((string) ($contract['currency_code'] ?? 'TZS'))),
            asOfDateTime: $effectiveFrom ?? now()->toDateTimeString(),
            tenantId: $this->platformScopeContext->tenantId(),
            facilityId: $this->platformScopeContext->facilityId(),
        );

        $created = $this->priceOverrideRepository->create([
            'billing_payer_contract_id' => $billingPayerContractId,
            'tenant_id' => $this->platformScopeContext->tenantId(),
            'facility_id' => $this->platformScopeContext->facilityId(),
            // PricingEngine_Migration_Plan.md Phase 5: billing_service_catalog_item_id
            // is a hard FK to the legacy table -- only ever set from
            // explicit payload input now (a caller who still has a real
            // legacy id), never from this lookup, which resolves against
            // chargeable_items instead.
            'billing_service_catalog_item_id' => $payload['billing_service_catalog_item_id'] ?? null,
            'chargeable_item_id' => $catalogItem['id'] ?? ($payload['chargeable_item_id'] ?? null),
            'service_code' => $serviceCode,
            'service_name' => $this->nullableTrimmedValue($payload['service_name'] ?? null) ?? $this->nullableTrimmedValue($catalogItem['service_name'] ?? null),
            'service_type' => $this->nullableTrimmedValue($payload['service_type'] ?? null) ?? $this->nullableTrimmedValue($catalogItem['service_type'] ?? null),
            'department' => $this->nullableTrimmedValue($payload['department'] ?? null) ?? $this->nullableTrimmedValue($catalogItem['department'] ?? null),
            'currency_code' => strtoupper(trim((string) ($contract['currency_code'] ?? 'TZS'))),
            'pricing_strategy' => $this->normalizePricingStrategy((string) $payload['pricing_strategy']),
            'override_value' => $this->normalizeOverrideValue($payload['override_value']),
            'effective_from' => $effectiveFrom,
            'effective_to' => $effectiveTo,
            'override_notes' => $this->nullableTrimmedValue($payload['override_notes'] ?? null),
            'metadata' => is_array($payload['metadata'] ?? null) ? $payload['metadata'] : null,
            'status' => BillingPayerContractPriceOverrideStatus::ACTIVE->value,
            'status_reason' => null,
        ]);

        $this->auditLogRepository->write(
            billingPayerContractPriceOverrideId: $created['id'],
            action: 'billing-payer-contract-price-override.created',
            actorId: $actorId,
            changes: [
                'after' => $this->extractTrackedFields($created),
            ],
            metadata: [
                'billing_payer_contract_id' => $billingPayerContractId,
            ],
        );

        return $created;
    }

    private function normalizeServiceCode(string $value): string
    {
        return strtoupper(trim($value));
    }

    private function normalizePricingStrategy(string $value): string
    {
        return strtolower(trim($value));
    }

    private function normalizeOverrideValue(mixed $value): float
    {
        return round(max((float) $value, 0), 2);
    }

    private function normalizeNullableDateTime(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }

    private function nullableTrimmedValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function extractTrackedFields(array $override): array
    {
        $tracked = [
            'billing_payer_contract_id',
            'billing_service_catalog_item_id',
            'service_code',
            'service_name',
            'service_type',
            'department',
            'currency_code',
            'pricing_strategy',
            'override_value',
            'effective_from',
            'effective_to',
            'override_notes',
            'metadata',
            'status',
            'status_reason',
        ];

        $result = [];
        foreach ($tracked as $field) {
            $result[$field] = $override[$field] ?? null;
        }

        return $result;
    }
}
