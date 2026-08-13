<?php

namespace App\Modules\Laboratory\Application\UseCases;

use App\Modules\Laboratory\Domain\Events\LaboratoryOrderCompleted;
use App\Modules\Laboratory\Domain\Repositories\LaboratoryOrderAuditLogRepositoryInterface;
use App\Modules\Laboratory\Domain\Repositories\LaboratoryOrderRepositoryInterface;
use App\Modules\Laboratory\Domain\ValueObjects\LaboratoryOrderStatus;
use App\Modules\Platform\Application\Services\ClinicalCatalogRecipeStockConsumptionService;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;
use App\Modules\Platform\Domain\ValueObjects\ClinicalCatalogType;
use App\Support\ClinicalOrders\ClinicalOrderLifecycle;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateLaboratoryOrderStatusUseCase
{
    public function __construct(
        private readonly LaboratoryOrderRepositoryInterface $laboratoryOrderRepository,
        private readonly LaboratoryOrderAuditLogRepositoryInterface $auditLogRepository,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
        private readonly ClinicalCatalogRecipeStockConsumptionService $recipeStockConsumptionService,
    ) {}

    public function execute(
        string $id,
        string $status,
        ?string $reason,
        ?string $resultSummary,
        ?int $actorId = null,
        ?array $resultParameters = null,
    ): ?array {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        return DB::transaction(function () use ($id, $status, $reason, $resultSummary, $actorId, $resultParameters): ?array {
            $existing = $this->laboratoryOrderRepository->findById($id);
            if (! $existing) {
                return null;
            }

            ClinicalOrderLifecycle::assertActiveForWorkflow($existing, 'laboratory order');

            $currentStatus = (string) ($existing['status'] ?? '');
            if (! LaboratoryOrderStatus::canTransitionForward($currentStatus, $status)) {
                throw ValidationException::withMessages([
                    'status' => sprintf(
                        'Invalid laboratory workflow transition from %s to %s. Normal flow is forward-only.',
                        $currentStatus !== '' ? str_replace('_', ' ', $currentStatus) : 'unknown status',
                        str_replace('_', ' ', $status),
                    ),
                ]);
            }

            $payload = [
                'status' => $status,
                'status_reason' => $reason,
            ];

            if ($resultSummary !== null) {
                $payload['result_summary'] = $resultSummary;
            }

            if ($resultParameters !== null) {
                $payload['result_parameters'] = $resultParameters;
            }

            if ($status === LaboratoryOrderStatus::COMPLETED->value) {
                $payload['resulted_at'] = now();
            }

            $updated = $this->laboratoryOrderRepository->update($id, $payload);
            if (! $updated) {
                return null;
            }

            if ($status === LaboratoryOrderStatus::COMPLETED->value) {
                $this->recipeStockConsumptionService->consumeForCompletedClinicalWork(
                    clinicalCatalogItemId: $updated['lab_test_catalog_item_id'] ?? null,
                    catalogType: ClinicalCatalogType::LAB_TEST->value,
                    sourceType: 'laboratory_order',
                    sourceId: $id,
                    actorId: $actorId,
                    sourceSnapshot: $updated,
                );
            }

            $this->auditLogRepository->write(
                laboratoryOrderId: $id,
                action: 'laboratory-order.status.updated',
                actorId: $actorId,
                changes: [
                    'status' => [
                        'before' => $existing['status'] ?? null,
                        'after' => $updated['status'] ?? null,
                    ],
                    'status_reason' => [
                        'before' => $existing['status_reason'] ?? null,
                        'after' => $updated['status_reason'] ?? null,
                    ],
                    'result_summary' => [
                        'before' => $existing['result_summary'] ?? null,
                        'after' => $updated['result_summary'] ?? null,
                    ],
                    'result_parameters' => [
                        'before' => $existing['result_parameters'] ?? null,
                        'after' => $updated['result_parameters'] ?? null,
                    ],
                    'resulted_at' => [
                        'before' => $existing['resulted_at'] ?? null,
                        'after' => $updated['resulted_at'] ?? null,
                    ],
                ],
            );

            if ($status === LaboratoryOrderStatus::COMPLETED->value) {
                DB::afterCommit(function () use ($id, $updated, $actorId): void {
                    event(new LaboratoryOrderCompleted(
                        laboratoryOrderId: $id,
                        patientId: (string) $updated['patient_id'],
                        appointmentId: $updated['appointment_id'] ?? null,
                        orderedByUserId: $updated['ordered_by_user_id'] ?? null,
                        actorId: $actorId,
                        facilityId: $updated['facility_id'] ?? null,
                    ));
                });
            }

            return $updated;
        });
    }
}
