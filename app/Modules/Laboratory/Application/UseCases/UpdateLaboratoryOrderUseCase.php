<?php

namespace App\Modules\Laboratory\Application\UseCases;

use App\Modules\Laboratory\Application\Exceptions\LaboratoryOrderTestCatalogItemNotEligibleException;
use App\Modules\Laboratory\Domain\Repositories\LaboratoryOrderAuditLogRepositoryInterface;
use App\Modules\Laboratory\Domain\Repositories\LaboratoryOrderRepositoryInterface;
use App\Modules\Laboratory\Domain\Services\LabTestCatalogLookupServiceInterface;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;
use App\Support\ClinicalOrders\ClinicalOrderLifecycle;

class UpdateLaboratoryOrderUseCase
{
    public function __construct(
        private readonly LaboratoryOrderRepositoryInterface $laboratoryOrderRepository,
        private readonly LaboratoryOrderAuditLogRepositoryInterface $auditLogRepository,
        private readonly LabTestCatalogLookupServiceInterface $labTestCatalogLookupService,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    public function execute(string $id, array $payload, ?int $actorId = null): ?array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $existing = $this->laboratoryOrderRepository->findById($id);
        if (! $existing) {
            return null;
        }

        ClinicalOrderLifecycle::assertDraftEditable($existing, 'laboratory order');

        $working = $existing;
        foreach ($payload as $field => $value) {
            $working[$field] = $value;
        }

        $catalogSelectionChanged = array_key_exists('lab_test_catalog_item_id', $payload)
            || array_key_exists('test_code', $payload);

        if ($catalogSelectionChanged) {
            $this->applyCatalogManagedLabTestSelection($working, $payload);
        }

        $updatePayload = [];
        foreach ([
            'ordered_at',
            'priority',
            'specimen_type',
            'clinical_notes',
        ] as $field) {
            if (array_key_exists($field, $payload)) {
                $updatePayload[$field] = $working[$field];
            }
        }

        if ($catalogSelectionChanged) {
            foreach ([
                'lab_test_catalog_item_id',
                'test_code',
                'test_name',
            ] as $field) {
                $updatePayload[$field] = $working[$field] ?? null;
            }
        }

        $updated = $this->laboratoryOrderRepository->update($id, $updatePayload);
        if (! $updated) {
            return null;
        }

        $this->auditLogRepository->write(
            laboratoryOrderId: $id,
            action: 'laboratory-order.updated',
            actorId: $actorId,
            changes: $this->collectChanges($existing, $updated, array_keys($updatePayload)),
        );

        return $updated;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $incomingPayload
     */
    private function applyCatalogManagedLabTestSelection(array &$payload, array $incomingPayload): void
    {
        $catalogItemId = array_key_exists('lab_test_catalog_item_id', $incomingPayload)
            ? trim((string) ($incomingPayload['lab_test_catalog_item_id'] ?? ''))
            : '';
        $testCode = array_key_exists('test_code', $incomingPayload)
            ? trim((string) ($incomingPayload['test_code'] ?? ''))
            : '';

        if ($catalogItemId === '' && $testCode === '') {
            $catalogItemId = trim((string) ($payload['lab_test_catalog_item_id'] ?? ''));
            $testCode = trim((string) ($payload['test_code'] ?? ''));
        }

        $catalogItem = null;
        if ($catalogItemId !== '') {
            $catalogItem = $this->labTestCatalogLookupService->findActiveById($catalogItemId);
        } elseif ($testCode !== '') {
            $catalogItem = $this->labTestCatalogLookupService->findActiveByCode($testCode);
        }

        if ($catalogItem === null) {
            throw new LaboratoryOrderTestCatalogItemNotEligibleException(
                'Selected laboratory test is not available in the active clinical catalog.'
            );
        }

        $resolvedCatalogItemId = trim((string) ($catalogItem['id'] ?? ''));
        $resolvedTestCode = trim((string) ($catalogItem['code'] ?? ''));
        $resolvedTestName = trim((string) ($catalogItem['name'] ?? ''));

        if ($resolvedCatalogItemId === '') {
            throw new LaboratoryOrderTestCatalogItemNotEligibleException(
                'Selected laboratory test catalog entry is missing required identifier.'
            );
        }

        if ($resolvedTestCode === '' || $resolvedTestName === '') {
            throw new LaboratoryOrderTestCatalogItemNotEligibleException(
                'Selected laboratory test catalog entry is missing required code or name.'
            );
        }

        $payload['lab_test_catalog_item_id'] = $resolvedCatalogItemId;
        $payload['test_code'] = $resolvedTestCode;
        $payload['test_name'] = $resolvedTestName;
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @param  array<int, string>  $fields
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
