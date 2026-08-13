<?php

namespace App\Modules\Pharmacy\Application\UseCases;

use App\Modules\Pharmacy\Domain\Repositories\PharmacyOrderAuditLogRepositoryInterface;
use App\Modules\Pharmacy\Domain\Repositories\PharmacyOrderRepositoryInterface;
use App\Modules\Pharmacy\Domain\ValueObjects\PharmacyOrderStatus;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;
use App\Support\ClinicalOrders\ClinicalOrderLifecycle;
use Illuminate\Validation\ValidationException;

class ApplyPharmacyOrderLifecycleActionUseCase
{
    public function __construct(
        private readonly PharmacyOrderRepositoryInterface $pharmacyOrderRepository,
        private readonly PharmacyOrderAuditLogRepositoryInterface $auditLogRepository,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    public function execute(string $id, string $action, string $reason, ?int $actorId = null): ?array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $existing = $this->pharmacyOrderRepository->findById($id);
        if (! $existing) {
            return null;
        }

        ClinicalOrderLifecycle::assertActiveForWorkflow($existing, 'pharmacy order');

        $normalizedAction = trim(strtolower($action));
        $reason = trim($reason);
        $currentStatus = trim((string) ($existing['status'] ?? ''));
        $quantityDispensed = round((float) ($existing['quantity_dispensed'] ?? 0), 2);

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
            if ($currentStatus === PharmacyOrderStatus::CANCELLED->value) {
                throw ValidationException::withMessages([
                    'action' => ['This pharmacy order is already cancelled.'],
                ]);
            }

            if ($currentStatus === PharmacyOrderStatus::DISPENSED->value || $quantityDispensed > 0) {
                throw ValidationException::withMessages([
                    'action' => ['Pharmacy orders with dispensed stock must be discontinued, not cancelled.'],
                ]);
            }

            $payload['status'] = PharmacyOrderStatus::CANCELLED->value;
            $payload['lifecycle_reason_code'] = 'cancelled';
            $auditAction = 'pharmacy-order.lifecycle.cancelled';
        } elseif ($normalizedAction === 'discontinue') {
            if (in_array($currentStatus, [
                PharmacyOrderStatus::DISPENSED->value,
                PharmacyOrderStatus::CANCELLED->value,
            ], true)) {
                throw ValidationException::withMessages([
                    'action' => ['Dispensed or cancelled pharmacy orders cannot be discontinued.'],
                ]);
            }

            $payload['status'] = PharmacyOrderStatus::CANCELLED->value;
            $payload['lifecycle_reason_code'] = 'discontinued';
            $auditAction = 'pharmacy-order.lifecycle.discontinued';
        } elseif ($normalizedAction === 'entered_in_error') {
            if (ClinicalOrderLifecycle::isEnteredInError($existing)) {
                throw ValidationException::withMessages([
                    'action' => ['This pharmacy order is already marked entered in error.'],
                ]);
            }

            $payload['status'] = PharmacyOrderStatus::CANCELLED->value;
            $payload['lifecycle_reason_code'] = 'entered_in_error';
            $payload['entered_in_error_at'] = now();
            $payload['entered_in_error_by_user_id'] = $actorId;
            $auditAction = 'pharmacy-order.lifecycle.entered-in-error';
        } else {
            throw ValidationException::withMessages([
                'action' => ['Unsupported pharmacy lifecycle action.'],
            ]);
        }

        $updated = $this->pharmacyOrderRepository->update($id, $payload);
        if (! $updated) {
            return null;
        }

        $this->auditLogRepository->write(
            pharmacyOrderId: $id,
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
                'quantity_dispensed_at_action' => $quantityDispensed,
            ],
        );

        return $updated;
    }
}
