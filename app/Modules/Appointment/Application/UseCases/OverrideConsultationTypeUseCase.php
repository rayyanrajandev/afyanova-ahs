<?php

namespace App\Modules\Appointment\Application\UseCases;

use App\Modules\Appointment\Application\Exceptions\AppointmentNotFoundException;
use App\Modules\Appointment\Domain\Repositories\AppointmentAuditLogRepositoryInterface;
use App\Modules\Appointment\Domain\Repositories\AppointmentRepositoryInterface;
use App\Modules\Appointment\Domain\ValueObjects\ConsultationClassification;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;

class OverrideConsultationTypeUseCase
{
    public function __construct(
        private readonly AppointmentRepositoryInterface $appointmentRepository,
        private readonly AppointmentAuditLogRepositoryInterface $auditLogRepository,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    /**
     * Manually override the consultation type for an appointment.
     *
     * @param  array{
     *     consultation_type: string,
     *     consultation_type_override_reason: string,
     * }  $payload
     */
    public function execute(string $appointmentId, array $payload, ?int $actorId = null): array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $existing = $this->appointmentRepository->findById($appointmentId);
        if ($existing === null) {
            throw new AppointmentNotFoundException(
                "Appointment {$appointmentId} not found.",
            );
        }

        $newType = strtolower(trim((string) ($payload['consultation_type'] ?? '')));
        if (! ConsultationClassification::isValid($newType)) {
            throw new \InvalidArgumentException(
                'consultation_type must be one of: '.implode(', ', ConsultationClassification::values()).'.',
            );
        }

        $reason = trim((string) ($payload['consultation_type_override_reason'] ?? ''));
        if ($reason === '') {
            throw new \InvalidArgumentException(
                'consultation_type_override_reason is required when manually overriding the consultation type.',
            );
        }

        $previousType = (string) ($existing['consultation_type'] ?? 'new');
        $previousSource = (string) ($existing['consultation_type_source'] ?? 'auto');

        $updated = $this->appointmentRepository->update($appointmentId, [
            'consultation_type'                 => $newType,
            'consultation_type_source'          => 'manual',
            'consultation_type_override_reason' => $reason,
        ]);

        if ($updated !== null) {
            $this->auditLogRepository->write(
                appointmentId: $appointmentId,
                action: 'appointment.consultation_type.overridden',
                actorId: $actorId,
                changes: [
                    'consultation_type' => [
                        'before' => $previousType,
                        'after'  => $newType,
                    ],
                    'consultation_type_source' => [
                        'before' => $previousSource,
                        'after'  => 'manual',
                    ],
                    'consultation_type_override_reason' => [
                        'before' => null,
                        'after'  => $reason,
                    ],
                ],
                metadata: [
                    'override_reason' => $reason,
                ],
            );
        }

        return $updated ?? $existing;
    }
}
