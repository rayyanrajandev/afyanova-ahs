<?php

namespace App\Modules\Staff\Application\UseCases;

use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;
use App\Modules\Staff\Application\Exceptions\DuplicateClinicalPrivilegeCatalogCodeException;
use App\Modules\Staff\Application\Exceptions\UnknownClinicalSpecialtyException;
use App\Modules\Staff\Domain\Repositories\ClinicalPrivilegeCatalogAuditLogRepositoryInterface;
use App\Modules\Staff\Domain\Repositories\ClinicalPrivilegeCatalogRepositoryInterface;
use App\Modules\Staff\Domain\Repositories\ClinicalSpecialtyRepositoryInterface;
use App\Modules\Staff\Domain\ValueObjects\ClinicalPrivilegeCatalogStatus;

class CreateClinicalPrivilegeCatalogUseCase
{
    public function __construct(
        private readonly ClinicalPrivilegeCatalogRepositoryInterface $clinicalPrivilegeCatalogRepository,
        private readonly ClinicalPrivilegeCatalogAuditLogRepositoryInterface $auditLogRepository,
        private readonly ClinicalSpecialtyRepositoryInterface $clinicalSpecialtyRepository,
        private readonly CurrentPlatformScopeContextInterface $platformScopeContext,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    public function execute(array $payload, ?int $actorId = null): array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $tenantId = $this->platformScopeContext->tenantId();
        $code = $this->normalizeCode((string) $payload['code']);
        if ($this->clinicalPrivilegeCatalogRepository->existsCodeInScope($code, $tenantId)) {
            throw new DuplicateClinicalPrivilegeCatalogCodeException('Privilege catalog code already exists for the current scope.');
        }

        $specialtyId = trim((string) ($payload['specialty_id'] ?? ''));
        $specialty = $this->clinicalSpecialtyRepository->findById($specialtyId);
        if (! $specialty) {
            throw new UnknownClinicalSpecialtyException('Clinical specialty is invalid or outside the current scope.');
        }

        $created = $this->clinicalPrivilegeCatalogRepository->create([
            'tenant_id' => $tenantId,
            'specialty_id' => $specialtyId,
            'code' => $code,
            'name' => trim((string) $payload['name']),
            'description' => $this->nullableTrimmedValue($payload['description'] ?? null),
            'cadre_code' => $this->nullableTrimmedValue($payload['cadre_code'] ?? null),
            'facility_type' => $this->nullableTrimmedValue($payload['facility_type'] ?? null),
            'status' => ClinicalPrivilegeCatalogStatus::ACTIVE->value,
            'status_reason' => null,
        ]);

        $this->auditLogRepository->write(
            privilegeCatalogId: $created['id'] ?? null,
            tenantId: $tenantId,
            action: 'privilege-catalog.created',
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
    private function extractTrackedFields(array $catalog): array
    {
        $tracked = ['tenant_id', 'specialty_id', 'code', 'name', 'description', 'cadre_code', 'facility_type', 'status', 'status_reason'];

        $result = [];
        foreach ($tracked as $field) {
            $result[$field] = $catalog[$field] ?? null;
        }

        return $result;
    }
}
