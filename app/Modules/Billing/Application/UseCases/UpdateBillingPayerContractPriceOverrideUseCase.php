<?php

namespace App\Modules\Billing\Application\UseCases;

use App\Modules\Billing\Application\Exceptions\OverlappingBillingPayerContractPriceOverrideException;
use App\Modules\Billing\Application\Support\ChargeableItemPricingLookup;
use App\Modules\Billing\Domain\Repositories\BillingPayerContractPriceOverrideAuditLogRepositoryInterface;
use App\Modules\Billing\Domain\Repositories\BillingPayerContractPriceOverrideRepositoryInterface;
use App\Modules\Billing\Domain\Repositories\BillingPayerContractRepositoryInterface;
use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;

class UpdateBillingPayerContractPriceOverrideUseCase
{
    public function __construct(
        private readonly BillingPayerContractRepositoryInterface $contractRepository,
        private readonly BillingPayerContractPriceOverrideRepositoryInterface $priceOverrideRepository,
        private readonly BillingPayerContractPriceOverrideAuditLogRepositoryInterface $auditLogRepository,
        private readonly ChargeableItemPricingLookup $chargeableItemPricingLookup,
        private readonly CurrentPlatformScopeContextInterface $platformScopeContext,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    public function execute(string $billingPayerContractId, string $id, array $payload, ?int $actorId = null): ?array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $contract = $this->contractRepository->findById($billingPayerContractId);
        if (! $contract) {
            return null;
        }

        $existing = $this->priceOverrideRepository->findById($id);
        if (! $existing || ($existing['billing_payer_contract_id'] ?? null) !== $billingPayerContractId) {
            return null;
        }

        $serviceCode = array_key_exists('service_code', $payload)
            ? $this->normalizeServiceCode((string) $payload['service_code'])
            : (string) ($existing['service_code'] ?? '');
        $effectiveFrom = array_key_exists('effective_from', $payload)
            ? $this->normalizeNullableDateTime($payload['effective_from'])
            : $this->normalizeNullableDateTime($existing['effective_from'] ?? null);
        $effectiveTo = array_key_exists('effective_to', $payload)
            ? $this->normalizeNullableDateTime($payload['effective_to'])
            : $this->normalizeNullableDateTime($existing['effective_to'] ?? null);

        if ($this->priceOverrideRepository->hasOverlappingWindow(
            billingPayerContractId: $billingPayerContractId,
            serviceCode: $serviceCode,
            effectiveFrom: $effectiveFrom,
            effectiveTo: $effectiveTo,
            excludeId: $id,
        )) {
            throw new OverlappingBillingPayerContractPriceOverrideException(
                'A payer price override already exists for this service in the selected effective window.',
            );
        }

        $updateData = $this->toUpdateData($payload, $contract, $existing);
        if ($updateData === []) {
            return $existing;
        }

        $updated = $this->priceOverrideRepository->update($id, $updateData);
        if (! $updated) {
            return null;
        }

        $this->auditLogRepository->write(
            billingPayerContractPriceOverrideId: $id,
            action: 'billing-payer-contract-price-override.updated',
            actorId: $actorId,
            changes: $this->diffChanges($existing, $updated),
            metadata: [
                'billing_payer_contract_id' => $billingPayerContractId,
                'tenant_id' => $this->platformScopeContext->tenantId(),
                'facility_id' => $this->platformScopeContext->facilityId(),
            ],
        );

        return $updated;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $contract
     * @param  array<string, mixed>  $existing
     * @return array<string, mixed>
     */
    private function toUpdateData(array $payload, array $contract, array $existing): array
    {
        $serviceCode = array_key_exists('service_code', $payload)
            ? $this->normalizeServiceCode((string) $payload['service_code'])
            : (string) ($existing['service_code'] ?? '');
        $effectiveFrom = array_key_exists('effective_from', $payload)
            ? $this->normalizeNullableDateTime($payload['effective_from'])
            : null;

        $catalogItem = $this->chargeableItemPricingLookup->findByServiceCode(
            serviceCode: $serviceCode,
            currencyCode: strtoupper(trim((string) ($contract['currency_code'] ?? 'TZS'))),
            asOfDateTime: $effectiveFrom ?? now()->toDateTimeString(),
            tenantId: $this->platformScopeContext->tenantId(),
            facilityId: $this->platformScopeContext->facilityId(),
        );

        $updateData = [];

        // billing_service_catalog_item_id is a hard FK to the legacy table --
        // only ever set from explicit payload input, never from this lookup
        // (see CreateBillingPayerContractPriceOverrideUseCase for why).
        if (array_key_exists('billing_service_catalog_item_id', $payload)) {
            $updateData['billing_service_catalog_item_id'] = $payload['billing_service_catalog_item_id'];
        }
        if (array_key_exists('chargeable_item_id', $payload) || $catalogItem !== null) {
            $updateData['chargeable_item_id'] = $catalogItem['id'] ?? ($payload['chargeable_item_id'] ?? null);
        }
        if (array_key_exists('service_code', $payload)) {
            $updateData['service_code'] = $serviceCode;
        }
        if (array_key_exists('service_name', $payload) || $catalogItem !== null) {
            $updateData['service_name'] = $this->nullableTrimmedValue($payload['service_name'] ?? null) ?? $this->nullableTrimmedValue($catalogItem['service_name'] ?? null);
        }
        if (array_key_exists('service_type', $payload) || $catalogItem !== null) {
            $updateData['service_type'] = $this->nullableTrimmedValue($payload['service_type'] ?? null) ?? $this->nullableTrimmedValue($catalogItem['service_type'] ?? null);
        }
        if (array_key_exists('department', $payload) || $catalogItem !== null) {
            $updateData['department'] = $this->nullableTrimmedValue($payload['department'] ?? null) ?? $this->nullableTrimmedValue($catalogItem['department'] ?? null);
        }
        if (array_key_exists('pricing_strategy', $payload)) {
            $updateData['pricing_strategy'] = $this->normalizePricingStrategy((string) $payload['pricing_strategy']);
        }
        if (array_key_exists('override_value', $payload)) {
            $updateData['override_value'] = $this->normalizeOverrideValue($payload['override_value']);
        }
        if (array_key_exists('effective_from', $payload)) {
            $updateData['effective_from'] = $effectiveFrom;
        }
        if (array_key_exists('effective_to', $payload)) {
            $updateData['effective_to'] = $this->normalizeNullableDateTime($payload['effective_to']);
        }
        if (array_key_exists('override_notes', $payload)) {
            $updateData['override_notes'] = $this->nullableTrimmedValue($payload['override_notes']);
        }
        if (array_key_exists('metadata', $payload)) {
            $updateData['metadata'] = is_array($payload['metadata']) ? $payload['metadata'] : null;
        }

        return $updateData;
    }

    /**
     * @return array<string, mixed>
     */
    private function diffChanges(array $before, array $after): array
    {
        $fields = [
            'billing_service_catalog_item_id',
            'chargeable_item_id',
            'service_code',
            'service_name',
            'service_type',
            'department',
            'pricing_strategy',
            'override_value',
            'effective_from',
            'effective_to',
            'override_notes',
            'metadata',
        ];

        $changes = [];
        foreach ($fields as $field) {
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
}
