<?php

namespace App\Modules\Billing\Application\UseCases;

use App\Modules\Billing\Domain\Repositories\BillingPayerContractRepositoryInterface;

class ListBillingPayerContractStatusCountsUseCase
{
    public function __construct(private readonly BillingPayerContractRepositoryInterface $repository) {}

    public function execute(array $filters): array
    {
        $query = isset($filters['q']) ? trim((string) $filters['q']) : null;
        $query = $query === '' ? null : $query;

        $payerType = isset($filters['payerType']) ? trim((string) $filters['payerType']) : null;
        $payerType = $payerType === '' ? null : $payerType;

        $currencyCode = isset($filters['currencyCode']) ? strtoupper(trim((string) $filters['currencyCode'])) : null;
        $currencyCode = $currencyCode === '' ? null : $currencyCode;

        $requiresPreAuthorization = $this->parseOptionalBoolean($filters['requiresPreAuthorization'] ?? null);

        return $this->repository->statusCounts(
            query: $query,
            payerType: $payerType,
            currencyCode: $currencyCode,
            requiresPreAuthorization: $requiresPreAuthorization,
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
