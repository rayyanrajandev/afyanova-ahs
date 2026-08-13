<?php

namespace App\Modules\Platform\Application\UseCases;

use App\Modules\Platform\Application\Exceptions\DuplicateClinicalCatalogCodeException;
use App\Modules\Platform\Application\Services\CatalogDownstreamSyncService;
use App\Modules\Platform\Application\Support\ClinicalCatalogBillingLinkEnricher;
use App\Modules\Platform\Domain\Repositories\ClinicalCatalogItemAuditLogRepositoryInterface;
use App\Modules\Platform\Domain\Repositories\ClinicalCatalogItemRepositoryInterface;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;
use App\Modules\Platform\Domain\ValueObjects\ClinicalCatalogType;
use App\Support\CatalogGovernance\FacilityTierSupport;
use App\Support\CatalogGovernance\StandardsCodeSupport;

class UpdateClinicalCatalogItemUseCase
{
    public function __construct(
        private readonly ClinicalCatalogItemRepositoryInterface $repository,
        private readonly ClinicalCatalogItemAuditLogRepositoryInterface $auditLogRepository,
        private readonly ClinicalCatalogBillingLinkEnricher $billingLinkEnricher,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
        private readonly StandardsCodeSupport $standardsCodeSupport,
        private readonly FacilityTierSupport $facilityTierSupport,
        private readonly CatalogDownstreamSyncService $downstreamSyncService,
    ) {}

    public function execute(string $id, string $catalogType, array $payload, ?int $actorId = null): ?array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $existing = $this->repository->findById($id);
        if (! $existing || ($existing['catalog_type'] ?? null) !== $catalogType) {
            return null;
        }

        $updatePayload = [];

        if (array_key_exists('code', $payload)) {
            $normalizedCode = $this->normalizeCode((string) $payload['code']);
            if ($this->repository->existsByCodeInScope(
                catalogType: $catalogType,
                code: $normalizedCode,
                tenantId: $existing['tenant_id'] ?? null,
                facilityId: $existing['facility_id'] ?? null,
                excludeId: $id,
            )) {
                throw new DuplicateClinicalCatalogCodeException('Catalog code already exists in the current scope.');
            }

            $updatePayload['code'] = $normalizedCode;
        }

        if (array_key_exists('name', $payload)) {
            $updatePayload['name'] = trim((string) $payload['name']);
        }

        if (array_key_exists('facility_tier', $payload)) {
            $updatePayload['facility_tier'] = $this->facilityTierSupport->normalize($payload['facility_tier']);
        }

        if (array_key_exists('department_id', $payload)) {
            $updatePayload['department_id'] = $this->nullableTrimmedValue($payload['department_id']);
        }

        if (array_key_exists('category', $payload)) {
            $updatePayload['category'] = $this->nullableTrimmedValue($payload['category']);
        }

        if (array_key_exists('unit', $payload)) {
            $updatePayload['unit'] = $this->nullableTrimmedValue($payload['unit']);
        }

        if (array_key_exists('description', $payload)) {
            $updatePayload['description'] = $this->nullableTrimmedValue($payload['description']);
        }

        foreach (['generic_name', 'dosage_form', 'strength', 'route', 'storage_conditions', 'controlled_substance_schedule', 'generic_group_code'] as $stringField) {
            if (array_key_exists($stringField, $payload)) {
                $updatePayload[$stringField] = $this->nullableTrimmedValue($payload[$stringField]);
            }
        }

        foreach (['requires_cold_chain', 'is_controlled_substance'] as $boolField) {
            if (array_key_exists($boolField, $payload)) {
                $updatePayload[$boolField] = (bool) $payload[$boolField];
            }
        }

        if (array_key_exists('metadata', $payload)) {
            $updatePayload['metadata'] = is_array($payload['metadata']) ? $payload['metadata'] : null;
        }

        // Inventory_MasterData_Alignment_Plan.md Phase 1: today's admin UI still
        // nests dosageForm/strength/route inside metadata rather than sending the
        // new top-level fields directly. When metadata changes on a formulary item,
        // derive the typed columns from it too -- but only for fields this call
        // didn't already set explicitly above, so an explicit top-level value
        // always wins over a metadata-derived one.
        if ($catalogType === ClinicalCatalogType::FORMULARY_ITEM->value && array_key_exists('metadata', $payload) && is_array($payload['metadata'])) {
            $metadataFieldMap = ['dosage_form' => 'dosageForm', 'strength' => 'strength', 'route' => 'route'];
            foreach ($metadataFieldMap as $snakeField => $metadataKey) {
                if (array_key_exists($snakeField, $updatePayload)) {
                    continue;
                }

                $derived = $this->nullableTrimmedValue($payload['metadata'][$metadataKey] ?? null);
                if ($derived !== null) {
                    $updatePayload[$snakeField] = $derived;
                }
            }
        }

        if (array_key_exists('codes', $payload)) {
            $updatePayload['codes'] = $this->standardsCodeSupport->normalize(is_array($payload['codes']) ? $payload['codes'] : null);
        }

        if (array_key_exists('billing_service_code', $payload)) {
            $baseMetadata = array_key_exists('metadata', $updatePayload)
                ? $updatePayload['metadata']
                : (is_array($existing['metadata'] ?? null) ? $existing['metadata'] : null);

            $updatePayload['metadata'] = $this->metadataWithBillingServiceCode(
                $baseMetadata,
                $payload['billing_service_code'],
            );
        }

        $updated = $this->repository->update($id, $updatePayload);
        if (! $updated) {
            return null;
        }

        $changes = $this->extractChanges($existing, $updated);
        if ($changes !== []) {
            $this->auditLogRepository->write(
                clinicalCatalogItemId: $id,
                action: 'platform.clinical-catalog-item.updated',
                actorId: $actorId,
                changes: $changes,
                metadata: [
                    'catalogType' => $catalogType,
                ],
            );
        }

        if ($catalogType === ClinicalCatalogType::FORMULARY_ITEM->value) {
            $this->downstreamSyncService->syncDownstream($id, $actorId);
        }

        return $this->billingLinkEnricher->enrich($updated);
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
     * @param  array<string, mixed>|null  $metadata
     */
    private function metadataWithBillingServiceCode(?array $metadata, mixed $billingServiceCode): ?array
    {
        $normalizedMetadata = is_array($metadata) ? $metadata : [];
        unset($normalizedMetadata['billingServiceCode'], $normalizedMetadata['billing_service_code']);

        $normalizedCode = $this->nullableTrimmedValue($billingServiceCode);
        if ($normalizedCode !== null) {
            $normalizedMetadata['billingServiceCode'] = strtoupper($normalizedCode);
        }

        return $normalizedMetadata === [] ? null : $normalizedMetadata;
    }

    /**
     * @return array<string, mixed>
     */
    private function extractChanges(array $before, array $after): array
    {
        $trackedFields = [
            'code',
            'name',
            'facility_tier',
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
