<?php

namespace App\Modules\ServiceRequest\Application\UseCases;

use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;
use App\Modules\ServiceRequest\Application\Exceptions\ServiceRequestDepartmentScopeException;
use App\Modules\ServiceRequest\Application\Exceptions\ServiceRequestStatusTransitionException;
use App\Modules\ServiceRequest\Application\Services\ServiceRequestDepartmentScope;
use App\Modules\ServiceRequest\Domain\Events\ServiceRequestStatusChanged;
use App\Modules\ServiceRequest\Domain\Repositories\ServiceRequestRepositoryInterface;
use App\Modules\ServiceRequest\Domain\ValueObjects\ServiceRequestServiceType;
use App\Modules\ServiceRequest\Domain\ValueObjects\ServiceRequestStatus;
use Illuminate\Support\Facades\DB;

class UpdateServiceRequestStatusUseCase
{
    public function __construct(
        private readonly ServiceRequestRepositoryInterface $serviceRequestRepository,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
        private readonly AppendServiceRequestAuditEventUseCase $appendServiceRequestAuditEvent,
        private readonly FulfillServiceRequestItemsUseCase $fulfillItemsUseCase,
    ) {}

    public function execute(
        string $id,
        string $newStatus,
        ServiceRequestDepartmentScope $scope,
        ?int $actorId = null,
        ?string $statusReason = null,
        ?string $linkedOrderNumber = null,
    ): ?array {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $existing = $this->serviceRequestRepository->findById($id);
        if (! $existing) {
            return null;
        }

        $this->assertWithinDepartmentScope($existing, $scope);

        $currentStatus = ServiceRequestStatus::tryFrom((string) ($existing['status'] ?? ''));
        if ($currentStatus === null || ! $currentStatus->canTransitionTo($newStatus)) {
            throw new ServiceRequestStatusTransitionException(
                sprintf(
                    "Cannot transition service request from '%s' to '%s'.",
                    $existing['status'] ?? 'unknown',
                    $newStatus,
                ),
            );
        }

        $previousStatus = (string) ($existing['status'] ?? 'unknown');
        $normalizedStatusReason = is_string($statusReason) ? trim($statusReason) : null;
        $normalizedStatusReason = $normalizedStatusReason === '' ? null : $normalizedStatusReason;

        $normalizedLinkedOrderType = ServiceRequestServiceType::tryFrom((string) ($existing['service_type'] ?? ''))?->linkedOrderType();
        $normalizedLinkedOrderNumber = is_string($linkedOrderNumber) ? trim($linkedOrderNumber) : null;
        $normalizedLinkedOrderNumber = $normalizedLinkedOrderNumber === '' ? null : $normalizedLinkedOrderNumber;

        $existingLinkedOrderId = $existing['linked_order_id'] ?? null;
        $existingLinkedOrderNumber = $existing['linked_order_number'] ?? null;
        $hasExistingLink = ! empty($existingLinkedOrderId) || ! empty($existingLinkedOrderNumber);
        $hasNewLink = $normalizedLinkedOrderNumber !== null;

        if (
            $newStatus === ServiceRequestStatus::COMPLETED->value
            && ! $hasExistingLink
            && ! $hasNewLink
        ) {
            throw new ServiceRequestStatusTransitionException(
                'Provide a destination order number or link a work record before closing this direct service ticket.',
            );
        }

        $payload = ['status' => $newStatus];

        if ($newStatus === ServiceRequestStatus::IN_PROGRESS->value) {
            $payload['acknowledged_at'] = now();
            $payload['acknowledged_by_user_id'] = $actorId;
        }

        if (in_array($newStatus, [ServiceRequestStatus::COMPLETED->value, ServiceRequestStatus::CANCELLED->value], true)) {
            $payload['completed_at'] = now();
            $payload['status_reason'] = $normalizedStatusReason;
        }

        if ($newStatus === ServiceRequestStatus::COMPLETED->value && $hasNewLink) {
            $payload['linked_order_type'] = $normalizedLinkedOrderType;
            $payload['linked_order_number'] = $normalizedLinkedOrderNumber;
        }

        $resolvedLinkedOrderType = $normalizedLinkedOrderType ?? ($existing['linked_order_type'] ?? null);
        $resolvedLinkedOrderNumber = $normalizedLinkedOrderNumber ?? ($existing['linked_order_number'] ?? null);
        $resolvedLinkedOrderId = $existingLinkedOrderId;

        $updated = DB::transaction(function () use ($id, $payload, $actorId, $previousStatus, $newStatus, $normalizedStatusReason, $resolvedLinkedOrderType, $resolvedLinkedOrderNumber, $resolvedLinkedOrderId): ?array {
            $updated = $this->serviceRequestRepository->update($id, $payload);
            if ($updated === null) {
                return null;
            }

            $metadata = array_filter([
                'statusReason' => $normalizedStatusReason,
                'linkedOrderType' => $resolvedLinkedOrderType,
                'linkedOrderId' => $resolvedLinkedOrderId,
                'linkedOrderNumber' => $resolvedLinkedOrderNumber,
            ], static fn (mixed $value): bool => $value !== null && $value !== '');

            $this->appendServiceRequestAuditEvent->execute(
                $id,
                'service_request.status_updated',
                $actorId,
                $previousStatus,
                $newStatus,
                $metadata === [] ? null : $metadata,
            );

            return $updated;
        });

        if ($updated !== null) {
            DB::afterCommit(function () use ($updated, $previousStatus, $newStatus, $actorId): void {
                event(new ServiceRequestStatusChanged(
                    serviceRequestId: (string) $updated['id'],
                    patientId: (string) $updated['patient_id'],
                    oldStatus: $previousStatus,
                    newStatus: $newStatus,
                    actorId: $actorId,
                    facilityId: $updated['facility_id'] ?? null,
                ));
            });
        }

        if ($updated !== null && $newStatus === ServiceRequestStatus::IN_PROGRESS->value) {
            $items = $updated['items'] ?? [];
            if (! empty($items)) {
                $this->fulfillItemsUseCase->execute($id, $items, (int) $actorId);
            }
        }

        return $updated;
    }

    private function assertWithinDepartmentScope(array $existing, ServiceRequestDepartmentScope $scope): void
    {
        if ($scope->canViewAllDepartments) {
            return;
        }

        if ($scope->hasNoAssignedDepartment()) {
            throw new ServiceRequestDepartmentScopeException(
                'Your account has no department assigned — contact an administrator before managing direct service tickets.',
            );
        }

        $ticketDepartmentId = $existing['department_id'] ?? null;
        if ($ticketDepartmentId !== $scope->departmentId) {
            throw new ServiceRequestDepartmentScopeException(
                'This direct service ticket belongs to a different department.',
            );
        }
    }
}
