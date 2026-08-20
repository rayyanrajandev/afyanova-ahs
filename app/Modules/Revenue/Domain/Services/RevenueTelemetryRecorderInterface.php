<?php

namespace App\Modules\Revenue\Domain\Services;

use App\Modules\Revenue\Domain\ValueObjects\ChargeSourceKind;
use App\Modules\Revenue\Domain\ValueObjects\RevenueTelemetryEvent;
use App\Modules\Revenue\Domain\ValueObjects\RevenueTelemetryReason;

interface RevenueTelemetryRecorderInterface
{
    /**
     * Record an anomaly. Implementations must never throw: every call site is
     * already handling a failure, and telemetry that can fail the thing it is
     * observing is worse than no telemetry.
     */
    public function record(
        RevenueTelemetryEvent $event,
        ?RevenueTelemetryReason $reason = null,
        ?ChargeSourceKind $sourceKind = null,
        ?string $sourceWorkflowId = null,
        ?string $serviceChargeId = null,
        ?string $patientId = null,
        ?int $actorUserId = null,
        ?string $detail = null,
    ): void;
}
