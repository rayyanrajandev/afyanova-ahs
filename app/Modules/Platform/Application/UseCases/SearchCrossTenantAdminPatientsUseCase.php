<?php

namespace App\Modules\Platform\Application\UseCases;

use App\Modules\Patient\Domain\ValueObjects\PatientStatus;
use App\Modules\Platform\Domain\Repositories\CrossTenantAdminAuditLogRepositoryInterface;
use App\Modules\Platform\Domain\Repositories\CrossTenantAdminPatientReadRepositoryInterface;
use App\Modules\Platform\Domain\Repositories\TenantRepositoryInterface;

class SearchCrossTenantAdminPatientsUseCase
{
    public function __construct(
        private readonly TenantRepositoryInterface $tenantRepository,
        private readonly CrossTenantAdminPatientReadRepositoryInterface $patientReadRepository,
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
        if (! in_array($status, PatientStatus::values(), true)) {
            $status = null;
        }

        $sortMap = [
            'patientNumber' => 'patient_number',
            'firstName' => 'first_name',
            'lastName' => 'last_name',
            'createdAt' => 'created_at',
            'updatedAt' => 'updated_at',
        ];
        $sortBy = $sortMap[(string) ($filters['sortBy'] ?? 'createdAt')] ?? 'created_at';

        $sortDirection = strtolower((string) ($filters['sortDir'] ?? 'desc'));
        $sortDirection = $sortDirection === 'asc' ? 'asc' : 'desc';

        $query = isset($filters['q']) ? trim((string) $filters['q']) : null;
        $query = $query === '' ? null : $query;

        $tenant = $this->tenantRepository->findByCode($targetTenantCode);

        if ($tenant === null) {
            $this->auditLogRepository->write(
                action: 'platform-admin.patients.search',
                operationType: 'read',
                actorId: $actorId,
                targetTenantId: null,
                targetTenantCode: $targetTenantCode !== '' ? $targetTenantCode : null,
                targetResourceType: 'patient',
                targetResourceId: null,
                outcome: 'not_found',
                reason: $reason !== '' ? $reason : null,
                metadata: [
                    'filters' => [
                        'q' => $query,
                        'status' => $status,
                        'page' => $page,
                        'perPage' => $perPage,
                        'sortBy' => $sortBy,
                        'sortDir' => $sortDirection,
                    ],
                ],
            );

            return null;
        }

        $result = $this->patientReadRepository->searchByTenantId(
            tenantId: (string) $tenant['id'],
            query: $query,
            status: $status,
            page: $page,
            perPage: $perPage,
            sortBy: $sortBy,
            sortDirection: $sortDirection,
        );

        $this->auditLogRepository->write(
            action: 'platform-admin.patients.search',
            operationType: 'read',
            actorId: $actorId,
            targetTenantId: (string) $tenant['id'],
            targetTenantCode: (string) ($tenant['code'] ?? $targetTenantCode),
            targetResourceType: 'patient',
            targetResourceId: null,
            outcome: 'success',
            reason: $reason !== '' ? $reason : null,
            metadata: [
                'filters' => [
                    'q' => $query,
                    'status' => $status,
                    'page' => $page,
                    'perPage' => $perPage,
                    'sortBy' => $sortBy,
                    'sortDir' => $sortDirection,
                ],
                'result' => [
                    'total' => $result['meta']['total'] ?? 0,
                ],
            ],
        );

        return [
            'data' => $result['data'],
            'meta' => [
                ...$result['meta'],
                'filters' => [
                    'targetTenantCode' => (string) ($tenant['code'] ?? $targetTenantCode),
                    'q' => $query,
                    'status' => $status,
                    'sortBy' => array_search($sortBy, $sortMap, true) ?: 'createdAt',
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
}
