<?php

namespace App\Modules\ClinicalProcedure\Application\UseCases;

use App\Modules\ClinicalProcedure\Domain\Events\ClinicalProcedureOrderCompleted;
use App\Modules\ClinicalProcedure\Domain\Repositories\ClinicalProcedureOrderAuditLogRepositoryInterface;
use App\Modules\ClinicalProcedure\Domain\Repositories\ClinicalProcedureOrderRepositoryInterface;
use App\Modules\ClinicalProcedure\Domain\ValueObjects\ClinicalProcedureOrderStatus;
use App\Modules\Platform\Application\Services\ClinicalCatalogRecipeStockConsumptionService;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;
use App\Modules\Platform\Domain\ValueObjects\ClinicalCatalogType;
use App\Support\ClinicalOrders\ClinicalOrderLifecycle;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateClinicalProcedureOrderStatusUseCase
{
    public function __construct(
        private readonly ClinicalProcedureOrderRepositoryInterface $clinicalProcedureOrderRepository,
        private readonly ClinicalProcedureOrderAuditLogRepositoryInterface $auditLogRepository,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
        private readonly ClinicalCatalogRecipeStockConsumptionService $recipeStockConsumptionService,
    ) {}

    public function execute(string $id, string $status, ?string $reason, ?string $reportSummary, ?int $actorId = null): ?array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        return DB::transaction(function () use ($id, $status, $reason, $reportSummary, $actorId): ?array {
            $existing = $this->clinicalProcedureOrderRepository->findById($id);
            if (! $existing) {
                return null;
            }

            ClinicalOrderLifecycle::assertActiveForWorkflow($existing, 'clinical procedure order');

            $currentStatus = (string) ($existing['status'] ?? '');
            if (! ClinicalProcedureOrderStatus::canTransitionForward($currentStatus, $status)) {
                throw ValidationException::withMessages([
                    'status' => sprintf(
                        'Invalid clinical procedure workflow transition from %s to %s. Normal flow is forward-only.',
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

            if ($status === ClinicalProcedureOrderStatus::COMPLETED->value) {
                $payload['completed_at'] = now();
            }

            $updated = $this->clinicalProcedureOrderRepository->update($id, $payload);
            if (! $updated) {
                return null;
            }

            if ($status === ClinicalProcedureOrderStatus::COMPLETED->value) {
                $this->recipeStockConsumptionService->consumeForCompletedClinicalWork(
                    clinicalCatalogItemId: $updated['clinical_procedure_catalog_item_id'] ?? null,
                    catalogType: ClinicalCatalogType::CLINICAL_PROCEDURE->value,
                    sourceType: 'clinical_procedure_order',
                    sourceId: $id,
                    actorId: $actorId,
                    sourceSnapshot: $updated,
                );
            }

            $this->auditLogRepository->write(
                clinicalProcedureOrderId: $id,
                action: 'clinical-procedure-order.status.updated',
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
                    'completion_report_required' => $status === ClinicalProcedureOrderStatus::COMPLETED->value,
                    'completion_report_provided' => ! blank($reportSummary),
                    'cancellation_reason_required' => $status === ClinicalProcedureOrderStatus::CANCELLED->value,
                    'cancellation_reason_provided' => ! blank($reason),
                ],
            );

            if ($status === ClinicalProcedureOrderStatus::COMPLETED->value) {
                DB::afterCommit(function () use ($id, $updated, $actorId): void {
                    event(new ClinicalProcedureOrderCompleted(
                        clinicalProcedureOrderId: $id,
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
