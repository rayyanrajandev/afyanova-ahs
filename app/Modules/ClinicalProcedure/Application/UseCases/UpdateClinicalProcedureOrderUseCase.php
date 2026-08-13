<?php

namespace App\Modules\ClinicalProcedure\Application\UseCases;

use App\Modules\ClinicalProcedure\Application\Exceptions\ClinicalProcedureOrderProcedureCatalogItemNotEligibleException;
use App\Modules\ClinicalProcedure\Domain\Repositories\ClinicalProcedureOrderAuditLogRepositoryInterface;
use App\Modules\ClinicalProcedure\Domain\Repositories\ClinicalProcedureOrderRepositoryInterface;
use App\Modules\ClinicalProcedure\Domain\Services\ClinicalProcedureCatalogLookupServiceInterface;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;
use App\Support\ClinicalOrders\ClinicalOrderLifecycle;

class UpdateClinicalProcedureOrderUseCase
{
    public function __construct(
        private readonly ClinicalProcedureOrderRepositoryInterface $clinicalProcedureOrderRepository,
        private readonly ClinicalProcedureOrderAuditLogRepositoryInterface $auditLogRepository,
        private readonly ClinicalProcedureCatalogLookupServiceInterface $clinicalProcedureCatalogLookupService,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    public function execute(string $id, array $payload, ?int $actorId = null): ?array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $existing = $this->clinicalProcedureOrderRepository->findById($id);
        if (! $existing) {
            return null;
        }

        ClinicalOrderLifecycle::assertDraftEditable($existing, 'clinical procedure order');

        $working = $existing;
        foreach ($payload as $field => $value) {
            $working[$field] = $value;
        }

        $catalogSelectionChanged = array_key_exists('clinical_procedure_catalog_item_id', $payload)
            || array_key_exists('procedure_code', $payload);

        if ($catalogSelectionChanged) {
            $this->applyCatalogManagedProcedureSelection($working, $payload);
        }

        $updatePayload = [];
        foreach ([
            'ordered_at',
            'procedure_setting',
            'clinical_indication',
            'scheduled_for',
        ] as $field) {
            if (array_key_exists($field, $payload)) {
                $updatePayload[$field] = $working[$field];
            }
        }

        if ($catalogSelectionChanged) {
            foreach ([
                'clinical_procedure_catalog_item_id',
                'procedure_code',
                'procedure_description',
            ] as $field) {
                $updatePayload[$field] = $working[$field] ?? null;
            }
        }

        $updated = $this->clinicalProcedureOrderRepository->update($id, $updatePayload);
        if (! $updated) {
            return null;
        }

        $this->auditLogRepository->write(
            clinicalProcedureOrderId: $id,
            action: 'clinical-procedure-order.updated',
            actorId: $actorId,
            changes: $this->collectChanges($existing, $updated, array_keys($updatePayload)),
        );

        return $updated;
    }

    private function applyCatalogManagedProcedureSelection(array &$payload, array $incomingPayload): void
    {
        $catalogItemId = array_key_exists('clinical_procedure_catalog_item_id', $incomingPayload)
            ? trim((string) ($incomingPayload['clinical_procedure_catalog_item_id'] ?? ''))
            : '';
        $procedureCode = array_key_exists('procedure_code', $incomingPayload)
            ? trim((string) ($incomingPayload['procedure_code'] ?? ''))
            : '';

        if ($catalogItemId === '' && $procedureCode === '') {
            $catalogItemId = trim((string) ($payload['clinical_procedure_catalog_item_id'] ?? ''));
            $procedureCode = trim((string) ($payload['procedure_code'] ?? ''));
        }

        $catalogItem = null;
        if ($catalogItemId !== '') {
            $catalogItem = $this->clinicalProcedureCatalogLookupService->findActiveById($catalogItemId);
        } elseif ($procedureCode !== '') {
            $catalogItem = $this->clinicalProcedureCatalogLookupService->findActiveByCode($procedureCode);
        }

        if ($catalogItem === null) {
            throw new ClinicalProcedureOrderProcedureCatalogItemNotEligibleException(
                'Selected clinical procedure is not available in the active clinical catalog.'
            );
        }

        $resolvedCatalogItemId = trim((string) ($catalogItem['id'] ?? ''));
        $resolvedProcedureCode = trim((string) ($catalogItem['code'] ?? ''));
        $resolvedProcedureDescription = trim((string) ($catalogItem['name'] ?? ''));

        if ($resolvedCatalogItemId === '') {
            throw new ClinicalProcedureOrderProcedureCatalogItemNotEligibleException(
                'Selected clinical procedure catalog entry is missing required identifier.'
            );
        }

        if ($resolvedProcedureCode === '' || $resolvedProcedureDescription === '') {
            throw new ClinicalProcedureOrderProcedureCatalogItemNotEligibleException(
                'Selected clinical procedure catalog entry is missing required code or name.'
            );
        }

        $payload['clinical_procedure_catalog_item_id'] = $resolvedCatalogItemId;
        $payload['procedure_code'] = $resolvedProcedureCode;
        $payload['procedure_description'] = $resolvedProcedureDescription;
    }

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
