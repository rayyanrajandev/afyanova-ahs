<?php

namespace App\Modules\Radiology\Application\UseCases;

use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;
use App\Modules\Radiology\Application\Exceptions\RadiologyOrderProcedureCatalogItemNotEligibleException;
use App\Modules\Radiology\Domain\Repositories\RadiologyOrderAuditLogRepositoryInterface;
use App\Modules\Radiology\Domain\Repositories\RadiologyOrderRepositoryInterface;
use App\Modules\Radiology\Domain\Services\RadiologyProcedureCatalogLookupServiceInterface;
use App\Support\ClinicalOrders\ClinicalOrderLifecycle;

class UpdateRadiologyOrderUseCase
{
    public function __construct(
        private readonly RadiologyOrderRepositoryInterface $radiologyOrderRepository,
        private readonly RadiologyOrderAuditLogRepositoryInterface $auditLogRepository,
        private readonly RadiologyProcedureCatalogLookupServiceInterface $radiologyProcedureCatalogLookupService,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    public function execute(string $id, array $payload, ?int $actorId = null): ?array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $existing = $this->radiologyOrderRepository->findById($id);
        if (! $existing) {
            return null;
        }

        ClinicalOrderLifecycle::assertDraftEditable($existing, 'radiology order');

        $working = $existing;
        foreach ($payload as $field => $value) {
            $working[$field] = $value;
        }

        $catalogSelectionChanged = array_key_exists('radiology_procedure_catalog_item_id', $payload)
            || array_key_exists('procedure_code', $payload);

        if ($catalogSelectionChanged) {
            $this->applyCatalogManagedProcedureSelection($working, $payload);
        }

        $updatePayload = [];
        foreach ([
            'ordered_at',
            'modality',
            'clinical_indication',
            'scheduled_for',
        ] as $field) {
            if (array_key_exists($field, $payload)) {
                $updatePayload[$field] = $working[$field];
            }
        }

        if ($catalogSelectionChanged) {
            foreach ([
                'radiology_procedure_catalog_item_id',
                'procedure_code',
                'study_description',
            ] as $field) {
                $updatePayload[$field] = $working[$field] ?? null;
            }
        }

        $updated = $this->radiologyOrderRepository->update($id, $updatePayload);
        if (! $updated) {
            return null;
        }

        $this->auditLogRepository->write(
            radiologyOrderId: $id,
            action: 'radiology-order.updated',
            actorId: $actorId,
            changes: $this->collectChanges($existing, $updated, array_keys($updatePayload)),
        );

        return $updated;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $incomingPayload
     */
    private function applyCatalogManagedProcedureSelection(array &$payload, array $incomingPayload): void
    {
        $catalogItemId = array_key_exists('radiology_procedure_catalog_item_id', $incomingPayload)
            ? trim((string) ($incomingPayload['radiology_procedure_catalog_item_id'] ?? ''))
            : '';
        $procedureCode = array_key_exists('procedure_code', $incomingPayload)
            ? trim((string) ($incomingPayload['procedure_code'] ?? ''))
            : '';

        if ($catalogItemId === '' && $procedureCode === '') {
            $catalogItemId = trim((string) ($payload['radiology_procedure_catalog_item_id'] ?? ''));
            $procedureCode = trim((string) ($payload['procedure_code'] ?? ''));
        }

        $catalogItem = null;
        if ($catalogItemId !== '') {
            $catalogItem = $this->radiologyProcedureCatalogLookupService->findActiveById($catalogItemId);
        } elseif ($procedureCode !== '') {
            $catalogItem = $this->radiologyProcedureCatalogLookupService->findActiveByCode($procedureCode);
        }

        if ($catalogItem === null) {
            throw new RadiologyOrderProcedureCatalogItemNotEligibleException(
                'Selected radiology procedure is not available in the active clinical catalog.'
            );
        }

        $resolvedCatalogItemId = trim((string) ($catalogItem['id'] ?? ''));
        $resolvedProcedureCode = trim((string) ($catalogItem['code'] ?? ''));
        $resolvedStudyDescription = trim((string) ($catalogItem['name'] ?? ''));

        if ($resolvedCatalogItemId === '') {
            throw new RadiologyOrderProcedureCatalogItemNotEligibleException(
                'Selected radiology procedure catalog entry is missing required identifier.'
            );
        }

        if ($resolvedProcedureCode === '' || $resolvedStudyDescription === '') {
            throw new RadiologyOrderProcedureCatalogItemNotEligibleException(
                'Selected radiology procedure catalog entry is missing required code or name.'
            );
        }

        $payload['radiology_procedure_catalog_item_id'] = $resolvedCatalogItemId;
        $payload['procedure_code'] = $resolvedProcedureCode;
        $payload['study_description'] = $resolvedStudyDescription;
    }

    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     * @param array<int, string> $fields
     * @return array<string, array<string, mixed>>
     */
    private function collectChanges(array $before, array $after, array $fields): array
    {
        $changes = [];

        foreach ($fields as $field) {
            $changes[$field] = [
                'before' => $before[$field] ?? null,
                'after' => $after[$field] ?? null,
            ];
        }

        return $changes;
    }
}
