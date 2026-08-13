<?php

namespace App\Modules\Appointment\Application\UseCases;

use App\Modules\Appointment\Application\Exceptions\InvalidAppointmentReferralTargetFacilityException;
use App\Modules\Appointment\Domain\Repositories\AppointmentAuditLogRepositoryInterface;
use App\Modules\Appointment\Domain\Repositories\AppointmentReferralAuditLogRepositoryInterface;
use App\Modules\Appointment\Domain\Repositories\AppointmentReferralRepositoryInterface;
use App\Modules\Appointment\Domain\Repositories\AppointmentRepositoryInterface;
use App\Modules\Appointment\Domain\ValueObjects\AppointmentReferralStatus;
use App\Modules\Platform\Domain\Repositories\FacilityRepositoryInterface;
use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;
use Illuminate\Support\Str;
use RuntimeException;

class CreateAppointmentReferralUseCase
{
    public function __construct(
        private readonly AppointmentRepositoryInterface $appointmentRepository,
        private readonly AppointmentReferralRepositoryInterface $referralRepository,
        private readonly AppointmentReferralAuditLogRepositoryInterface $referralAuditLogRepository,
        private readonly AppointmentAuditLogRepositoryInterface $appointmentAuditLogRepository,
        private readonly CurrentPlatformScopeContextInterface $platformScopeContext,
        private readonly FacilityRepositoryInterface $facilityRepository,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    public function execute(string $appointmentId, array $payload, ?int $actorId = null): ?array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $appointment = $this->appointmentRepository->findById($appointmentId);
        if (! $appointment) {
            return null;
        }

        $status = $payload['status'] ?? AppointmentReferralStatus::REQUESTED->value;
        if (! in_array($status, AppointmentReferralStatus::values(), true)) {
            $status = AppointmentReferralStatus::REQUESTED->value;
        }

        $requestedAt = $payload['requested_at'] ?? now();
        $targetFacility = $this->resolveTargetFacility($appointment, $payload);

        $createPayload = [
            'appointment_id' => $appointmentId,
            'referral_number' => $this->generateReferralNumber(),
            'tenant_id' => $appointment['tenant_id'] ?? $this->platformScopeContext->tenantId(),
            'facility_id' => $appointment['facility_id'] ?? $this->platformScopeContext->facilityId(),
            'referral_type' => $payload['referral_type'],
            'priority' => $payload['priority'],
            'target_department' => $payload['target_department'] ?? null,
            'target_facility_id' => $targetFacility['id'],
            'target_facility_code' => $targetFacility['code'],
            'target_facility_name' => $targetFacility['name'],
            'target_clinician_user_id' => $payload['target_clinician_user_id'] ?? null,
            'referral_reason' => $payload['referral_reason'] ?? null,
            'clinical_notes' => $payload['clinical_notes'] ?? null,
            'handoff_notes' => $payload['handoff_notes'] ?? null,
            'requested_at' => $requestedAt,
            'accepted_at' => null,
            'handed_off_at' => null,
            'completed_at' => null,
            'status' => $status,
            'status_reason' => $payload['status_reason'] ?? null,
            'metadata' => $payload['metadata'] ?? null,
        ];

        if (in_array($status, [
            AppointmentReferralStatus::ACCEPTED->value,
            AppointmentReferralStatus::IN_PROGRESS->value,
            AppointmentReferralStatus::COMPLETED->value,
        ], true)) {
            $createPayload['accepted_at'] = $payload['accepted_at'] ?? now();
        }

        if (in_array($status, [
            AppointmentReferralStatus::IN_PROGRESS->value,
            AppointmentReferralStatus::COMPLETED->value,
        ], true)) {
            $createPayload['handed_off_at'] = $payload['handed_off_at'] ?? now();
        }

        if (in_array($status, [
            AppointmentReferralStatus::COMPLETED->value,
            AppointmentReferralStatus::CANCELLED->value,
            AppointmentReferralStatus::REJECTED->value,
        ], true)) {
            $createPayload['completed_at'] = $payload['completed_at'] ?? now();
        }

        $created = $this->referralRepository->create($createPayload);

        $changes = [
            'after' => $this->extractTrackedFields($created),
        ];

        $metadata = [
            'referral_number' => $created['referral_number'] ?? null,
        ];

        $this->referralAuditLogRepository->write(
            referralId: $created['id'],
            appointmentId: $appointmentId,
            action: 'appointment.referral.created',
            actorId: $actorId,
            changes: $changes,
            metadata: $metadata,
        );

        $this->appointmentAuditLogRepository->write(
            appointmentId: $appointmentId,
            action: 'appointment.referral.created',
            actorId: $actorId,
            changes: $changes,
            metadata: [
                'referral_id' => $created['id'] ?? null,
                'referral_number' => $metadata['referral_number'],
            ],
        );

        return $created;
    }

    private function generateReferralNumber(): string
    {
        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $candidate = 'RFL'.now()->format('Ymd').strtoupper(Str::random(6));

            if (! $this->referralRepository->existsByReferralNumber($candidate)) {
                return $candidate;
            }
        }

        throw new RuntimeException('Unable to generate unique appointment referral number.');
    }

    /**
     * @return array<string, mixed>
     */
    private function extractTrackedFields(array $referral): array
    {
        $tracked = [
            'referral_number',
            'tenant_id',
            'facility_id',
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

        $result = [];
        foreach ($tracked as $field) {
            $result[$field] = $referral[$field] ?? null;
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $appointment
     * @param  array<string, mixed>  $payload
     * @return array{id:string|null,code:string|null,name:string|null}
     */
    private function resolveTargetFacility(array $appointment, array $payload): array
    {
        $targetFacilityId = $this->nullableStringValue($payload['target_facility_id'] ?? null);
        $targetFacilityCode = $this->nullableStringValue($payload['target_facility_code'] ?? null);
        $targetFacilityName = $this->nullableStringValue($payload['target_facility_name'] ?? null);
        $tenantId = $this->nullableStringValue($appointment['tenant_id'] ?? $this->platformScopeContext->tenantId());

        if ($targetFacilityId === null && $targetFacilityCode === null) {
            return [
                'id' => null,
                'code' => null,
                'name' => $targetFacilityName,
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
