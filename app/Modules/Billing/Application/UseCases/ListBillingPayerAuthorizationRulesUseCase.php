<?php

namespace App\Modules\Billing\Application\UseCases;

use App\Modules\Billing\Domain\Repositories\BillingPayerAuthorizationRuleRepositoryInterface;
use App\Modules\Billing\Domain\Repositories\BillingPayerContractRepositoryInterface;
use App\Modules\Billing\Domain\ValueObjects\BillingPayerAuthorizationRuleStatus;

class ListBillingPayerAuthorizationRulesUseCase
{
    public function __construct(
        private readonly BillingPayerContractRepositoryInterface $contractRepository,
        private readonly BillingPayerAuthorizationRuleRepositoryInterface $ruleRepository,
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
            'ruleCode' => 'rule_code',
            'ruleName' => 'rule_name',
            'serviceCode' => 'service_code',
            'serviceType' => 'service_type',
            'department' => 'department',
            'priority' => 'priority',
            'amountThreshold' => 'amount_threshold',
            'coverageDecision' => 'coverage_decision',
            'coveragePercentOverride' => 'coverage_percent_override',
            'requiresAuthorization' => 'requires_authorization',
            'autoApprove' => 'auto_approve',
            'effectiveFrom' => 'effective_from',
            'effectiveTo' => 'effective_to',
            'status' => 'status',
            'updatedAt' => 'updated_at',
            'createdAt' => 'created_at',
        ];
        $sortBy = $filters['sortBy'] ?? 'ruleName';
        $sortBy = $sortMap[$sortBy] ?? 'rule_name';

        $sortDirection = strtolower((string) ($filters['sortDir'] ?? 'asc'));
        $sortDirection = $sortDirection === 'desc' ? 'desc' : 'asc';

        $query = isset($filters['q']) ? trim((string) $filters['q']) : null;
        $query = $query === '' ? null : $query;

        $status = isset($filters['status']) ? strtolower(trim((string) $filters['status'])) : null;
        $status = in_array($status, BillingPayerAuthorizationRuleStatus::values(), true) ? $status : null;

        $serviceType = isset($filters['serviceType']) ? trim((string) $filters['serviceType']) : null;
        $serviceType = $serviceType === '' ? null : $serviceType;

        $department = isset($filters['department']) ? trim((string) $filters['department']) : null;
        $department = $department === '' ? null : $department;

        $serviceCode = isset($filters['serviceCode']) ? strtoupper(trim((string) $filters['serviceCode'])) : null;
        $serviceCode = $serviceCode === '' ? null : $serviceCode;

        $coverageDecision = isset($filters['coverageDecision']) ? strtolower(trim((string) $filters['coverageDecision'])) : null;
        $coverageDecision = in_array($coverageDecision, ['inherit', 'covered', 'covered_with_rule', 'excluded', 'manual_review'], true)
            ? $coverageDecision
            : null;

        return $this->ruleRepository->searchByContractId(
            billingPayerContractId: $billingPayerContractId,
            query: $query,
            status: $status,
            serviceType: $serviceType,
            department: $department,
            serviceCode: $serviceCode,
            coverageDecision: $coverageDecision,
            page: $page,
            perPage: $perPage,
            sortBy: $sortBy,
            sortDirection: $sortDirection,
        );
    }
}
