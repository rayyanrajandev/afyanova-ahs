<?php

namespace App\Modules\InventoryProcurement\Application\UseCases;

use App\Modules\InventoryProcurement\Domain\Repositories\InventoryMsdOrderRepositoryInterface;
use App\Modules\InventoryProcurement\Domain\Services\MsdApiClientInterface;
use App\Modules\InventoryProcurement\Domain\ValueObjects\InventoryMsdOrderStatus;

class SyncMsdOrderStatusUseCase
{
    public function __construct(
        private readonly InventoryMsdOrderRepositoryInterface $msdOrderRepository,
        private readonly MsdApiClientInterface $msdApiClient,
    ) {}

    /**
     * Sync a single MSD order's status from the MSD API.
     */
    public function execute(string $orderId): array
    {
        $order = $this->msdOrderRepository->findById($orderId);
        if ($order === null) {
            throw new \RuntimeException('MSD order not found.');
        }

        $submissionRef = $order['submission_reference'] ?? null;
        if ($submissionRef === null) {
            throw new \DomainException('Order has not been submitted to MSD yet.');
        }

        $response = $this->msdApiClient->queryOrderStatus($submissionRef);

        $updateData = [
            'api_response_log' => $response,
        ];

        $newStatus = InventoryMsdOrderStatus::tryFrom($response['status'] ?? '');
        if ($newStatus !== null) {
            $updateData['status'] = $newStatus->value;
        }

        if (! empty($response['dispatched_at'])) {
            $updateData['dispatched_at'] = $response['dispatched_at'];
        }

        if (! empty($response['delivery_note_number'])) {
            $updateData['delivery_note_number'] = $response['delivery_note_number'];
        }

        return $this->msdOrderRepository->update($orderId, $updateData) ?? $order;
    }
}
