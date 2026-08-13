<?php

namespace App\Modules\TheatreProcedure\Application\UseCases;

use App\Modules\Platform\Application\Services\ClinicalCatalogRecipeStockConsumptionService;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;
use App\Modules\Platform\Domain\ValueObjects\ClinicalCatalogType;
use App\Modules\TheatreProcedure\Domain\Repositories\TheatreProcedureAuditLogRepositoryInterface;
use App\Modules\TheatreProcedure\Domain\Repositories\TheatreProcedureRepositoryInterface;
use App\Modules\TheatreProcedure\Domain\ValueObjects\TheatreProcedureStatus;
use App\Support\ClinicalOrders\ClinicalOrderLifecycle;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateTheatreProcedureStatusUseCase
{
    public function __construct(
        private readonly TheatreProcedureRepositoryInterface $theatreProcedureRepository,
        private readonly TheatreProcedureAuditLogRepositoryInterface $auditLogRepository,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
        private readonly ClinicalCatalogRecipeStockConsumptionService $recipeStockConsumptionService,
    ) {}

    public function execute(
        string $id,
        string $status,
        ?string $reason,
        ?string $startedAt,
        ?string $completedAt,
        ?int $actorId = null
    ): ?array {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        return DB::transaction(function () use ($id, $status, $reason, $startedAt, $completedAt, $actorId): ?array {
            $existing = $this->theatreProcedureRepository->findById($id);
            if (! $existing) {
                return null;
            }

            ClinicalOrderLifecycle::assertActiveForWorkflow($existing, 'theatre procedure');

            $currentStatus = (string) ($existing['status'] ?? '');
            if (! TheatreProcedureStatus::canTransitionForward($currentStatus, $status)) {
                throw ValidationException::withMessages([
                    'status' => sprintf(
                        'Invalid theatre workflow transition from %s to %s. Normal flow is forward-only.',
                        $currentStatus !== '' ? str_replace('_', ' ', $currentStatus) : 'unknown status',
                        str_replace('_', ' ', $status),
                    ),
                ]);
            }

            $payload = [
                'status' => $status,
                'status_reason' => $reason,
            ];

            if ($status === TheatreProcedureStatus::IN_PROGRESS->value) {
                $payload['started_at'] = $startedAt ?? ($existing['started_at'] ?? now());
                $payload['completed_at'] = null;
            }

            if ($status === TheatreProcedureStatus::COMPLETED->value) {
                $payload['started_at'] = $startedAt ?? ($existing['started_at'] ?? now());
                $payload['completed_at'] = $completedAt ?? now();
            }

            if ($status === TheatreProcedureStatus::PLANNED->value || $status === TheatreProcedureStatus::IN_PREOP->value) {
                $payload['completed_at'] = null;
            }

            if ($status === TheatreProcedureStatus::CANCELLED->value) {
                $payload['completed_at'] = null;
            }

            $updated = $this->theatreProcedureRepository->update($id, $payload);
            if (! $updated) {
                return null;
            }

            if ($status === TheatreProcedureStatus::COMPLETED->value) {
                $this->recipeStockConsumptionService->consumeForCompletedClinicalWork(
                    clinicalCatalogItemId: $updated['theatre_procedure_catalog_item_id'] ?? null,
                    catalogType: ClinicalCatalogType::THEATRE_PROCEDURE->value,
                    sourceType: 'theatre_procedure',
                    sourceId: $id,
                    actorId: $actorId,
                    sourceSnapshot: $updated,
                );
            }

            $this->auditLogRepository->write(
                theatreProcedureId: $id,
                action: 'theatre-procedure.status.updated',
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
                    'started_at' => [
                        'before' => $existing['started_at'] ?? null,
                        'after' => $updated['started_at'] ?? null,
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
                    'completion_evidence_required' => $status === TheatreProcedureStatus::COMPLETED->value,
                    'completion_evidence_provided' => ($updated['completed_at'] ?? null) !== null,
                    'cancellation_reason_required' => $status === TheatreProcedureStatus::CANCELLED->value,
                    'cancellation_reason_provided' => trim((string) ($updated['status_reason'] ?? '')) !== '',
                ],
            );

            return $updated;
        });
    }
}
