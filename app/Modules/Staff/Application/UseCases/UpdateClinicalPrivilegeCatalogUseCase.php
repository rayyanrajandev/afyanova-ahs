<?php

namespace App\Modules\Staff\Application\UseCases;

use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;
use App\Modules\Staff\Application\Exceptions\DuplicateClinicalPrivilegeCatalogCodeException;
use App\Modules\Staff\Application\Exceptions\UnknownClinicalSpecialtyException;
use App\Modules\Staff\Domain\Repositories\ClinicalPrivilegeCatalogAuditLogRepositoryInterface;
use App\Modules\Staff\Domain\Repositories\ClinicalPrivilegeCatalogRepositoryInterface;
use App\Modules\Staff\Domain\Repositories\ClinicalSpecialtyRepositoryInterface;

class UpdateClinicalPrivilegeCatalogUseCase
{
    public function __construct(
        private readonly ClinicalPrivilegeCatalogRepositoryInterface $clinicalPrivilegeCatalogRepository,
        private readonly ClinicalPrivilegeCatalogAuditLogRepositoryInterface $auditLogRepository,
        private readonly ClinicalSpecialtyRepositoryInterface $clinicalSpecialtyRepository,
        private readonly CurrentPlatformScopeContextInterface $platformScopeContext,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    public function execute(string $id, array $payload, ?int $actorId = null): ?array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $existing = $this->clinicalPrivilegeCatalogRepository->findById($id);
        if (! $existing) {
            return null;
        }

        $updatePayload = [];
        if (array_key_exists('code', $payload)) {
            $normalizedCode = $this->normalizeCode((string) $payload['code']);
            if ($this->clinicalPrivilegeCatalogRepository->existsCodeInScope(
                code: $normalizedCode,
                tenantId: $existing['tenant_id'] ?? $this->platformScopeContext->tenantId(),
                excludeId: $id,
            )) {
                throw new DuplicateClinicalPrivilegeCatalogCodeException('Privilege catalog code already exists for the current scope.');
            }

            $updatePayload['code'] = $normalizedCode;
        }

        if (array_key_exists('specialty_id', $payload)) {
            $specialtyId = trim((string) $payload['specialty_id']);
            $specialty = $this->clinicalSpecialtyRepository->findById($specialtyId);
            if (! $specialty) {
                throw new UnknownClinicalSpecialtyException('Clinical specialty is invalid or outside the current scope.');
            }

            $updatePayload['specialty_id'] = $specialtyId;
        }

        if (array_key_exists('name', $payload)) {
            $updatePayload['name'] = trim((string) $payload['name']);
        }

        if (array_key_exists('description', $payload)) {
            $updatePayload['description'] = $this->nullableTrimmedValue($payload['description']);
        }

        if (array_key_exists('cadre_code', $payload)) {
            $updatePayload['cadre_code'] = $this->nullableTrimmedValue($payload['cadre_code']);
        }

        if (array_key_exists('facility_type', $payload)) {
            $updatePayload['facility_type'] = $this->nullableTrimmedValue($payload['facility_type']);
        }

        $updated = $this->clinicalPrivilegeCatalogRepository->update($id, $updatePayload);
        if (! $updated) {
            return null;
        }

        $changes = $this->extractChanges($existing, $updated);
        if ($changes !== []) {
            $this->auditLogRepository->write(
                privilegeCatalogId: $id,
                tenantId: $this->platformScopeContext->tenantId(),
                action: 'privilege-catalog.updated',
                actorId: $actorId,
                changes: $changes,
            );
        }

        return $updated;
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
    private function extractChanges(array $before, array $after): array
    {
        $trackedFields = ['specialty_id', 'code', 'name', 'description', 'cadre_code', 'facility_type'];

        $changes = [];
        foreach ($trackedFields as $field) {
            $beforeValue = $before[$field] ?? null;
            $afterValue = $after[$field] ?? null;
            if ($beforeValue === $afterValue) {
                continue;
            }

            $changes[$field] = [
                'before' => $beforeValue,
                'after' => $afterValue,
            ];
        }

        return $changes;
    }
}
