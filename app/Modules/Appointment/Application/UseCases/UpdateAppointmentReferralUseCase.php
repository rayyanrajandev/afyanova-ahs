<?php

namespace App\Modules\Appointment\Application\UseCases;

use App\Modules\Appointment\Application\Exceptions\InvalidAppointmentReferralTargetFacilityException;
use App\Modules\Appointment\Domain\Repositories\AppointmentAuditLogRepositoryInterface;
use App\Modules\Appointment\Domain\Repositories\AppointmentReferralAuditLogRepositoryInterface;
use App\Modules\Appointment\Domain\Repositories\AppointmentReferralRepositoryInterface;
use App\Modules\Platform\Domain\Repositories\FacilityRepositoryInterface;
use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;

class UpdateAppointmentReferralUseCase
{
    public function __construct(
        private readonly AppointmentReferralRepositoryInterface $referralRepository,
        private readonly AppointmentReferralAuditLogRepositoryInterface $referralAuditLogRepository,
        private readonly AppointmentAuditLogRepositoryInterface $appointmentAuditLogRepository,
        private readonly CurrentPlatformScopeContextInterface $platformScopeContext,
        private readonly FacilityRepositoryInterface $facilityRepository,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    public function execute(
        string $appointmentId,
        string $referralId,
        array $payload,
        ?int $actorId = null
    ): ?array {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $existing = $this->referralRepository->findByAppointmentAndId($appointmentId, $referralId);
        if (! $existing) {
            return null;
        }

        if (
            array_key_exists('target_facility_id', $payload)
            || array_key_exists('target_facility_code', $payload)
        ) {
            $targetFacility = $this->resolveTargetFacility($existing, $payload);
            $payload['target_facility_id'] = $targetFacility['id'];
            $payload['target_facility_code'] = $targetFacility['code'];

            if (! array_key_exists('target_facility_name', $payload)) {
                $payload['target_facility_name'] = $targetFacility['name'];
            }
        }

        $updated = $this->referralRepository->update($referralId, $payload);
        if (! $updated) {
            return null;
        }

        $changes = $this->extractChanges($existing, $updated);
        if ($changes !== []) {
            $metadata = [
                'referral_number' => $updated['referral_number'] ?? null,
            ];

            $this->referralAuditLogRepository->write(
                referralId: $referralId,
                appointmentId: $appointmentId,
                action: 'appointment.referral.updated',
                actorId: $actorId,
                changes: $changes,
                metadata: $metadata,
            );

            $this->appointmentAuditLogRepository->write(
                appointmentId: $appointmentId,
                action: 'appointment.referral.updated',
                actorId: $actorId,
                changes: $changes,
                metadata: [
                    'referral_id' => $referralId,
                    'referral_number' => $metadata['referral_number'],
                ],
            );
        }

        return $updated;
    }

    /**
     * @return array<string, mixed>
     */
    private function extractChanges(array $before, array $after): array
    {
        $trackedFields = [
            'referral_type',
            'priority',
            'target_department',
            'target_facility_id',
            'target_facility_code',
            'target_facility_name',
            'target_clinician_user_id',
            'referral_reason',
            'clinical_notes',
            'handoff_notes',
            'requested_at',
            'accepted_at',
            'handed_off_at',
            'completed_at',
            'status',
            'status_reason',
            'metadata',
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

    /**
     * @param  array<string, mixed>  $existing
     * @param  array<string, mixed>  $payload
     * @return array{id:string|null,code:string|null,name:string|null}
     */
    private function resolveTargetFacility(array $existing, array $payload): array
    {
        $targetFacilityId = $this->nullableStringValue($payload['target_facility_id'] ?? null);
        $targetFacilityCode = $this->nullableStringValue($payload['target_facility_code'] ?? null);
        $targetFacilityName = $this->nullableStringValue($payload['target_facility_name'] ?? null);
        $tenantId = $this->nullableStringValue($existing['tenant_id'] ?? $this->platformScopeContext->tenantId());

        if ($targetFacilityId === null && $targetFacilityCode === null) {
            return [
                'id' => null,
                'code' => null,
                'name' => $targetFacilityName ?? $this->nullableStringValue($existing['target_facility_name'] ?? null),
            ];
        }

        $resolvedFacility = null;

        if ($targetFacilityId !== null) {
            $resolvedFacility = $this->facilityRepository->findById($targetFacilityId);
            if ($resolvedFacility === null) {
                throw new InvalidAppointmentReferralTargetFacilityException(
                    errors: ['targetFacilityId' => ['Target facility id is invalid.']],
                );
            }

            if ($tenantId !== null && (string) ($resolvedFacility['tenant_id'] ?? '') !== $tenantId) {
                throw new InvalidAppointmentReferralTargetFacilityException(
                    errors: ['targetFacilityId' => ['Target facility is outside the current tenant scope.']],
                );
            }
        }

        if ($targetFacilityCode !== null) {
            $normalizedCode = strtoupper($targetFacilityCode);

            if ($resolvedFacility === null) {
                $resolvedFacility = $this->facilityRepository->findByCode($normalizedCode, $tenantId);
            }

            if ($resolvedFacility === null) {
                throw new InvalidAppointmentReferralTargetFacilityException(
                    errors: ['targetFacilityCode' => ['Target facility code was not found in current scope.']],
                );
            }

            $resolvedCode = strtoupper((string) ($resolvedFacility['code'] ?? ''));
            if ($resolvedCode !== $normalizedCode) {
                throw new InvalidAppointmentReferralTargetFacilityException(
                    errors: [
                        'targetFacilityCode' => ['Target facility code does not match the selected target facility id.'],
                    ],
                );
            }
        }

        return [
            'id' => $this->nullableStringValue($resolvedFacility['id'] ?? null),
            'code' => $this->nullableUppercaseStringValue($resolvedFacility['code'] ?? null),
            'name' => $targetFacilityName ?? $this->nullableStringValue($resolvedFacility['name'] ?? null),
        ];
    }

    private function nullableStringValue(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function nullableUppercaseStringValue(mixed $value): ?string
    {
        $normalized = $this->nullableStringValue($value);

        return $normalized === null ? null : strtoupper($normalized);
    }
}
