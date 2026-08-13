<?php

namespace App\Modules\Platform\Application\UseCases;

use App\Modules\Platform\Domain\Repositories\CrossTenantAdminAuditLogHoldRepositoryInterface;
use App\Modules\Platform\Domain\Repositories\CrossTenantAdminAuditLogRepositoryInterface;
use Illuminate\Support\Carbon;

class ListCrossTenantAdminAuditLogHoldsUseCase
{
    public function __construct(
        private readonly CrossTenantAdminAuditLogHoldRepositoryInterface $holdRepository,
        private readonly CrossTenantAdminAuditLogRepositoryInterface $auditLogRepository,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, mixed>}
     */
    public function execute(array $filters, ?int $actorId): array
    {
        $page = max((int) ($filters['page'] ?? 1), 1);
        $perPage = min(max((int) ($filters['perPage'] ?? 15), 1), 100);

        $sortBy = (string) ($filters['sortBy'] ?? 'createdAt');
        $sortBy = in_array($sortBy, ['createdAt', 'releasedAt'], true) ? $sortBy : 'createdAt';

        $sortDir = strtolower((string) ($filters['sortDir'] ?? 'desc'));
        $sortDir = $sortDir === 'asc' ? 'asc' : 'desc';

        $normalized = [
            'q' => isset($filters['q']) && trim((string) $filters['q']) !== ''
                ? trim((string) $filters['q'])
                : null,
            'holdCode' => isset($filters['holdCode']) && trim((string) $filters['holdCode']) !== ''
                ? strtoupper(trim((string) $filters['holdCode']))
                : null,
            'targetTenantCode' => isset($filters['targetTenantCode']) && trim((string) $filters['targetTenantCode']) !== ''
                ? strtoupper(trim((string) $filters['targetTenantCode']))
                : null,
            'action' => isset($filters['action']) && trim((string) $filters['action']) !== ''
                ? trim((string) $filters['action'])
                : null,
            'approvalCaseReference' => isset($filters['approvalCaseReference']) && trim((string) $filters['approvalCaseReference']) !== ''
                ? trim((string) $filters['approvalCaseReference'])
                : null,
            'approvedByUserId' => isset($filters['approvedByUserId']) ? (int) $filters['approvedByUserId'] : null,
            'createdByUserId' => isset($filters['createdByUserId']) ? (int) $filters['createdByUserId'] : null,
            'releaseCaseReference' => isset($filters['releaseCaseReference']) && trim((string) $filters['releaseCaseReference']) !== ''
                ? trim((string) $filters['releaseCaseReference'])
                : null,
            'releaseApprovedByUserId' => isset($filters['releaseApprovedByUserId']) ? (int) $filters['releaseApprovedByUserId'] : null,
            'releasedByUserId' => isset($filters['releasedByUserId']) ? (int) $filters['releasedByUserId'] : null,
            'createdFrom' => isset($filters['createdFrom']) && trim((string) $filters['createdFrom']) !== ''
                ? Carbon::parse((string) $filters['createdFrom'])->toDateTimeString()
                : null,
            'createdTo' => isset($filters['createdTo']) && trim((string) $filters['createdTo']) !== ''
                ? Carbon::parse((string) $filters['createdTo'])->toDateTimeString()
                : null,
            'releasedFrom' => isset($filters['releasedFrom']) && trim((string) $filters['releasedFrom']) !== ''
                ? Carbon::parse((string) $filters['releasedFrom'])->toDateTimeString()
                : null,
            'releasedTo' => isset($filters['releasedTo']) && trim((string) $filters['releasedTo']) !== ''
                ? Carbon::parse((string) $filters['releasedTo'])->toDateTimeString()
                : null,
            'isActive' => array_key_exists('isActive', $filters) ? filter_var($filters['isActive'], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) : null,
            'sortBy' => $sortBy,
            'sortDir' => $sortDir,
        ];

        $result = $this->holdRepository->list($normalized, $page, $perPage);

        $this->auditLogRepository->write(
            action: 'platform-admin.audit-log-holds.list',
            operationType: 'read',
            actorId: $actorId,
            targetTenantId: null,
            targetTenantCode: $normalized['targetTenantCode'],
            targetResourceType: 'cross_tenant_audit_log_hold',
            targetResourceId: null,
            outcome: 'success',
            reason: null,
            metadata: [
                'filters' => [
                    ...$result['meta']['filters'],
                    'page' => $page,
                    'perPage' => $perPage,
                ],
                'result' => [
                    'total' => $result['meta']['total'] ?? 0,
                ],
            ],
        );

        return $result;
    }
}
