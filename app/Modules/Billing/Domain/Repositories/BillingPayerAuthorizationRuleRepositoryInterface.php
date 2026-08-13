<?php

namespace App\Modules\Billing\Domain\Repositories;

interface BillingPayerAuthorizationRuleRepositoryInterface
{
    public function create(array $attributes): array;

    public function findById(string $id): ?array;

    public function update(string $id, array $attributes): ?array;

    public function listByContractId(
        string $billingPayerContractId,
        ?string $status = null,
    ): array;

    public function existsByRuleCode(
        string $billingPayerContractId,
        string $ruleCode,
        ?string $excludeId = null
    ): bool;

    public function searchByContractId(
        string $billingPayerContractId,
        ?string $query,
        ?string $status,
        ?string $serviceType,
        ?string $department,
        ?string $serviceCode,
        ?string $coverageDecision,
        int $page,
        int $perPage,
        ?string $sortBy,
        string $sortDirection
    ): array;

    public function listActiveMatchingRules(
        string $billingPayerContractId,
        ?string $serviceCode,
        ?string $serviceType,
        ?string $department,
        ?string $asOfDateTime
    ): array;

    /**
     * @param  list<string>  $serviceCodes
     * @return array<string, list<array<string, mixed>>>  Map of service_code => matching rules
     */
    public function listActiveMatchingRulesByServiceCodes(
        string $billingPayerContractId,
        array $serviceCodes,
        ?string $asOfDateTime = null
    ): array;
}
