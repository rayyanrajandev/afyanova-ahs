<?php

namespace App\Modules\ServiceRequest\Application\UseCases;

use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;
use App\Modules\ServiceRequest\Domain\Repositories\ServiceRequestItemRepositoryInterface;
use App\Modules\ServiceRequest\Domain\Repositories\ServiceRequestRepositoryInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class AddServiceRequestItemsUseCase
{
    public function __construct(
        private readonly ServiceRequestRepositoryInterface $serviceRequestRepository,
        private readonly ServiceRequestItemRepositoryInterface $itemRepository,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
        private readonly AppendServiceRequestAuditEventUseCase $appendAuditEvent,
    ) {}

    public function execute(string $serviceRequestId, array $items, ?int $actorId = null): array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $serviceRequest = $this->serviceRequestRepository->findById($serviceRequestId);
        if ($serviceRequest === null) {
            throw new RuntimeException('Service request not found.');
        }

        $serviceType = (string) ($serviceRequest['service_type'] ?? '');

        return DB::transaction(function () use ($serviceRequestId, $items, $serviceType, $actorId): array {
            $mapped = array_map(static fn (array $item): array => [
                'service_type' => $item['serviceType'] ?? $item['service_type'] ?? $serviceType,
                'catalog_item_id' => $item['catalogItemId'] ?? $item['catalog_item_id'] ?? null,
                'item_name' => $item['itemName'] ?? $item['item_name'] ?? '',
                'item_code' => $item['itemCode'] ?? $item['item_code'] ?? null,
                'quantity' => $item['quantity'] ?? 1,
                'clinical_indication' => $item['clinicalIndication'] ?? $item['clinical_indication'] ?? null,
                'instructions' => $item['instructions'] ?? null,
                'status' => 'pending',
                'requested_by_user_id' => $actorId,
                'requested_at' => now(),
            ], $items);

            $this->itemRepository->createMany($serviceRequestId, $mapped);

            $this->appendAuditEvent->execute(
                $serviceRequestId,
                'service_request.items_added',
                $actorId,
                null,
                null,
                ['itemCount' => count($items)],
            );

            return $this->itemRepository->findByServiceRequestId($serviceRequestId);
        });
    }
}
