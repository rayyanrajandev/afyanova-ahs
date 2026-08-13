<?php

namespace App\Modules\Platform\Application\UseCases;

use App\Modules\Platform\Domain\Repositories\MultiFacilityRolloutAuditLogRepositoryInterface;
use App\Modules\Platform\Domain\Repositories\MultiFacilityRolloutRepositoryInterface;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;
use App\Modules\Platform\Domain\ValueObjects\MultiFacilityRolloutIncidentSeverity;
use App\Modules\Platform\Domain\ValueObjects\MultiFacilityRolloutIncidentStatus;
use DomainException;

class UpdateMultiFacilityRolloutIncidentUseCase
{
    public function __construct(
        private readonly MultiFacilityRolloutRepositoryInterface $rolloutRepository,
        private readonly MultiFacilityRolloutAuditLogRepositoryInterface $auditLogRepository,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    public function execute(string $rolloutPlanId, string $incidentId, array $payload, ?int $actorId = null): ?array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $plan = $this->rolloutRepository->findPlanById($rolloutPlanId);
        if ($plan === null) {
            return null;
        }

        $existing = $this->rolloutRepository->findIncidentById($rolloutPlanId, $incidentId);
        if ($existing === null) {
            return null;
        }

        $updatePayload = [];

        if (array_key_exists('severity', $payload)) {
            $severity = strtolower(trim((string) $payload['severity']));
            if (! in_array($severity, MultiFacilityRolloutIncidentSeverity::values(), true)) {
                throw new DomainException('Invalid incident severity.');
            }

            $updatePayload['severity'] = $severity;
        }

        if (array_key_exists('status', $payload)) {
            $status = strtolower(trim((string) $payload['status']));
            if (! in_array($status, MultiFacilityRolloutIncidentStatus::values(), true)) {
                throw new DomainException('Invalid incident status.');
            }

            $updatePayload['status'] = $status;
        }

        if (array_key_exists('summary', $payload)) {
            $summary = trim((string) $payload['summary']);
            if ($summary === '') {
                throw new DomainException('Incident summary cannot be blank.');
            }

            $updatePayload['summary'] = $summary;
        }

        if (array_key_exists('details', $payload)) {
            $updatePayload['details'] = $this->nullableTrimmedValue($payload['details']);
        }

        if (array_key_exists('escalated_to', $payload)) {
            $updatePayload['escalated_to'] = $this->nullableTrimmedValue($payload['escalated_to']);
        }

        if (array_key_exists('resolved_at', $payload)) {
            $updatePayload['resolved_at'] = $this->nullableTrimmedValue($payload['resolved_at']);
        }

        $nextStatus = (string) ($updatePayload['status'] ?? ($existing['status'] ?? MultiFacilityRolloutIncidentStatus::OPEN->value));
        if ($nextStatus === MultiFacilityRolloutIncidentStatus::RESOLVED->value) {
            $updatePayload['resolved_by_user_id'] = $actorId;
            $updatePayload['resolved_at'] = $updatePayload['resolved_at'] ?? now();
        }

        if ($nextStatus !== MultiFacilityRolloutIncidentStatus::RESOLVED->value && array_key_exists('status', $updatePayload)) {
            $updatePayload['resolved_by_user_id'] = null;
            $updatePayload['resolved_at'] = null;
        }

        $updated = $this->rolloutRepository->updateIncident($rolloutPlanId, $incidentId, $updatePayload);
        if ($updated === null) {
            return null;
        }

        $changes = $this->extractChanges($existing, $updated);
        if ($changes !== []) {
            $this->auditLogRepository->write(
                rolloutPlanId: $rolloutPlanId,
                action: 'platform.multi-facility-rollout.incident.updated',
                actorId: $actorId,
                changes: $changes,
                metadata: [
                    'incidentId' => $incidentId,
                ],
            );
        }

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

    /**
     * @return array<string, mixed>
     */
    private function extractChanges(array $before, array $after): array
    {
        $trackedFields = [
            'severity',
            'status',
            'summary',
            'details',
            'escalated_to',
            'resolved_by_user_id',
            'resolved_at',
        ];

        $changes = [];
        foreach ($trackedFields as $field) {
            $beforeValue = $before[$field] ?? null;
            $afterValue = $after[$field] ?? null;

            if ($beforeValue === $afterValue) {
                continue;
            }

            $changes[$field] = [
                'before' => $beforeValue,
                'after' => $afterValue,
            ];
        }

        return $changes;
    }
}
