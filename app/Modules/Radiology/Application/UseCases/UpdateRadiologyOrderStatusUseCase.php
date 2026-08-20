<?php

namespace App\Modules\Radiology\Application\UseCases;

use App\Modules\Platform\Application\Services\ClinicalCatalogRecipeStockConsumptionService;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;
use App\Modules\Platform\Domain\ValueObjects\ClinicalCatalogType;
use App\Modules\Radiology\Application\Services\RecordRadiologyFlowTransitionService;
use App\Modules\Radiology\Domain\Events\RadiologyOrderCompleted;
use App\Modules\Radiology\Domain\Repositories\RadiologyOrderAuditLogRepositoryInterface;
use App\Modules\Radiology\Domain\Repositories\RadiologyOrderRepositoryInterface;
use App\Modules\Radiology\Domain\ValueObjects\RadiologyOrderStatus;
use App\Modules\Revenue\Application\Services\PrepaidGatePolicy;
use App\Modules\Revenue\Domain\ValueObjects\ChargeSourceKind;
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
        private readonly RecordRadiologyFlowTransitionService $recordFlowTransition,
        private readonly PrepaidGatePolicy $prepaidGate,
    ) {}

    /**
     * Staff-facing write paths for the flow log. Keys are the status being moved
     * *to*; `completed` is what the workspace calls entering a report.
     *
     * Cancellation is deliberately absent: it withdraws work rather than
     * advancing it, and nothing in the flow vocabulary describes that yet —
     * the same choice the laboratory path makes.
     */
    private const FLOW_SOURCES_BY_STATUS = [
        RadiologyOrderStatus::SCHEDULED->value => 'radiology.study_scheduled',
        RadiologyOrderStatus::IN_PROGRESS->value => 'radiology.study_started',
        RadiologyOrderStatus::COMPLETED->value => 'radiology.report_entered',
    ];

    /**
     * @param  string|null  $scheduledFor  The booked slot, carried by the ordered -> scheduled
     *   transition itself. Booking a study *is* that transition, so the slot belongs to it:
     *   splitting them meant two calls, two audit rows, and a window where a study was
     *   'scheduled' for no particular time. The generic edit route can still set it in
     *   isolation for a reschedule, where there is no status change to attach it to.
     */
    public function execute(string $id, string $status, ?string $reason, ?string $reportSummary, ?int $actorId = null, ?string $scheduledFor = null): ?array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        return DB::transaction(function () use ($id, $status, $reason, $reportSummary, $actorId, $scheduledFor): ?array {
            $existing = $this->radiologyOrderRepository->findById($id);
            if (! $existing) {
                return null;
            }

            ClinicalOrderLifecycle::assertActiveForWorkflow($existing, 'radiology order');

            // Prepaid gate. The statuses below are this module's own
            // declaration of what "providing the service" means; the rule
            // itself lives in PrepaidGatePolicy.
            $this->prepaidGate->assertAuthorized(
                kind: ChargeSourceKind::RADIOLOGY_ORDER,
                orderId: $id,
                targetStatus: $status,
                deliveryStatuses: [
                    RadiologyOrderStatus::SCHEDULED->value,
                    RadiologyOrderStatus::IN_PROGRESS->value,
                    RadiologyOrderStatus::COMPLETED->value,
                ],
                refusalMessage: 'Radiology order cannot be processed before payment has been verified.',
            );

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

            if ($scheduledFor !== null) {
                $payload['scheduled_for'] = $scheduledFor;
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

            // Recorded after the update, so the shared resolver sees the new
            // status when it works out where the visit now stands.
            $flowSource = self::FLOW_SOURCES_BY_STATUS[$status] ?? null;
            if ($flowSource !== null) {
                $this->recordFlowTransition->recordForOrder(
                    order: $updated,
                    source: $flowSource,
                    actorId: $actorId,
                    metadata: ['radiology_order_id' => $id],
                );
            }

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
