<?php

namespace App\Modules\ServiceRequest\Application\UseCases;

use App\Modules\ServiceRequest\Domain\Repositories\ServiceRequestRepositoryInterface;
use App\Modules\ServiceRequest\Domain\ValueObjects\ServiceRequestStatus;
use Illuminate\Validation\ValidationException;

class LinkServiceRequestToClinicalOrderUseCase
{
    public function __construct(
        private readonly ServiceRequestRepositoryInterface $serviceRequestRepository,
        private readonly AppendServiceRequestAuditEventUseCase $appendServiceRequestAuditEvent,
    ) {}

    public function assertLinkable(string $serviceRequestId, string $patientId, string $serviceType): void
    {
        $serviceRequest = $this->loadLinkableRequest($serviceRequestId, $patientId, $serviceType);

        if ((string) ($serviceRequest['status'] ?? '') !== ServiceRequestStatus::IN_PROGRESS->value) {
            throw ValidationException::withMessages([
                'serviceRequestId' => ['Accept the direct service handoff before creating the linked clinical order.'],
            ]);
        }
    }

    public function complete(
        string $serviceRequestId,
        string $patientId,
        string $serviceType,
        string $linkedOrderType,
        string $linkedOrderId,
        ?string $linkedOrderNumber,
        ?int $actorId = null,
    ): void {
        $serviceRequest = $this->loadLinkableRequest($serviceRequestId, $patientId, $serviceType);
        $fromStatus = (string) ($serviceRequest['status'] ?? '');

        if ($fromStatus !== ServiceRequestStatus::IN_PROGRESS->value) {
            return;
        }

        $this->serviceRequestRepository->update($serviceRequestId, [
            'status' => ServiceRequestStatus::COMPLETED->value,
            'completed_at' => now(),
            'status_reason' => 'Clinical order created from direct service ticket.',
            'linked_order_type' => $linkedOrderType,
            'linked_order_id' => $linkedOrderId,
            'linked_order_number' => $linkedOrderNumber,
        ]);

        $this->appendServiceRequestAuditEvent->execute(
            $serviceRequestId,
            'service_request.linked_order_created',
            $actorId,
            $fromStatus,
            ServiceRequestStatus::COMPLETED->value,
            [
                'patientId' => $patientId,
                'serviceType' => $serviceType,
                'linkedOrderType' => $linkedOrderType,
                'linkedOrderId' => $linkedOrderId,
                'linkedOrderNumber' => $linkedOrderNumber,
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function loadLinkableRequest(string $serviceRequestId, string $patientId, string $serviceType): array
    {
        $serviceRequestId = trim($serviceRequestId);
        if ($serviceRequestId === '') {
            throw ValidationException::withMessages([
                'serviceRequestId' => ['Direct service ticket is required.'],
            ]);
        }

        $serviceRequest = $this->serviceRequestRepository->findById($serviceRequestId);
        if ($serviceRequest === null) {
            throw ValidationException::withMessages([
                'serviceRequestId' => ['Direct service ticket was not found.'],
            ]);
        }

        if ((string) ($serviceRequest['patient_id'] ?? '') !== $patientId) {
            throw ValidationException::withMessages([
                'serviceRequestId' => ['Direct service ticket belongs to a different patient.'],
            ]);
        }

        if ((string) ($serviceRequest['service_type'] ?? '') !== $serviceType) {
            throw ValidationException::withMessages([
                'serviceRequestId' => ['Direct service ticket belongs to a different service desk.'],
            ]);
        }

        return $serviceRequest;
    }
}
