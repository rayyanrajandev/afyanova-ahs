<?php

namespace App\Modules\Platform\Application\UseCases;

use App\Modules\Platform\Domain\Repositories\MultiFacilityRolloutAuditLogRepositoryInterface;
use App\Modules\Platform\Domain\Repositories\MultiFacilityRolloutRepositoryInterface;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;
use App\Modules\Platform\Domain\ValueObjects\MultiFacilityRolloutIncidentSeverity;
use App\Modules\Platform\Domain\ValueObjects\MultiFacilityRolloutIncidentStatus;
use DomainException;

class CreateMultiFacilityRolloutIncidentUseCase
{
    public function __construct(
        private readonly MultiFacilityRolloutRepositoryInterface $rolloutRepository,
        private readonly MultiFacilityRolloutAuditLogRepositoryInterface $auditLogRepository,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    public function execute(string $rolloutPlanId, array $payload, ?int $actorId = null): ?array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $plan = $this->rolloutRepository->findPlanById($rolloutPlanId);
        if ($plan === null) {
            return null;
        }

        $incidentCode = strtoupper(trim((string) ($payload['incident_code'] ?? '')));
        if ($incidentCode === '') {
            throw new DomainException('Incident code is required.');
        }

        if ($this->rolloutRepository->findIncidentByCode($rolloutPlanId, $incidentCode) !== null) {
            throw new DomainException('Incident code already exists for this rollout plan.');
        }

        $severity = strtolower(trim((string) ($payload['severity'] ?? '')));
        if (! in_array($severity, MultiFacilityRolloutIncidentSeverity::values(), true)) {
            throw new DomainException('Invalid incident severity.');
        }

        $status = strtolower(trim((string) ($payload['status'] ?? MultiFacilityRolloutIncidentStatus::OPEN->value)));
        if (! in_array($status, MultiFacilityRolloutIncidentStatus::values(), true)) {
            throw new DomainException('Invalid incident status.');
        }

        $summary = trim((string) ($payload['summary'] ?? ''));
        if ($summary === '') {
            throw new DomainException('Incident summary is required.');
        }

        $isResolved = $status === MultiFacilityRolloutIncidentStatus::RESOLVED->value;

        $created = $this->rolloutRepository->createIncident($rolloutPlanId, [
            'incident_code' => $incidentCode,
            'severity' => $severity,
            'status' => $status,
            'summary' => $summary,
            'details' => $this->nullableTrimmedValue($payload['details'] ?? null),
            'escalated_to' => $this->nullableTrimmedValue($payload['escalated_to'] ?? null),
            'opened_by_user_id' => $actorId,
            'resolved_by_user_id' => $isResolved ? $actorId : null,
            'opened_at' => $this->nullableTrimmedValue($payload['opened_at'] ?? null) ?? now(),
            'resolved_at' => $isResolved ? now() : null,
        ]);

        $this->auditLogRepository->write(
            rolloutPlanId: $rolloutPlanId,
            action: 'platform.multi-facility-rollout.incident.created',
            actorId: $actorId,
            changes: [
                'after' => [
                    'incident_code' => $created['incident_code'] ?? null,
                    'severity' => $created['severity'] ?? null,
                    'status' => $created['status'] ?? null,
                    'summary' => $created['summary'] ?? null,
                ],
            ],
            metadata: [
                'incidentId' => $created['id'] ?? null,
            ],
        );

        return $this->rolloutRepository->findPlanById($rolloutPlanId);
    }

    private function nullableTrimmedValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }
}
