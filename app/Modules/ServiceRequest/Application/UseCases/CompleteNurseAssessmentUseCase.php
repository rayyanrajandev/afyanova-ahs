<?php

namespace App\Modules\ServiceRequest\Application\UseCases;

use App\Modules\Encounter\Application\Services\EncounterResolverService;
use App\Modules\MedicalRecord\Domain\Repositories\MedicalRecordRepositoryInterface;
use App\Modules\MedicalRecord\Domain\ValueObjects\MedicalRecordNoteType;
use App\Modules\MedicalRecord\Domain\ValueObjects\MedicalRecordStatus;
use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;
use App\Modules\ServiceRequest\Domain\Repositories\ServiceRequestItemRepositoryInterface;
use App\Modules\ServiceRequest\Domain\Repositories\ServiceRequestRepositoryInterface;
use App\Modules\ServiceRequest\Domain\ValueObjects\ServiceRequestStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class CompleteNurseAssessmentUseCase
{
    public function __construct(
        private readonly ServiceRequestRepositoryInterface $serviceRequestRepository,
        private readonly ServiceRequestItemRepositoryInterface $itemRepository,
        private readonly MedicalRecordRepositoryInterface $medicalRecordRepository,
        private readonly EncounterResolverService $encounterResolverService,
        private readonly CurrentPlatformScopeContextInterface $platformScopeContext,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
        private readonly AppendServiceRequestAuditEventUseCase $appendAuditEvent,
        private readonly FulfillServiceRequestItemsUseCase $fulfillItemsUseCase,
    ) {}

    public function execute(
        string $encounterId,
        string $clinicalNote,
        array $items,
        ?int $actorId = null,
    ): array {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $encounter = $this->encounterResolverService->findById($encounterId);
        if ($encounter === null) {
            throw new RuntimeException('Encounter not found.');
        }

        $patientId = (string) $encounter->patient_id;
        if ($patientId === '') {
            throw new RuntimeException('Encounter has no patient.');
        }

        return DB::transaction(function () use ($encounterId, $encounter, $patientId, $clinicalNote, $items, $actorId): array {
            $serviceRequest = $this->createServiceRequest(
                $encounterId,
                $encounter,
                $patientId,
                $items,
                $actorId,
            );

            $this->createNurseNote($encounter, $patientId, $clinicalNote, $actorId);

            $createdItems = $this->createItems($serviceRequest['id'], $items);

            $this->appendAuditEvent->execute(
                $serviceRequest['id'],
                'service_request.assessed',
                $actorId,
                ServiceRequestStatus::PENDING->value,
                ServiceRequestStatus::IN_PROGRESS->value,
                [
                    'encounterId' => $encounterId,
                    'itemCount' => count($items),
                ],
            );

            $actorIdInt = is_int($actorId) ? $actorId : 0;
            $this->fulfillItemsUseCase->execute(
                serviceRequestId: (string) $serviceRequest['id'],
                items: $createdItems,
                actorId: $actorIdInt,
            );

            return $serviceRequest;
        });
    }

    private function createServiceRequest(
        string $encounterId,
        mixed $encounter,
        string $patientId,
        array $items,
        ?int $actorId,
    ): array {
        $serviceType = $this->resolvePrimaryServiceType($items);

        $payload = [
            'patient_id' => $patientId,
            'service_type' => $serviceType,
            'encounter_id' => $encounterId,
            'appointment_id' => $encounter->appointment_id,
            'status' => ServiceRequestStatus::PENDING->value,
            'request_number' => $this->generateRequestNumber(),
            'tenant_id' => $this->platformScopeContext->tenantId(),
            'facility_id' => $this->platformScopeContext->facilityId(),
            'requested_by_user_id' => $actorId,
            'requested_at' => now(),
            'acknowledged_at' => now(),
            'acknowledged_by_user_id' => $actorId,
            'assessed_by_user_id' => $actorId,
            'assessed_at' => now(),
            'priority' => 'routine',
        ];

        $created = $this->serviceRequestRepository->create($payload);

        $this->appendAuditEvent->execute(
            (string) $created['id'],
            'service_request.created',
            $actorId,
            null,
            ServiceRequestStatus::PENDING->value,
            [
                'patientId' => $patientId,
                'serviceType' => $serviceType,
                'encounterId' => $encounterId,
                'requestNumber' => $created['request_number'] ?? null,
                'itemCount' => count($items),
            ],
        );

        return $created;
    }

    private function createNurseNote(mixed $encounter, string $patientId, string $clinicalNote, ?int $actorId): void
    {
        $this->medicalRecordRepository->create([
            'patient_id' => $patientId,
            'encounter_id' => $encounter->id,
            'appointment_id' => $encounter->appointment_id,
            'author_user_id' => $actorId,
            'encounter_at' => now(),
            'record_type' => MedicalRecordNoteType::NURSING_NOTE->value,
            'assessment' => $clinicalNote,
            'status' => MedicalRecordStatus::DRAFT->value,
            'record_number' => $this->generateMedicalRecordNumber(),
            'tenant_id' => $this->platformScopeContext->tenantId(),
            'facility_id' => $this->platformScopeContext->facilityId(),
        ]);
    }

    private function createItems(string $serviceRequestId, array $items): array
    {
        $mapped = array_map(static fn (array $item): array => [
            'service_type' => $item['serviceType'] ?? $item['service_type'] ?? '',
            'catalog_item_id' => $item['catalogItemId'] ?? $item['catalog_item_id'] ?? null,
            'item_name' => $item['itemName'] ?? $item['item_name'] ?? '',
            'item_code' => $item['itemCode'] ?? $item['item_code'] ?? null,
            'quantity' => $item['quantity'] ?? 1,
            'status' => 'pending',
            'requested_by_user_id' => null,
            'requested_at' => now(),
        ], $items);

        $this->itemRepository->createMany($serviceRequestId, $mapped);

        return $this->itemRepository->findByServiceRequestId($serviceRequestId);
    }

    private function resolvePrimaryServiceType(array $items): string
    {
        $types = array_unique(array_map(
            static fn (array $item): string => $item['serviceType'] ?? $item['service_type'] ?? 'pharmacy',
            $items,
        ));

        return count($types) === 1 ? $types[0] : 'clinical_procedure';
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

    private function generateMedicalRecordNumber(): string
    {
        return 'MR'.now()->format('Ymd').strtoupper(Str::random(6));
    }
}
