<?php

namespace App\Modules\Appointment\Application\UseCases;

use App\Modules\Appointment\Domain\Repositories\AppointmentAuditLogRepositoryInterface;
use App\Modules\Appointment\Domain\Repositories\AppointmentReferralAuditLogRepositoryInterface;
use App\Modules\Appointment\Domain\Repositories\AppointmentReferralRepositoryInterface;
use App\Modules\Appointment\Domain\ValueObjects\AppointmentReferralStatus;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;

class UpdateAppointmentReferralStatusUseCase
{
    public function __construct(
        private readonly AppointmentReferralRepositoryInterface $referralRepository,
        private readonly AppointmentReferralAuditLogRepositoryInterface $referralAuditLogRepository,
        private readonly AppointmentAuditLogRepositoryInterface $appointmentAuditLogRepository,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    public function execute(
        string $appointmentId,
        string $referralId,
        string $status,
        ?string $reason,
        ?string $handoffNotes,
        ?int $actorId = null
    ): ?array {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $existing = $this->referralRepository->findByAppointmentAndId($appointmentId, $referralId);
        if (! $existing) {
            return null;
        }

        $payload = [
            'status' => $status,
            'status_reason' => $reason,
        ];

        if ($handoffNotes !== null) {
            $payload['handoff_notes'] = $handoffNotes;
        }

        if ($status === AppointmentReferralStatus::REQUESTED->value) {
            $payload['accepted_at'] = null;
            $payload['handed_off_at'] = null;
            $payload['completed_at'] = null;
        }

        if ($status === AppointmentReferralStatus::ACCEPTED->value) {
            $payload['accepted_at'] = $existing['accepted_at'] ?? now();
            $payload['handed_off_at'] = null;
            $payload['completed_at'] = null;
        }

        if ($status === AppointmentReferralStatus::IN_PROGRESS->value) {
            $payload['accepted_at'] = $existing['accepted_at'] ?? now();
            $payload['handed_off_at'] = $existing['handed_off_at'] ?? now();
            $payload['completed_at'] = null;
        }

        if ($status === AppointmentReferralStatus::COMPLETED->value) {
            $payload['accepted_at'] = $existing['accepted_at'] ?? now();
            $payload['handed_off_at'] = $existing['handed_off_at'] ?? now();
            $payload['completed_at'] = now();
        }

        if (in_array($status, [
            AppointmentReferralStatus::CANCELLED->value,
            AppointmentReferralStatus::REJECTED->value,
        ], true)) {
            $payload['completed_at'] = now();
        }

        $updated = $this->referralRepository->update($referralId, $payload);
        if (! $updated) {
            return null;
        }

        $changes = [
            'status' => [
                'before' => $existing['status'] ?? null,
                'after' => $updated['status'] ?? null,
            ],
            'status_reason' => [
                'before' => $existing['status_reason'] ?? null,
                'after' => $updated['status_reason'] ?? null,
            ],
            'handoff_notes' => [
                'before' => $existing['handoff_notes'] ?? null,
                'after' => $updated['handoff_notes'] ?? null,
            ],
            'accepted_at' => [
                'before' => $existing['accepted_at'] ?? null,
                'after' => $updated['accepted_at'] ?? null,
            ],
            'handed_off_at' => [
                'before' => $existing['handed_off_at'] ?? null,
                'after' => $updated['handed_off_at'] ?? null,
            ],
            'completed_at' => [
                'before' => $existing['completed_at'] ?? null,
                'after' => $updated['completed_at'] ?? null,
            ],
        ];

        $metadata = [
            'referral_number' => $updated['referral_number'] ?? null,
        ];

        $this->referralAuditLogRepository->write(
            referralId: $referralId,
            appointmentId: $appointmentId,
            action: 'appointment.referral.status.updated',
            actorId: $actorId,
            changes: $changes,
            metadata: $metadata,
        );

        $this->appointmentAuditLogRepository->write(
            appointmentId: $appointmentId,
            action: 'appointment.referral.status.updated',
            actorId: $actorId,
            changes: $changes,
            metadata: [
                'referral_id' => $referralId,
                'referral_number' => $metadata['referral_number'],
            ],
        );

        return $updated;
    }
}

