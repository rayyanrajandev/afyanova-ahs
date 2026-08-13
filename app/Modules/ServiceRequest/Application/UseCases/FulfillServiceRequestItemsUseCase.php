<?php

namespace App\Modules\ServiceRequest\Application\UseCases;

use App\Modules\ClinicalProcedure\Application\Jobs\FulfillClinicalProcedureServiceRequestItemJob;
use App\Modules\Laboratory\Application\Jobs\FulfillLaboratoryServiceRequestItemJob;
use App\Modules\Pharmacy\Application\Jobs\FulfillPharmacyServiceRequestItemJob;
use App\Modules\Radiology\Application\Jobs\FulfillRadiologyServiceRequestItemJob;
use App\Modules\ServiceRequest\Domain\Repositories\ServiceRequestItemRepositoryInterface;
use App\Modules\ServiceRequest\Domain\Repositories\ServiceRequestRepositoryInterface;
use App\Modules\ServiceRequest\Domain\ValueObjects\ServiceRequestItemStatus;

class FulfillServiceRequestItemsUseCase
{
    private const SERVICE_TYPE_JOB_MAP = [
        'laboratory' => FulfillLaboratoryServiceRequestItemJob::class,
        'pharmacy' => FulfillPharmacyServiceRequestItemJob::class,
        'radiology' => FulfillRadiologyServiceRequestItemJob::class,
        'clinical_procedure' => FulfillClinicalProcedureServiceRequestItemJob::class,
    ];

    public function __construct(
        private readonly ServiceRequestItemRepositoryInterface $itemRepository,
        private readonly ServiceRequestRepositoryInterface $serviceRequestRepository,
        private readonly AppendServiceRequestAuditEventUseCase $appendAuditEvent,
    ) {}

    public function execute(string $serviceRequestId, array $items, int $actorId): void
    {
        $sr = $this->serviceRequestRepository->findById($serviceRequestId);
        $patientId = $sr ? (string) ($sr['patient_id'] ?? '') : '';

        if ($patientId === '') {
            return;
        }

        $priority = (string) ($sr['priority'] ?? 'routine');
        $encounterId = is_string($sr['encounter_id'] ?? null) && $sr['encounter_id'] !== '' ? $sr['encounter_id'] : null;

        foreach ($items as $item) {
            if (($item['status'] ?? '') !== ServiceRequestItemStatus::PENDING->value) {
                continue;
            }

            $itemId = (string) $item['id'];
            $serviceType = (string) ($item['service_type'] ?? '');
            $jobClass = self::SERVICE_TYPE_JOB_MAP[$serviceType] ?? null;

            if ($jobClass === null) {
                $this->markUnfulfillable($serviceRequestId, $itemId, $actorId, "Unsupported service type: {$serviceType}");
                continue;
            }

            // Defense-in-depth: both entry points that create items
            // (CreateServiceRequestUseCase for Direct Service,
            // CompleteNurseAssessmentUseCase/ResolveAppointmentDirectlyUseCase
            // for nurse-resolved) now require catalogItemId at the
            // validation layer, so this should be unreachable — but if it
            // ever is, fail loudly (FAILED + reason) rather than silently
            // leaving the item PENDING forever behind a success response.
            if ($item['catalog_item_id'] === null || $item['catalog_item_id'] === '') {
                $this->markUnfulfillable($serviceRequestId, $itemId, $actorId, 'No catalog item linked to this request item.');
                continue;
            }

            $this->itemRepository->update($itemId, [
                'status' => ServiceRequestItemStatus::PROCESSING->value,
            ]);

            $this->appendAuditEvent->execute(
                $serviceRequestId,
                'service_request_item.fulfillment_started',
                $actorId,
                ServiceRequestItemStatus::PENDING->value,
                ServiceRequestItemStatus::PROCESSING->value,
                ['item_id' => $itemId, 'service_type' => $serviceType],
                $itemId,
            );

            dispatch_sync(new $jobClass(
                serviceRequestItemId: $itemId,
                catalogItemId: (string) $item['catalog_item_id'],
                quantity: (int) ($item['quantity'] ?? 1),
                actorId: $actorId,
                serviceRequestId: $serviceRequestId,
                patientId: $patientId,
                priority: $priority,
                encounterId: $encounterId,
            ));
        }
    }

    private function markUnfulfillable(string $serviceRequestId, string $itemId, int $actorId, string $reason): void
    {
        $this->itemRepository->update($itemId, [
            'status' => ServiceRequestItemStatus::FAILED->value,
            'failed_at' => now(),
            'failure_reason' => $reason,
        ]);

        $this->appendAuditEvent->execute(
            $serviceRequestId,
            'service_request_item.failed',
            $actorId,
            ServiceRequestItemStatus::PENDING->value,
            ServiceRequestItemStatus::FAILED->value,
            ['item_id' => $itemId, 'error' => $reason],
            $itemId,
        );
    }
}
