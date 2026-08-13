<?php

namespace App\Modules\ServiceRequest\Application\UseCases;

use App\Modules\ServiceRequest\Application\Exceptions\ServiceRequestStatusTransitionException;
use App\Modules\ServiceRequest\Domain\Repositories\ServiceRequestItemRepositoryInterface;
use App\Modules\ServiceRequest\Domain\ValueObjects\ServiceRequestItemStatus;

class RetryServiceRequestItemUseCase
{
    public function __construct(
        private readonly ServiceRequestItemRepositoryInterface $itemRepository,
        private readonly AppendServiceRequestAuditEventUseCase $appendAuditEvent,
    ) {}

    public function execute(string $itemId, int $actorId): void
    {
        $item = $this->itemRepository->findById($itemId);

        if ($item === null || ($item['status'] ?? '') !== ServiceRequestItemStatus::FAILED->value) {
            throw new ServiceRequestStatusTransitionException('Only failed items can be retried.');
        }

        $this->itemRepository->update($itemId, [
            'status' => ServiceRequestItemStatus::PENDING->value,
            'failed_at' => null,
            'failure_reason' => null,
        ]);

        $this->appendAuditEvent->execute(
            (string) $item['service_request_id'],
            'service_request_item.retry',
            $actorId,
            ServiceRequestItemStatus::FAILED->value,
            ServiceRequestItemStatus::PENDING->value,
            ['item_id' => $itemId],
            $itemId,
        );
    }
}
