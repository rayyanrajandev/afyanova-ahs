<?php

namespace App\Modules\Platform\Application\UseCases;

use App\Modules\Platform\Domain\Repositories\CrossTenantAdminAuditLogRepositoryInterface;

class ListCrossTenantAdminAuditLogsUseCase
{
    public function __construct(private readonly CrossTenantAdminAuditLogRepositoryInterface $auditLogRepository) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, mixed>}
     */
    public function execute(array $filters): array
    {
        $page = max((int) ($filters['page'] ?? 1), 1);
        $perPage = min(max((int) ($filters['perPage'] ?? 20), 1), 100);

        $normalized = [
            'action' => $this->nullableTrimmedString($filters, 'action'),
            'operationType' => $this->normalizeEnum($filters, 'operationType', ['read', 'write']),
            'targetTenantCode' => $this->nullableUpperString($filters, 'targetTenantCode'),
            'targetResourceType' => $this->nullableTrimmedString($filters, 'targetResourceType'),
            'outcome' => $this->normalizeEnum($filters, 'outcome', ['success', 'not_found', 'forbidden', 'validation_error', 'error']),
            'actorId' => isset($filters['actorId']) && $filters['actorId'] !== '' ? (int) $filters['actorId'] : null,
        ];

        return $this->auditLogRepository->list($normalized, $page, $perPage);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function nullableTrimmedString(array $filters, string $key): ?string
    {
        if (! array_key_exists($key, $filters)) {
            return null;
        }

        $value = trim((string) $filters[$key]);

        return $value === '' ? null : $value;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function nullableUpperString(array $filters, string $key): ?string
    {
        $value = $this->nullableTrimmedString($filters, $key);

        return $value === null ? null : strtoupper($value);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  array<int, string>  $allowed
     */
    private function normalizeEnum(array $filters, string $key, array $allowed): ?string
    {
        $value = $this->nullableTrimmedString($filters, $key);
        if ($value === null) {
            return null;
        }

        $value = strtolower($value);

        return in_array($value, $allowed, true) ? $value : null;
    }
}
