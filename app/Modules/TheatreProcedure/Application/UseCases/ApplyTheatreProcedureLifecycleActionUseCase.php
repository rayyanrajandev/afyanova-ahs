<?php

namespace App\Modules\TheatreProcedure\Application\UseCases;

use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;
use App\Modules\TheatreProcedure\Domain\Repositories\TheatreProcedureAuditLogRepositoryInterface;
use App\Modules\TheatreProcedure\Domain\Repositories\TheatreProcedureRepositoryInterface;
use App\Modules\TheatreProcedure\Domain\ValueObjects\TheatreProcedureStatus;
use App\Support\ClinicalOrders\ClinicalOrderLifecycle;
use Illuminate\Validation\ValidationException;

class ApplyTheatreProcedureLifecycleActionUseCase
{
    public function __construct(
        private readonly TheatreProcedureRepositoryInterface $theatreProcedureRepository,
        private readonly TheatreProcedureAuditLogRepositoryInterface $auditLogRepository,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    public function execute(string $id, string $action, string $reason, ?int $actorId = null): ?array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $existing = $this->theatreProcedureRepository->findById($id);
        if (! $existing) {
            return null;
        }

        ClinicalOrderLifecycle::assertActiveForWorkflow($existing, 'theatre procedure');

        $normalizedAction = trim(strtolower($action));
        $reason = trim($reason);
        $currentStatus = trim((string) ($existing['status'] ?? ''));

        if ($reason === '') {
            throw ValidationException::withMessages([
                'reason' => ['A clinical reason is required for this lifecycle action.'],
            ]);
        }

        $payload = [
            'status_reason' => $reason,
        ];
        $auditAction = '';

        if ($normalizedAction === 'cancel') {
            if (in_array($currentStatus, [
                TheatreProcedureStatus::COMPLETED->value,
                TheatreProcedureStatus::CANCELLED->value,
            ], true)) {
                throw ValidationException::withMessages([
                    'action' => ['Completed or cancelled theatre procedures cannot be cancelled again.'],
                ]);
            }

            $payload['status'] = TheatreProcedureStatus::CANCELLED->value;
            $payload['lifecycle_reason_code'] = 'cancelled';
            $auditAction = 'theatre-procedure.lifecycle.cancelled';
        } elseif ($normalizedAction === 'entered_in_error') {
            if (ClinicalOrderLifecycle::isEnteredInError($existing)) {
                throw ValidationException::withMessages([
                    'action' => ['This theatre procedure is already marked entered in error.'],
                ]);
            }

            $payload['status'] = TheatreProcedureStatus::CANCELLED->value;
            $payload['lifecycle_reason_code'] = 'entered_in_error';
            $payload['entered_in_error_at'] = now();
            $payload['entered_in_error_by_user_id'] = $actorId;
            $auditAction = 'theatre-procedure.lifecycle.entered-in-error';
        } else {
            throw ValidationException::withMessages([
                'action' => ['Unsupported theatre lifecycle action.'],
            ]);
        }

        $updated = $this->theatreProcedureRepository->update($id, $payload);
        if (! $updated) {
            return null;
        }

        $this->auditLogRepository->write(
            theatreProcedureId: $id,
            action: $auditAction,
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
                'lifecycle_reason_code' => [
                    'before' => $existing['lifecycle_reason_code'] ?? null,
                    'after' => $updated['lifecycle_reason_code'] ?? null,
                ],
                'entered_in_error_at' => [
                    'before' => $existing['entered_in_error_at'] ?? null,
                    'after' => $updated['entered_in_error_at'] ?? null,
                ],
                'entered_in_error_by_user_id' => [
                    'before' => $existing['entered_in_error_by_user_id'] ?? null,
                    'after' => $updated['entered_in_error_by_user_id'] ?? null,
                ],
            ],
            metadata: [
                'lifecycle_action' => $normalizedAction,
            ],
        );

        return $updated;
    }
}
