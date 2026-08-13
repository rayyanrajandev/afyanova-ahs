<?php

namespace App\Modules\Radiology\Application\UseCases;

use App\Modules\Platform\Application\Services\ClinicalCatalogRecipeStockConsumptionService;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;
use App\Modules\Platform\Domain\ValueObjects\ClinicalCatalogType;
use App\Modules\Radiology\Domain\Events\RadiologyOrderCompleted;
use App\Modules\Radiology\Domain\Repositories\RadiologyOrderAuditLogRepositoryInterface;
use App\Modules\Radiology\Domain\Repositories\RadiologyOrderRepositoryInterface;
use App\Modules\Radiology\Domain\ValueObjects\RadiologyOrderStatus;
use App\Support\ClinicalOrders\ClinicalOrderLifecycle;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateRadiologyOrderStatusUseCase
{
    public function __construct(
        private readonly RadiologyOrderRepositoryInterface $radiologyOrderRepository,
        private readonly RadiologyOrderAuditLogRepositoryInterface $auditLogRepository,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
        private readonly ClinicalCatalogRecipeStockConsumptionService $recipeStockConsumptionService,
    ) {}

    public function execute(string $id, string $status, ?string $reason, ?string $reportSummary, ?int $actorId = null): ?array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        return DB::transaction(function () use ($id, $status, $reason, $reportSummary, $actorId): ?array {
            $existing = $this->radiologyOrderRepository->findById($id);
            if (! $existing) {
                return null;
            }

            ClinicalOrderLifecycle::assertActiveForWorkflow($existing, 'radiology order');

            $currentStatus = (string) ($existing['status'] ?? '');
            if (! RadiologyOrderStatus::canTransitionForward($currentStatus, $status)) {
                throw ValidationException::withMessages([
                    'status' => sprintf(
                        'Invalid radiology workflow transition from %s to %s. Normal flow is forward-only.',
                        $currentStatus !== '' ? str_replace('_', ' ', $currentStatus) : 'unknown status',
                        str_replace('_', ' ', $status),
                    ),
                ]);
            }

            $payload = [
                'status' => $status,
                'status_reason' => $reason,
            ];

            if ($reportSummary !== null) {
                $payload['report_summary'] = $reportSummary;
            }

            if ($status === RadiologyOrderStatus::COMPLETED->value) {
                $payload['completed_at'] = now();
            }

            $updated = $this->radiologyOrderRepository->update($id, $payload);
            if (! $updated) {
                return null;
            }

            if ($status === RadiologyOrderStatus::COMPLETED->value) {
                $this->recipeStockConsumptionService->consumeForCompletedClinicalWork(
                    clinicalCatalogItemId: $updated['radiology_procedure_catalog_item_id'] ?? null,
                    catalogType: ClinicalCatalogType::RADIOLOGY_PROCEDURE->value,
                    sourceType: 'radiology_order',
                    sourceId: $id,
                    actorId: $actorId,
                    sourceSnapshot: $updated,
                );
            }

            $this->auditLogRepository->write(
                radiologyOrderId: $id,
                action: 'radiology-order.status.updated',
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
                    'report_summary' => [
                        'before' => $existing['report_summary'] ?? null,
                        'after' => $updated['report_summary'] ?? null,
                    ],
                    'completed_at' => [
                        'before' => $existing['completed_at'] ?? null,
                        'after' => $updated['completed_at'] ?? null,
                    ],
                ],
                metadata: [
                    'transition' => [
                        'from' => $existing['status'] ?? null,
                        'to' => $updated['status'] ?? null,
                    ],
                    'completion_report_required' => $status === RadiologyOrderStatus::COMPLETED->value,
                    'completion_report_provided' => ! blank($reportSummary),
                    'cancellation_reason_required' => $status === RadiologyOrderStatus::CANCELLED->value,
                    'cancellation_reason_provided' => ! blank($reason),
                ],
            );

            if ($status === RadiologyOrderStatus::COMPLETED->value) {
                DB::afterCommit(function () use ($id, $updated, $actorId): void {
                    event(new RadiologyOrderCompleted(
                        radiologyOrderId: $id,
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
