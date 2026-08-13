<?php

namespace App\Modules\ServiceRequest\Application\UseCases;

use App\Modules\ServiceRequest\Infrastructure\Models\ServiceRequestAuditEventModel;

class ListServiceRequestAuditEventsUseCase
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function execute(string $serviceRequestId): array
    {
        $serviceRequestId = trim($serviceRequestId);
        if ($serviceRequestId === '') {
            return [];
        }

        return ServiceRequestAuditEventModel::query()
            ->where('service_request_id', $serviceRequestId)
            ->orderByDesc('created_at')
            ->get()
            ->map(static fn (ServiceRequestAuditEventModel $m): array => $m->toArray())
            ->all();
    }
}
