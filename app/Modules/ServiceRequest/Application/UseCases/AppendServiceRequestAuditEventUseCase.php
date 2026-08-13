<?php

namespace App\Modules\ServiceRequest\Application\UseCases;

use App\Modules\ServiceRequest\Infrastructure\Models\ServiceRequestAuditEventModel;

class AppendServiceRequestAuditEventUseCase
{
    /**
     * @param  array<string, mixed>|null  $metadata
     */
    public function execute(
        string $serviceRequestId,
        string $action,
        ?int $actorUserId,
        ?string $fromStatus,
        ?string $toStatus,
        ?array $metadata = null,
        ?string $serviceRequestItemId = null,
    ): void {
        $serviceRequestId = trim($serviceRequestId);
        $action = trim($action);

        if ($serviceRequestId === '' || $action === '') {
            return;
        }

        ServiceRequestAuditEventModel::query()->create([
            'service_request_id' => $serviceRequestId,
            'service_request_item_id' => $serviceRequestItemId,
            'actor_user_id' => $actorUserId,
            'action' => $action,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'metadata' => $metadata,
            'created_at' => now(),
        ]);
    }
}
