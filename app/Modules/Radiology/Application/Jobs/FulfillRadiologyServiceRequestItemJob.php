<?php

namespace App\Modules\Radiology\Application\Jobs;

use App\Modules\Radiology\Application\UseCases\CreateRadiologyOrderUseCase;
use App\Modules\ServiceRequest\Application\UseCases\AppendServiceRequestAuditEventUseCase;
use App\Modules\ServiceRequest\Application\UseCases\CompleteServiceRequestIfItemsDoneUseCase;
use App\Modules\ServiceRequest\Domain\Repositories\ServiceRequestItemRepositoryInterface;
use App\Modules\ServiceRequest\Domain\ValueObjects\ServiceRequestItemStatus;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class FulfillRadiologyServiceRequestItemJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $serviceRequestItemId,
        private readonly string $catalogItemId,
        private readonly int $quantity,
        private readonly int $actorId,
        private readonly string $serviceRequestId,
        private readonly string $patientId,
        private readonly string $priority = 'routine',
        private readonly ?string $encounterId = null,
    ) {}

    public function handle(
        ServiceRequestItemRepositoryInterface $itemRepository,
        CreateRadiologyOrderUseCase $createOrder,
        AppendServiceRequestAuditEventUseCase $appendAuditEvent,
        CompleteServiceRequestIfItemsDoneUseCase $completeIfDone,
    ): void {
        $item = $itemRepository->findById($this->serviceRequestItemId);

        if ($item === null) {
            return;
        }

        $itemStatus = $item['status'] ?? '';
        if (in_array($itemStatus, [ServiceRequestItemStatus::ORDERED->value, ServiceRequestItemStatus::COMPLETED->value, ServiceRequestItemStatus::CANCELLED->value], true)) {
            return;
        }

        $itemName = (string) ($item['item_name'] ?? '');
        $itemCode = (string) ($item['item_code'] ?? '');

        try {
            $createOrder->execute(
                payload: [
                    'patient_id' => $this->patientId,
                    'encounter_id' => $this->encounterId,
                    'service_request_item_id' => $this->serviceRequestItemId,
                    'radiology_procedure_catalog_item_id' => $this->catalogItemId,
                    'study_description' => $itemName,
                    'procedure_code' => $itemCode,
                    'clinical_indication' => $item['clinical_indication'] ?? null,
                ],
                actorId: $this->actorId,
            );

            $itemRepository->update($this->serviceRequestItemId, [
                'status' => ServiceRequestItemStatus::ORDERED->value,
                'ordered_at' => now(),
            ]);

            $appendAuditEvent->execute(
                $this->serviceRequestId,
                'service_request_item.ordered',
                $this->actorId,
                ServiceRequestItemStatus::PROCESSING->value,
                ServiceRequestItemStatus::ORDERED->value,
                ['item_id' => $this->serviceRequestItemId],
                $this->serviceRequestItemId,
            );
        } catch (\Throwable $e) {
            $itemRepository->update($this->serviceRequestItemId, [
                'status' => ServiceRequestItemStatus::FAILED->value,
                'failed_at' => now(),
                'failure_reason' => $e->getMessage(),
            ]);

            $appendAuditEvent->execute(
                $this->serviceRequestId,
                'service_request_item.failed',
                $this->actorId,
                ServiceRequestItemStatus::PROCESSING->value,
                ServiceRequestItemStatus::FAILED->value,
                ['item_id' => $this->serviceRequestItemId, 'error' => $e->getMessage()],
                $this->serviceRequestItemId,
            );
        }

        $completeIfDone->execute($this->serviceRequestId);
    }
}
