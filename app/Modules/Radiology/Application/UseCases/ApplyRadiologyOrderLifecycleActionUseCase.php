<?php

namespace App\Modules\Radiology\Application\UseCases;

use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;
use App\Modules\Radiology\Domain\Repositories\RadiologyOrderAuditLogRepositoryInterface;
use App\Modules\Radiology\Domain\Repositories\RadiologyOrderRepositoryInterface;
use App\Modules\Radiology\Domain\ValueObjects\RadiologyOrderStatus;
use App\Support\ClinicalOrders\ClinicalOrderLifecycle;
use Illuminate\Validation\ValidationException;

class ApplyRadiologyOrderLifecycleActionUseCase
{
    public function __construct(
        private readonly RadiologyOrderRepositoryInterface $radiologyOrderRepository,
        private readonly RadiologyOrderAuditLogRepositoryInterface $auditLogRepository,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    public function execute(string $id, string $action, string $reason, ?int $actorId = null): ?array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $existing = $this->radiologyOrderRepository->findById($id);
        if (! $existing) {
            return null;
        }

        ClinicalOrderLifecycle::assertActiveForWorkflow($existing, 'radiology order');

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
                RadiologyOrderStatus::COMPLETED->value,
                RadiologyOrderStatus::CANCELLED->value,
            ], true)) {
                throw ValidationException::withMessages([
                    'action' => ['Completed or cancelled imaging orders cannot be cancelled again.'],
                ]);
            }

            $payload['status'] = RadiologyOrderStatus::CANCELLED->value;
            $payload['lifecycle_reason_code'] = 'cancelled';
            $auditAction = 'radiology-order.lifecycle.cancelled';
        } elseif ($normalizedAction === 'entered_in_error') {
            if (ClinicalOrderLifecycle::isEnteredInError($existing)) {
                throw ValidationException::withMessages([
                    'action' => ['This imaging order is already marked entered in error.'],
                ]);
            }

            $payload['status'] = RadiologyOrderStatus::CANCELLED->value;
            $payload['lifecycle_reason_code'] = 'entered_in_error';
            $payload['entered_in_error_at'] = now();
            $payload['entered_in_error_by_user_id'] = $actorId;
            $auditAction = 'radiology-order.lifecycle.entered-in-error';
        } else {
            throw ValidationException::withMessages([
                'action' => ['Unsupported imaging lifecycle action.'],
            ]);
        }

        $updated = $this->radiologyOrderRepository->update($id, $payload);
        if (! $updated) {
            return null;
        }

        $this->auditLogRepository->write(
            radiologyOrderId: $id,
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
