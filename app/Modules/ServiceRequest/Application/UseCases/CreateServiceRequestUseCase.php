<?php

namespace App\Modules\ServiceRequest\Application\UseCases;

use App\Modules\Encounter\Application\Services\EncounterResolverService;
use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;
use App\Modules\Pharmacy\Domain\ValueObjects\PharmacyOrderStatus;
use App\Modules\Pharmacy\Infrastructure\Models\PharmacyOrderModel;
use App\Modules\Platform\Infrastructure\Models\ClinicalCatalogItemModel;
use App\Modules\ServiceRequest\Application\Exceptions\ActiveServiceRequestAlreadyExistsException;
use App\Modules\ServiceRequest\Application\Exceptions\CatalogItemNotEligibleForDirectServiceException;
use App\Modules\ServiceRequest\Application\Exceptions\MedicationReferenceNotEligibleForDirectServiceException;
use App\Modules\ServiceRequest\Application\Exceptions\PatientNotEligibleForServiceRequestException;
use App\Modules\ServiceRequest\Domain\Repositories\ServiceRequestItemRepositoryInterface;
use App\Modules\ServiceRequest\Domain\Repositories\ServiceRequestRepositoryInterface;
use App\Modules\ServiceRequest\Domain\Services\PatientLookupServiceInterface;
use App\Modules\ServiceRequest\Domain\ValueObjects\ServiceRequestStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class CreateServiceRequestUseCase
{
    public function __construct(
        private readonly ServiceRequestRepositoryInterface $serviceRequestRepository,
        private readonly ServiceRequestItemRepositoryInterface $itemRepository,
        private readonly PatientLookupServiceInterface $patientLookupService,
        private readonly CurrentPlatformScopeContextInterface $platformScopeContext,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
        private readonly AppendServiceRequestAuditEventUseCase $appendServiceRequestAuditEvent,
        private readonly EncounterResolverService $encounterResolverService,
        private readonly FulfillServiceRequestItemsUseCase $fulfillItemsUseCase,
    ) {}

    public function execute(array $payload, ?int $actorId = null): array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        if (isset($payload['appointment_id'])) {
            $aid = trim((string) $payload['appointment_id']);
            $payload['appointment_id'] = $aid !== '' ? $aid : null;
        }

        $patientId = (string) $payload['patient_id'];
        if (! $this->patientLookupService->patientExists($patientId)) {
            throw new PatientNotEligibleForServiceRequestException(
                'Service request can only be created for an existing patient.',
            );
        }

        $serviceType = (string) $payload['service_type'];
        $activeRequest = $this->serviceRequestRepository->findActiveForPatientAndServiceType($patientId, $serviceType);
        if ($activeRequest !== null) {
            throw new ActiveServiceRequestAlreadyExistsException($activeRequest);
        }

        $items = is_array($payload['items'] ?? null) ? $payload['items'] : [];
        unset($payload['items']);
        $this->assertItemsEligible($items, $patientId);

        $created = DB::transaction(function () use ($payload, $actorId, $serviceType, $items, $patientId): array {
            // Every Direct Service request gets a real Encounter (never an
            // Appointment) so its items/orders/invoice carry the same
            // encounter_id linkage every other visit type already has — see
            // EncounterResolverService::createForDirectService().
            $encounter = $this->encounterResolverService->createForDirectService($patientId, $actorId);
            $payload['encounter_id'] = (string) $encounter->id;

            $payload['status'] = ServiceRequestStatus::IN_PROGRESS->value;
            $payload['request_number'] = $this->generateRequestNumber();
            $payload['tenant_id'] = $this->platformScopeContext->tenantId();
            $payload['facility_id'] = $this->platformScopeContext->facilityId();
            $payload['requested_by_user_id'] = $actorId;
            $payload['requested_at'] = now();
            $payload['acknowledged_at'] = now();
            $payload['acknowledged_by_user_id'] = $actorId;

            if (empty($payload['priority'])) {
                $payload['priority'] = 'routine';
            }

            $created = $this->serviceRequestRepository->create($payload);
            $id = (string) $created['id'];

            $this->appendServiceRequestAuditEvent->execute(
                $id,
                'service_request.created',
                $actorId,
                null,
                ServiceRequestStatus::IN_PROGRESS->value,
                [
                    'patientId' => $created['patient_id'] ?? null,
                    'serviceType' => $created['service_type'] ?? null,
                    'departmentId' => $created['department_id'] ?? null,
                    'requestNumber' => $created['request_number'] ?? null,
                    'itemCount' => count($items),
                ],
            );

            $createdItems = $this->createItems($id, $serviceType, $items);

            return [$created, $createdItems];
        });

        [$created, $createdItems] = $created;

        if ($createdItems !== []) {
            $actorIdInt = is_int($actorId) ? $actorId : 0;
            $this->fulfillItemsUseCase->execute(
                serviceRequestId: (string) $created['id'],
                items: $createdItems,
                actorId: $actorIdInt,
            );
        }

        return $created;
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    private function assertItemsEligible(array $items, string $patientId): void
    {
        if ($items === []) {
            return;
        }

        $catalogItemIds = array_values(array_unique(array_filter(array_map(
            static fn (array $item): ?string => isset($item['catalogItemId']) ? (string) $item['catalogItemId'] : null,
            $items,
        ))));

        $catalogItemsById = ClinicalCatalogItemModel::query()
            ->whereIn('id', $catalogItemIds)
            ->where('direct_service_eligible', true)
            ->get(['id', 'catalog_type', 'refillable_without_prescription'])
            ->keyBy(static fn (ClinicalCatalogItemModel $item): string => (string) $item->id);

        $ineligibleIds = array_diff($catalogItemIds, $catalogItemsById->keys()->all());
        if ($ineligibleIds !== []) {
            throw new CatalogItemNotEligibleForDirectServiceException(
                'One or more selected items are not available for direct service request.',
            );
        }

        foreach ($items as $item) {
            $catalogItemId = isset($item['catalogItemId']) ? (string) $item['catalogItemId'] : '';
            $catalogItem = $catalogItemsById->get($catalogItemId);

            if ($catalogItem === null || $catalogItem->catalog_type !== 'formulary_item') {
                continue;
            }

            $this->assertMedicationReferenceEligible($item, $catalogItem, $catalogItemId, $patientId);
        }
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function assertMedicationReferenceEligible(
        array $item,
        ClinicalCatalogItemModel $catalogItem,
        string $catalogItemId,
        string $patientId,
    ): void {
        $referenceOrderId = isset($item['referencePharmacyOrderId']) ? (string) $item['referencePharmacyOrderId'] : '';

        if ($referenceOrderId === '') {
            throw new MedicationReferenceNotEligibleForDirectServiceException(
                'A medication request must reference a prior dispensed order for the same item.',
            );
        }

        if (! $catalogItem->refillable_without_prescription) {
            throw new MedicationReferenceNotEligibleForDirectServiceException(
                'This medication is not flagged as refillable without a new prescription.',
            );
        }

        $referenceOrder = PharmacyOrderModel::query()->find($referenceOrderId);

        if (
            $referenceOrder === null
            || (string) $referenceOrder->patient_id !== $patientId
            || $referenceOrder->status !== PharmacyOrderStatus::DISPENSED->value
            || (string) $referenceOrder->approved_medicine_catalog_item_id !== $catalogItemId
        ) {
            throw new MedicationReferenceNotEligibleForDirectServiceException(
                'The referenced pharmacy order is not a valid, dispensed order for this patient and medication.',
            );
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    private function createItems(string $serviceRequestId, string $serviceType, array $items): array
    {
        if ($items === []) {
            return [];
        }

        $mapped = array_map(static fn (array $item): array => [
            'service_type' => $item['serviceType'] ?? $item['service_type'] ?? $serviceType,
            'catalog_item_id' => $item['catalogItemId'] ?? $item['catalog_item_id'] ?? null,
            'reference_pharmacy_order_id' => $item['referencePharmacyOrderId'] ?? $item['reference_pharmacy_order_id'] ?? null,
            'item_name' => $item['itemName'] ?? $item['item_name'] ?? '',
            'item_code' => $item['itemCode'] ?? $item['item_code'] ?? null,
            'quantity' => $item['quantity'] ?? 1,
            'clinical_indication' => $item['clinicalIndication'] ?? $item['clinical_indication'] ?? null,
            'status' => 'pending',
            'requested_at' => now(),
        ], $items);

        $this->itemRepository->createMany($serviceRequestId, $mapped);

        return $this->itemRepository->findByServiceRequestId($serviceRequestId);
    }

    private function generateRequestNumber(): string
    {
        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $candidate = 'SR'.now()->format('Ymd').strtoupper(Str::random(6));

            if (! $this->serviceRequestRepository->existsByRequestNumber($candidate)) {
                return $candidate;
            }
        }

        throw new RuntimeException('Unable to generate unique service request number.');
    }
}
