<?php

namespace App\Modules\Platform\Application\UseCases;

use App\Modules\Billing\Domain\ValueObjects\BillingInvoiceStatus;
use App\Modules\Platform\Domain\Repositories\CrossTenantAdminAuditLogRepositoryInterface;
use App\Modules\Platform\Domain\Repositories\CrossTenantAdminBillingInvoiceReadRepositoryInterface;
use App\Modules\Platform\Domain\Repositories\TenantRepositoryInterface;

class SearchCrossTenantAdminBillingInvoicesUseCase
{
    public function __construct(
        private readonly TenantRepositoryInterface $tenantRepository,
        private readonly CrossTenantAdminBillingInvoiceReadRepositoryInterface $billingInvoiceReadRepository,
        private readonly CrossTenantAdminAuditLogRepositoryInterface $auditLogRepository,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>|null
     */
    public function execute(array $filters, ?int $actorId): ?array
    {
        $targetTenantCode = strtoupper(trim((string) ($filters['targetTenantCode'] ?? '')));
        $reason = trim((string) ($filters['reason'] ?? ''));

        $page = max((int) ($filters['page'] ?? 1), 1);
        $perPage = min(max((int) ($filters['perPage'] ?? 15), 1), 100);

        $status = isset($filters['status']) ? strtolower(trim((string) $filters['status'])) : null;
        if (! in_array($status, BillingInvoiceStatus::values(), true)) {
            $status = null;
        }

        $currencyCode = isset($filters['currencyCode']) ? strtoupper(trim((string) $filters['currencyCode'])) : null;
        $currencyCode = $currencyCode === '' ? null : $currencyCode;

        $query = isset($filters['q']) ? trim((string) $filters['q']) : null;
        $query = $query === '' ? null : $query;

        $patientId = isset($filters['patientId']) ? trim((string) $filters['patientId']) : null;
        $patientId = $patientId === '' ? null : $patientId;

        $fromDateTime = isset($filters['from']) ? trim((string) $filters['from']) : null;
        $fromDateTime = $fromDateTime === '' ? null : $fromDateTime;

        $toDateTime = isset($filters['to']) ? trim((string) $filters['to']) : null;
        $toDateTime = $toDateTime === '' ? null : $toDateTime;

        $sortMap = [
            'invoiceNumber' => 'invoice_number',
            'invoiceDate' => 'invoice_date',
            'totalAmount' => 'total_amount',
            'status' => 'status',
            'createdAt' => 'created_at',
            'updatedAt' => 'updated_at',
        ];
        $requestedSortKey = (string) ($filters['sortBy'] ?? 'invoiceDate');
        $sortBy = $sortMap[$requestedSortKey] ?? 'invoice_date';

        $sortDirection = strtolower((string) ($filters['sortDir'] ?? 'desc'));
        $sortDirection = $sortDirection === 'asc' ? 'asc' : 'desc';

        $tenant = $this->tenantRepository->findByCode($targetTenantCode);

        if ($tenant === null) {
            $this->auditLogRepository->write(
                action: 'platform-admin.billing-invoices.search',
                operationType: 'read',
                actorId: $actorId,
                targetTenantId: null,
                targetTenantCode: $targetTenantCode !== '' ? $targetTenantCode : null,
                targetResourceType: 'billing_invoice',
                targetResourceId: null,
                outcome: 'not_found',
                reason: $reason !== '' ? $reason : null,
                metadata: $this->auditMetadata(
                    query: $query,
                    patientId: $patientId,
                    status: $status,
                    currencyCode: $currencyCode,
                    fromDateTime: $fromDateTime,
                    toDateTime: $toDateTime,
                    page: $page,
                    perPage: $perPage,
                    sortBy: $sortBy,
                    sortDirection: $sortDirection,
                ),
            );

            return null;
        }

        $result = $this->billingInvoiceReadRepository->searchByTenantId(
            tenantId: (string) $tenant['id'],
            query: $query,
            patientId: $patientId,
            status: $status,
            currencyCode: $currencyCode,
            fromDateTime: $fromDateTime,
            toDateTime: $toDateTime,
            page: $page,
            perPage: $perPage,
            sortBy: $sortBy,
            sortDirection: $sortDirection,
        );

        $metadata = $this->auditMetadata(
            query: $query,
            patientId: $patientId,
            status: $status,
            currencyCode: $currencyCode,
            fromDateTime: $fromDateTime,
            toDateTime: $toDateTime,
            page: $page,
            perPage: $perPage,
            sortBy: $sortBy,
            sortDirection: $sortDirection,
        );
        $metadata['result'] = ['total' => $result['meta']['total'] ?? 0];

        $this->auditLogRepository->write(
            action: 'platform-admin.billing-invoices.search',
            operationType: 'read',
            actorId: $actorId,
            targetTenantId: (string) $tenant['id'],
            targetTenantCode: (string) ($tenant['code'] ?? $targetTenantCode),
            targetResourceType: 'billing_invoice',
            targetResourceId: null,
            outcome: 'success',
            reason: $reason !== '' ? $reason : null,
            metadata: $metadata,
        );

        return [
            'data' => $result['data'],
            'meta' => [
                ...$result['meta'],
                'filters' => [
                    'targetTenantCode' => (string) ($tenant['code'] ?? $targetTenantCode),
                    'q' => $query,
                    'patientId' => $patientId,
                    'status' => $status,
                    'currencyCode' => $currencyCode,
                    'from' => $fromDateTime,
                    'to' => $toDateTime,
                    'sortBy' => array_search($sortBy, $sortMap, true) ?: 'invoiceDate',
                    'sortDir' => $sortDirection,
                ],
                'targetTenant' => [
                    'id' => $tenant['id'] ?? null,
                    'code' => $tenant['code'] ?? null,
                    'name' => $tenant['name'] ?? null,
                    'countryCode' => $tenant['country_code'] ?? null,
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function auditMetadata(
        ?string $query,
        ?string $patientId,
        ?string $status,
        ?string $currencyCode,
        ?string $fromDateTime,
        ?string $toDateTime,
        int $page,
        int $perPage,
        string $sortBy,
        string $sortDirection
    ): array {
        return [
            'filters' => [
                'q' => $query,
                'patientId' => $patientId,
                'status' => $status,
                'currencyCode' => $currencyCode,
                'from' => $fromDateTime,
                'to' => $toDateTime,
                'page' => $page,
                'perPage' => $perPage,
                'sortBy' => $sortBy,
                'sortDir' => $sortDirection,
            ],
        ];
    }
}
