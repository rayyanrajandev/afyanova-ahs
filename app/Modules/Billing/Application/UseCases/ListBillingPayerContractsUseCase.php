<?php

namespace App\Modules\Billing\Application\UseCases;

use App\Modules\Billing\Domain\Repositories\BillingPayerContractRepositoryInterface;
use App\Modules\Billing\Domain\ValueObjects\BillingPayerContractStatus;

class ListBillingPayerContractsUseCase
{
    public function __construct(private readonly BillingPayerContractRepositoryInterface $repository) {}

    public function execute(array $filters): array
    {
        $page = max((int) ($filters['page'] ?? 1), 1);
        $perPage = min(max((int) ($filters['perPage'] ?? 15), 1), 100);

        $sortMap = [
            'contractCode' => 'contract_code',
            'contractName' => 'contract_name',
            'payerType' => 'payer_type',
            'payerName' => 'payer_name',
            'payerPlanCode' => 'payer_plan_code',
            'currencyCode' => 'currency_code',
            'requiresPreAuthorization' => 'requires_pre_authorization',
            'status' => 'status',
            'effectiveFrom' => 'effective_from',
            'updatedAt' => 'updated_at',
            'createdAt' => 'created_at',
        ];
        $sortBy = $filters['sortBy'] ?? 'contractName';
        $sortBy = $sortMap[$sortBy] ?? 'contract_name';

        $sortDirection = strtolower((string) ($filters['sortDir'] ?? 'asc'));
        $sortDirection = $sortDirection === 'desc' ? 'desc' : 'asc';

        $query = isset($filters['q']) ? trim((string) $filters['q']) : null;
        $query = $query === '' ? null : $query;

        $payerType = isset($filters['payerType']) ? trim((string) $filters['payerType']) : null;
        $payerType = $payerType === '' ? null : $payerType;

        $status = isset($filters['status']) ? strtolower(trim((string) $filters['status'])) : null;
        $status = in_array($status, BillingPayerContractStatus::values(), true) ? $status : null;

        $currencyCode = isset($filters['currencyCode']) ? strtoupper(trim((string) $filters['currencyCode'])) : null;
        $currencyCode = $currencyCode === '' ? null : $currencyCode;

        $requiresPreAuthorization = $this->parseOptionalBoolean($filters['requiresPreAuthorization'] ?? null);

        return $this->repository->search(
            query: $query,
            payerType: $payerType,
            status: $status,
            currencyCode: $currencyCode,
            requiresPreAuthorization: $requiresPreAuthorization,
            page: $page,
            perPage: $perPage,
            sortBy: $sortBy,
            sortDirection: $sortDirection,
        );
    }

    private function parseOptionalBoolean(mixed $value): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_bool($value)) {
            return $value;
        }

        $normalized = strtolower(trim((string) $value));

        return match ($normalized) {
            '1', 'true', 'yes' => true,
            '0', 'false', 'no' => false,
            default => null,
        };
    }
}
