<?php

namespace App\Modules\Staff\Application\UseCases;

use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;
use App\Modules\Staff\Application\Exceptions\DuplicateClinicalSpecialtyCodeException;
use App\Modules\Staff\Domain\Repositories\ClinicalSpecialtyAuditLogRepositoryInterface;
use App\Modules\Staff\Domain\Repositories\ClinicalSpecialtyRepositoryInterface;
use App\Modules\Staff\Domain\ValueObjects\ClinicalSpecialtyStatus;

class CreateClinicalSpecialtyUseCase
{
    public function __construct(
        private readonly ClinicalSpecialtyRepositoryInterface $clinicalSpecialtyRepository,
        private readonly ClinicalSpecialtyAuditLogRepositoryInterface $auditLogRepository,
        private readonly CurrentPlatformScopeContextInterface $platformScopeContext,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    public function execute(array $payload, ?int $actorId = null): array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $tenantId = $this->platformScopeContext->tenantId();
        $code = $this->normalizeCode((string) $payload['code']);
        if ($this->clinicalSpecialtyRepository->existsCodeInScope($code, $tenantId)) {
            throw new DuplicateClinicalSpecialtyCodeException('Specialty code already exists for the current scope.');
        }

        $created = $this->clinicalSpecialtyRepository->create([
            'tenant_id' => $tenantId,
            'code' => $code,
            'name' => trim((string) $payload['name']),
            'description' => $this->nullableTrimmedValue($payload['description'] ?? null),
            'status' => ClinicalSpecialtyStatus::ACTIVE->value,
            'status_reason' => null,
        ]);

        $this->auditLogRepository->write(
            specialtyId: $created['id'] ?? null,
            tenantId: $tenantId,
            staffProfileId: null,
            action: 'specialty.created',
            actorId: $actorId,
            changes: [
                'after' => $this->extractTrackedFields($created),
            ],
        );

        return $created;
    }

    private function normalizeCode(string $value): string
    {
        return strtoupper(trim($value));
    }

    private function nullableTrimmedValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    /**
     * @return array<string, mixed>
     */
    private function extractTrackedFields(array $specialty): array
    {
        $tracked = ['tenant_id', 'code', 'name', 'description', 'status', 'status_reason'];

        $result = [];
        foreach ($tracked as $field) {
            $result[$field] = $specialty[$field] ?? null;
        }

        return $result;
    }
}

