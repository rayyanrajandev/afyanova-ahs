<?php

namespace App\Modules\Platform\Application\UseCases;

use App\Modules\Platform\Application\Exceptions\DuplicateClinicalCatalogCodeException;
use App\Modules\Platform\Application\Services\CatalogDownstreamSyncService;
use App\Modules\Platform\Application\Support\ClinicalCatalogBillingLinkEnricher;
use App\Modules\Platform\Domain\Repositories\ClinicalCatalogItemAuditLogRepositoryInterface;
use App\Modules\Platform\Domain\Repositories\ClinicalCatalogItemRepositoryInterface;
use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;
use App\Modules\Platform\Domain\ValueObjects\ClinicalCatalogItemStatus;
use App\Modules\Platform\Domain\ValueObjects\ClinicalCatalogType;
use App\Support\CatalogGovernance\FacilityTierSupport;
use App\Support\CatalogGovernance\StandardsCodeSupport;

class CreateClinicalCatalogItemUseCase
{
    public function __construct(
        private readonly ClinicalCatalogItemRepositoryInterface $repository,
        private readonly ClinicalCatalogItemAuditLogRepositoryInterface $auditLogRepository,
        private readonly ClinicalCatalogBillingLinkEnricher $billingLinkEnricher,
        private readonly CurrentPlatformScopeContextInterface $platformScopeContext,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
        private readonly StandardsCodeSupport $standardsCodeSupport,
        private readonly FacilityTierSupport $facilityTierSupport,
        private readonly CatalogDownstreamSyncService $downstreamSyncService,
    ) {}

    public function execute(string $catalogType, array $payload, ?int $actorId = null): array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $tenantId = $this->platformScopeContext->tenantId();
        $facilityId = $this->platformScopeContext->facilityId();
        $code = $this->normalizeCode((string) $payload['code']);

        if ($this->repository->existsByCodeInScope($catalogType, $code, $tenantId, $facilityId)) {
            throw new DuplicateClinicalCatalogCodeException('Catalog code already exists in the current scope.');
        }

        $name = trim((string) $payload['name']);
        $metadataForDerivation = is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [];
        $strength = $this->resolveClinicalField($payload, 'strength', $catalogType, $metadataForDerivation);
        $created = $this->repository->create([
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'facility_tier' => $this->facilityTierSupport->normalize($payload['facility_tier'] ?? null),
            'catalog_type' => $catalogType,
            'code' => $code,
            'name' => $name,
            'generic_name' => $this->resolveClinicalField($payload, 'generic_name', $catalogType, $metadataForDerivation)
                ?? ($catalogType === ClinicalCatalogType::FORMULARY_ITEM->value ? $this->deriveGenericName($name, $strength ?? '') : null),
            'dosage_form' => $this->resolveClinicalField($payload, 'dosage_form', $catalogType, $metadataForDerivation),
            'strength' => $strength,
            'route' => $this->resolveClinicalField($payload, 'route', $catalogType, $metadataForDerivation),
            'storage_conditions' => $this->nullableTrimmedValue($payload['storage_conditions'] ?? null),
            // Tri-state: null means "no opinion yet", not false -- see the Phase 1
            // migration's column comment. Only set when the caller actually sent one.
            'requires_cold_chain' => array_key_exists('requires_cold_chain', $payload) ? (bool) $payload['requires_cold_chain'] : null,
            'is_controlled_substance' => array_key_exists('is_controlled_substance', $payload) ? (bool) $payload['is_controlled_substance'] : null,
            'controlled_substance_schedule' => $this->nullableTrimmedValue($payload['controlled_substance_schedule'] ?? null),
            'generic_group_code' => $this->nullableTrimmedValue($payload['generic_group_code'] ?? null),
            'department_id' => $this->nullableTrimmedValue($payload['department_id'] ?? null),
            'category' => $this->nullableTrimmedValue($payload['category'] ?? null),
            'unit' => $this->nullableTrimmedValue($payload['unit'] ?? null),
            'description' => $this->nullableTrimmedValue($payload['description'] ?? null),
            'metadata' => $this->metadataWithBillingServiceCode(
                is_array($payload['metadata'] ?? null) ? $payload['metadata'] : null,
                $payload['billing_service_code'] ?? null,
                array_key_exists('billing_service_code', $payload),
            ),
            'codes' => $this->standardsCodeSupport->normalize(is_array($payload['codes'] ?? null) ? $payload['codes'] : null),
            'status' => ClinicalCatalogItemStatus::ACTIVE->value,
            'status_reason' => null,
        ]);

        $this->auditLogRepository->write(
            clinicalCatalogItemId: $created['id'],
            action: 'platform.clinical-catalog-item.created',
            actorId: $actorId,
            changes: [
                'after' => $this->extractTrackedFields($created),
            ],
            metadata: [
                'catalogType' => $catalogType,
            ],
        );

        if ($catalogType === ClinicalCatalogType::FORMULARY_ITEM->value) {
            $this->downstreamSyncService->syncDownstream((string) $created['id'], $actorId);
        }

        return $this->billingLinkEnricher->enrich($created);
    }

    private function normalizeCode(string $value): string
    {
        return strtoupper(trim($value));
    }

    /**
     * Inventory_MasterData_Alignment_Plan.md Phase 1: the admin UI doesn't
     * send these as top-level fields yet for formulary items -- it still
     * nests dosageForm/strength/route inside metadata (see
     * clinical-catalogs/IndexV2.vue's metadataFieldsForDomain()). Prefer an
     * explicit top-level value when the caller sends one (API clients,
     * future UI work); otherwise derive from metadata so today's UI keeps
     * populating the new typed columns with zero frontend changes.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $metadata
     */
    private function resolveClinicalField(array $payload, string $snakeField, string $catalogType, array $metadata): ?string
    {
        $explicit = $this->nullableTrimmedValue($payload[$snakeField] ?? null);
        if ($explicit !== null) {
            return $explicit;
        }

        if ($catalogType !== ClinicalCatalogType::FORMULARY_ITEM->value) {
            return null;
        }

        $metadataKey = match ($snakeField) {
            'dosage_form' => 'dosageForm',
            'strength' => 'strength',
            'route' => 'route',
            default => null,
        };

        if ($metadataKey === null) {
            return null;
        }

        return $this->nullableTrimmedValue($metadata[$metadataKey] ?? null);
    }

    /**
     * Mirrors genericNameFromClinicalName() in
     * resources/js/pages/inventory-procurement/stock-control/IndexV2.vue.
     */
    private function deriveGenericName(string $name, string $strength): string
    {
        $withoutStrength = $name;
        if (trim($strength) !== '') {
            $pattern = '/\s*'.preg_quote(trim($strength), '/').'\s*$/i';
            $withoutStrength = trim((string) preg_replace($pattern, '', $name));
        }

        $withoutTrailingNumber = trim((string) preg_replace('/\s+\d+.*$/u', '', $withoutStrength));

        return $withoutTrailingNumber !== '' ? $withoutTrailingNumber : trim($name);
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
     * @param  array<string, mixed>|null  $metadata
     */
    private function metadataWithBillingServiceCode(?array $metadata, mixed $billingServiceCode, bool $shouldApply): ?array
    {
        $normalizedMetadata = is_array($metadata) ? $metadata : [];

        if ($shouldApply) {
            unset($normalizedMetadata['billingServiceCode'], $normalizedMetadata['billing_service_code']);

            $normalizedCode = $this->nullableTrimmedValue($billingServiceCode);
            if ($normalizedCode !== null) {
                $normalizedMetadata['billingServiceCode'] = strtoupper($normalizedCode);
            }
        }

        return $normalizedMetadata === [] ? null : $normalizedMetadata;
    }

    /**
     * @return array<string, mixed>
     */
    private function extractTrackedFields(array $item): array
    {
        $tracked = [
            'tenant_id',
            'facility_id',
            'facility_tier',
            'catalog_type',
            'code',
            'name',
            'generic_name',
            'dosage_form',
            'strength',
            'route',
            'storage_conditions',
            'requires_cold_chain',
            'is_controlled_substance',
            'controlled_substance_schedule',
            'generic_group_code',
            'department_id',
            'category',
            'unit',
            'description',
            'metadata',
            'codes',
            'status',
            'status_reason',
        ];

        $result = [];
        foreach ($tracked as $field) {
            $result[$field] = $item[$field] ?? null;
        }

        return $result;
    }
}
