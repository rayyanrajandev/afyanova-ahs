<?php

namespace App\Modules\ServiceRequest\Application\UseCases;

use App\Modules\ServiceRequest\Domain\Repositories\ServiceRequestItemRepositoryInterface;
use App\Modules\ServiceRequest\Domain\Repositories\ServiceRequestRepositoryInterface;
use App\Modules\ServiceRequest\Domain\ValueObjects\ServiceRequestStatus;

class CompleteServiceRequestIfItemsDoneUseCase
{
    private const TERMINAL_STATUSES = ['completed', 'cancelled'];

    public function __construct(
        private readonly ServiceRequestItemRepositoryInterface $itemRepository,
        private readonly ServiceRequestRepositoryInterface $serviceRequestRepository,
    ) {}

    public function execute(string $serviceRequestId): void
    {
        $items = $this->itemRepository->findByServiceRequestId($serviceRequestId);
        $statuses = array_column($items, 'status');

        if (empty($statuses) || empty(array_diff($statuses, self::TERMINAL_STATUSES))) {
            $this->serviceRequestRepository->update($serviceRequestId, [
                'status' => ServiceRequestStatus::COMPLETED->value,
            ]);
        }
    }
}
