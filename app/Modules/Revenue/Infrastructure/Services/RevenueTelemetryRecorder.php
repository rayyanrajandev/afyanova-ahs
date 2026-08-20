<?php

namespace App\Modules\Revenue\Infrastructure\Services;

use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use App\Modules\Revenue\Domain\Services\RevenueTelemetryRecorderInterface;
use App\Modules\Revenue\Domain\ValueObjects\ChargeSourceKind;
use App\Modules\Revenue\Domain\ValueObjects\RevenueTelemetryEvent;
use App\Modules\Revenue\Domain\ValueObjects\RevenueTelemetryReason;
use App\Modules\Revenue\Infrastructure\Models\RevenueTelemetryEventModel;
use Illuminate\Support\Facades\Log;
use Throwable;

class RevenueTelemetryRecorder implements RevenueTelemetryRecorderInterface
{
    public function __construct(
        private readonly CurrentPlatformScopeContextInterface $scopeContext,
    ) {}

    public function record(
        RevenueTelemetryEvent $event,
        ?RevenueTelemetryReason $reason = null,
        ?ChargeSourceKind $sourceKind = null,
        ?string $sourceWorkflowId = null,
        ?string $serviceChargeId = null,
        ?string $patientId = null,
        ?int $actorUserId = null,
        ?string $detail = null,
    ): void {
        try {
            RevenueTelemetryEventModel::query()->create([
                'tenant_id' => $this->scopeContext->tenantId(),
                'facility_id' => $this->scopeContext->facilityId(),
                'event_type' => $event->value,
                'reason' => $reason?->value,
                'source_kind' => $sourceKind?->value,
                'source_workflow_id' => $sourceWorkflowId,
                'service_charge_id' => $serviceChargeId,
                'patient_id' => $patientId,
                'actor_user_id' => $actorUserId,
                'detail' => $detail,
                'occurred_at' => now(),
            ]);
        } catch (Throwable $exception) {
            // Every caller is already inside a failure path. Telemetry that can
            // fail the thing it observes is worse than no telemetry, so this
            // degrades to the log rather than propagating.
            Log::warning('Unable to record a revenue telemetry event.', [
                'event_type' => $event->value,
                'source_workflow_id' => $sourceWorkflowId,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
