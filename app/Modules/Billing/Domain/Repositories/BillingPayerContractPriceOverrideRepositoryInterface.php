<?php

namespace App\Modules\Billing\Domain\Repositories;

interface BillingPayerContractPriceOverrideRepositoryInterface
{
    public function create(array $attributes): array;

    public function findById(string $id): ?array;

    public function update(string $id, array $attributes): ?array;

    public function searchByContractId(
        string $billingPayerContractId,
        ?string $query,
        ?string $status,
        ?string $serviceType,
        ?string $pricingStrategy,
        ?string $serviceCode,
        int $page,
        int $perPage,
        ?string $sortBy,
        string $sortDirection
    ): array;

    public function hasOverlappingWindow(
        string $billingPayerContractId,
        string $serviceCode,
        ?string $effectiveFrom,
        ?string $effectiveTo,
        ?string $excludeId = null
    ): bool;

    public function findActiveApplicableOverride(
        string $billingPayerContractId,
        string $serviceCode,
        string $currencyCode,
        ?string $asOfDateTime = null
    ): ?array;

    /**
     * @param  list<string>  $serviceCodes
     * @return array<string, array<string, mixed>|null>  Map of service_code => override or null
     */
    public function findActiveApplicableOverrides(
        string $billingPayerContractId,
        array $serviceCodes,
        string $currencyCode,
        ?string $asOfDateTime = null
    ): array;
}
