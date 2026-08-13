<?php

namespace App\Modules\Billing\Application\UseCases;

use App\Modules\Billing\Domain\Repositories\BillingPayerContractPriceOverrideAuditLogRepositoryInterface;
use App\Modules\Billing\Domain\Repositories\BillingPayerContractPriceOverrideRepositoryInterface;
use App\Modules\Billing\Domain\Repositories\BillingPayerContractRepositoryInterface;

class ListBillingPayerContractPriceOverrideAuditLogsUseCase
{
    public function __construct(
        private readonly BillingPayerContractRepositoryInterface $contractRepository,
        private readonly BillingPayerContractPriceOverrideRepositoryInterface $priceOverrideRepository,
        private readonly BillingPayerContractPriceOverrideAuditLogRepositoryInterface $auditLogRepository,
    ) {}

    public function execute(string $billingPayerContractId, string $billingPayerContractPriceOverrideId, array $filters): ?array
    {
        $contract = $this->contractRepository->findById($billingPayerContractId);
        if (! $contract) {
            return null;
        }

        $override = $this->priceOverrideRepository->findById($billingPayerContractPriceOverrideId);
        if (! $override || ($override['billing_payer_contract_id'] ?? null) !== $billingPayerContractId) {
            return null;
        }

        $page = max((int) ($filters['page'] ?? 1), 1);
        $perPage = min(max((int) ($filters['perPage'] ?? 20), 1), 100);

        $query = isset($filters['q']) ? trim((string) $filters['q']) : null;
        $query = $query === '' ? null : $query;

        $action = isset($filters['action']) ? trim((string) $filters['action']) : null;
        $action = $action === '' ? null : $action;

        $actorType = isset($filters['actorType']) ? strtolower(trim((string) $filters['actorType'])) : null;
        $actorType = in_array($actorType, ['system', 'user'], true) ? $actorType : null;

        $actorId = isset($filters['actorId']) && $filters['actorId'] !== ''
            ? max((int) $filters['actorId'], 1)
            : null;

        $fromDateTime = isset($filters['fromDateTime']) ? trim((string) $filters['fromDateTime']) : null;
        $fromDateTime = $fromDateTime === '' ? null : $fromDateTime;

        $toDateTime = isset($filters['toDateTime']) ? trim((string) $filters['toDateTime']) : null;
        $toDateTime = $toDateTime === '' ? null : $toDateTime;

        return $this->auditLogRepository->listByBillingPayerContractPriceOverrideId(
            billingPayerContractPriceOverrideId: $billingPayerContractPriceOverrideId,
            page: $page,
            perPage: $perPage,
            query: $query,
            action: $action,
            actorType: $actorType,
            actorId: $actorId,
            fromDateTime: $fromDateTime,
            toDateTime: $toDateTime,
        );
    }
}
