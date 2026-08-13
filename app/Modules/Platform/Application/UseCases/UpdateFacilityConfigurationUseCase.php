<?php

namespace App\Modules\Platform\Application\UseCases;

use App\Modules\Platform\Application\Exceptions\DuplicateFacilityCodeException;
use App\Modules\Platform\Domain\Repositories\FacilityConfigurationAuditLogRepositoryInterface;
use App\Modules\Platform\Domain\Repositories\FacilityConfigurationRepositoryInterface;
use App\Modules\Platform\Domain\Repositories\TenantRepositoryInterface;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;

class UpdateFacilityConfigurationUseCase
{
    public function __construct(
        private readonly FacilityConfigurationRepositoryInterface $facilityConfigurationRepository,
        private readonly FacilityConfigurationAuditLogRepositoryInterface $auditLogRepository,
        private readonly TenantRepositoryInterface $tenantRepository,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    public function execute(string $id, array $payload, ?int $actorId = null): ?array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $existing = $this->facilityConfigurationRepository->findById($id);
        if (! $existing) {
            return null;
        }

        $updatePayload = [];

        if (array_key_exists('code', $payload)) {
            $normalizedCode = $this->normalizeCode((string) $payload['code']);
            $tenantId = (string) ($existing['tenant_id'] ?? '');

            if ($tenantId !== '' && $this->facilityConfigurationRepository->existsCodeInTenant(
                tenantId: $tenantId,
                code: $normalizedCode,
                excludeId: $id,
            )) {
                throw new DuplicateFacilityCodeException('Facility code already exists in the current tenant scope.');
            }

            $updatePayload['code'] = $normalizedCode;
        }

        if (array_key_exists('name', $payload)) {
            $updatePayload['name'] = trim((string) $payload['name']);
        }

        if (array_key_exists('facility_type', $payload)) {
            $updatePayload['facility_type'] = $this->nullableTrimmedValue($payload['facility_type']);
        }

        if (array_key_exists('timezone', $payload)) {
            $updatePayload['timezone'] = $this->nullableTrimmedValue($payload['timezone']);
        }

        $tenantId = trim((string) ($existing['tenant_id'] ?? ''));
        $existingTenant = $tenantId !== '' ? $this->tenantRepository->findById($tenantId) : null;
        $tenantUpdatePayload = [];

        if (array_key_exists('tenant_allowed_country_codes', $payload)) {
            $normalizedCountryCodes = $this->normalizeCountryCodes($payload['tenant_allowed_country_codes']);
            $tenantUpdatePayload['allowed_country_codes'] = $normalizedCountryCodes !== [] ? $normalizedCountryCodes : null;
        }

        $updated = $existing;
        if ($updatePayload !== []) {
            $updated = $this->facilityConfigurationRepository->update($id, $updatePayload);
            if (! $updated) {
                return null;
            }
        }

        $changes = $this->extractChanges($existing, $updated);
        if ($changes !== []) {
            $this->auditLogRepository->write(
                facilityId: $id,
                action: 'platform.facilities.updated',
                actorId: $actorId,
                changes: $changes,
            );
        }

        if ($tenantUpdatePayload !== [] && $tenantId !== '') {
            $updatedTenant = $this->tenantRepository->updateById($tenantId, $tenantUpdatePayload);
            $tenantPolicyChanges = $this->extractTenantCountryPolicyChanges($existingTenant, $updatedTenant);

            if ($tenantPolicyChanges !== []) {
                $this->auditLogRepository->write(
                    facilityId: $id,
                    action: 'platform.facilities.tenant-country-policy.updated',
                    actorId: $actorId,
                    changes: $tenantPolicyChanges,
                    metadata: [
                        'tenant_id' => $tenantId,
                        'tenant_code' => $updatedTenant['code'] ?? $existingTenant['code'] ?? null,
                    ],
                );
            }
        }

        return $this->facilityConfigurationRepository->findById($id) ?? $updated;
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
     * @param  mixed  $value
     * @return array<int, string>
     */
    private function normalizeCountryCodes(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(function (mixed $countryCode): ?string {
            if (! is_string($countryCode)) {
                return null;
            }

            $normalized = strtoupper(trim($countryCode));

            return $normalized !== '' ? $normalized : null;
        }, $value))));
    }

    /**
     * @return array<string, mixed>
     */
    private function extractChanges(array $before, array $after): array
    {
        $trackedFields = [
            'code',
            'name',
            'facility_type',
            'timezone',
        ];

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

    /**
     * @return array<string, mixed>
     */
    private function extractTenantCountryPolicyChanges(?array $beforeTenant, ?array $afterTenant): array
    {
        $beforeValue = $this->normalizeCountryCodes($beforeTenant['allowed_country_codes'] ?? null);
        $afterValue = $this->normalizeCountryCodes($afterTenant['allowed_country_codes'] ?? null);

        if ($beforeValue === $afterValue) {
            return [];
        }

        return [
            'tenant_allowed_country_codes' => [
                'before' => $beforeValue !== [] ? $beforeValue : null,
                'after' => $afterValue !== [] ? $afterValue : null,
            ],
        ];
    }
}
