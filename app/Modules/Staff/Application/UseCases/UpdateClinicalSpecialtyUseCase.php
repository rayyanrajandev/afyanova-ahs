<?php

namespace App\Modules\Staff\Application\UseCases;

use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;
use App\Modules\Staff\Application\Exceptions\DuplicateClinicalSpecialtyCodeException;
use App\Modules\Staff\Domain\Repositories\ClinicalSpecialtyAuditLogRepositoryInterface;
use App\Modules\Staff\Domain\Repositories\ClinicalSpecialtyRepositoryInterface;

class UpdateClinicalSpecialtyUseCase
{
    public function __construct(
        private readonly ClinicalSpecialtyRepositoryInterface $clinicalSpecialtyRepository,
        private readonly ClinicalSpecialtyAuditLogRepositoryInterface $auditLogRepository,
        private readonly CurrentPlatformScopeContextInterface $platformScopeContext,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    public function execute(string $id, array $payload, ?int $actorId = null): ?array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $existing = $this->clinicalSpecialtyRepository->findById($id);
        if (! $existing) {
            return null;
        }

        $updatePayload = [];
        if (array_key_exists('code', $payload)) {
            $normalizedCode = $this->normalizeCode((string) $payload['code']);
            if ($this->clinicalSpecialtyRepository->existsCodeInScope(
                code: $normalizedCode,
                tenantId: $existing['tenant_id'] ?? $this->platformScopeContext->tenantId(),
                excludeId: $id,
            )) {
                throw new DuplicateClinicalSpecialtyCodeException('Specialty code already exists for the current scope.');
            }

            $updatePayload['code'] = $normalizedCode;
        }

        if (array_key_exists('name', $payload)) {
            $updatePayload['name'] = trim((string) $payload['name']);
        }

        if (array_key_exists('description', $payload)) {
            $updatePayload['description'] = $this->nullableTrimmedValue($payload['description']);
        }

        $updated = $this->clinicalSpecialtyRepository->update($id, $updatePayload);
        if (! $updated) {
            return null;
        }

        $changes = $this->extractChanges($existing, $updated);
        if ($changes !== []) {
            $this->auditLogRepository->write(
                specialtyId: $id,
                tenantId: $this->platformScopeContext->tenantId(),
                staffProfileId: null,
                action: 'specialty.updated',
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
        $trackedFields = ['code', 'name', 'description'];

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

