<?php

namespace App\Modules\Billing\Application\UseCases;

use App\Modules\Billing\Domain\Repositories\BillingPayerContractPriceOverrideRepositoryInterface;
use App\Modules\Billing\Domain\Repositories\BillingPayerContractRepositoryInterface;
use App\Modules\Billing\Domain\ValueObjects\BillingPayerContractPriceOverrideStatus;

class ListBillingPayerContractPriceOverridesUseCase
{
    public function __construct(
        private readonly BillingPayerContractRepositoryInterface $contractRepository,
        private readonly BillingPayerContractPriceOverrideRepositoryInterface $priceOverrideRepository,
    ) {}

    public function execute(string $billingPayerContractId, array $filters): ?array
    {
        $contract = $this->contractRepository->findById($billingPayerContractId);
        if (! $contract) {
            return null;
        }

        $page = max((int) ($filters['page'] ?? 1), 1);
        $perPage = min(max((int) ($filters['perPage'] ?? 15), 1), 100);

        $sortMap = [
            'serviceCode' => 'service_code',
            'serviceName' => 'service_name',
            'serviceType' => 'service_type',
            'department' => 'department',
            'pricingStrategy' => 'pricing_strategy',
            'overrideValue' => 'override_value',
            'effectiveFrom' => 'effective_from',
            'status' => 'status',
            'updatedAt' => 'updated_at',
            'createdAt' => 'created_at',
        ];
        $sortBy = $sortMap[$filters['sortBy'] ?? 'serviceName'] ?? 'service_name';

        $sortDirection = strtolower((string) ($filters['sortDir'] ?? 'asc'));
        $sortDirection = $sortDirection === 'desc' ? 'desc' : 'asc';

        $query = isset($filters['q']) ? trim((string) $filters['q']) : null;
        $query = $query === '' ? null : $query;

        $status = isset($filters['status']) ? strtolower(trim((string) $filters['status'])) : null;
        $status = in_array($status, BillingPayerContractPriceOverrideStatus::values(), true) ? $status : null;

        $serviceType = isset($filters['serviceType']) ? trim((string) $filters['serviceType']) : null;
        $serviceType = $serviceType === '' ? null : $serviceType;

        $pricingStrategy = isset($filters['pricingStrategy']) ? trim((string) $filters['pricingStrategy']) : null;
        $pricingStrategy = in_array($pricingStrategy, ['fixed_price', 'discount_percent', 'markup_percent'], true)
            ? $pricingStrategy
            : null;

        $serviceCode = isset($filters['serviceCode']) ? strtoupper(trim((string) $filters['serviceCode'])) : null;
        $serviceCode = $serviceCode === '' ? null : $serviceCode;

        return $this->priceOverrideRepository->searchByContractId(
            billingPayerContractId: $billingPayerContractId,
            query: $query,
            status: $status,
            serviceType: $serviceType,
            pricingStrategy: $pricingStrategy,
            serviceCode: $serviceCode,
            page: $page,
            perPage: $perPage,
            sortBy: $sortBy,
            sortDirection: $sortDirection,
        );
    }
}
