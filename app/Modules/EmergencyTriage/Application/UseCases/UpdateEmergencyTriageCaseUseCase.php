<?php

namespace App\Modules\EmergencyTriage\Application\UseCases;

use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;
use App\Modules\EmergencyTriage\Application\Exceptions\AdmissionNotEligibleForEmergencyTriageCaseException;
use App\Modules\EmergencyTriage\Application\Exceptions\AppointmentNotEligibleForEmergencyTriageCaseException;
use App\Modules\EmergencyTriage\Application\Exceptions\PatientNotEligibleForEmergencyTriageCaseException;
use App\Modules\EmergencyTriage\Domain\Repositories\EmergencyTriageCaseAuditLogRepositoryInterface;
use App\Modules\EmergencyTriage\Domain\Repositories\EmergencyTriageCaseRepositoryInterface;
use App\Modules\EmergencyTriage\Domain\Services\AdmissionLookupServiceInterface;
use App\Modules\EmergencyTriage\Domain\Services\AppointmentLookupServiceInterface;
use App\Modules\EmergencyTriage\Domain\Services\PatientLookupServiceInterface;

class UpdateEmergencyTriageCaseUseCase
{
    public function __construct(
        private readonly EmergencyTriageCaseAuditLogRepositoryInterface $auditLogRepository,
        private readonly EmergencyTriageCaseRepositoryInterface $emergencyTriageCaseRepository,
        private readonly PatientLookupServiceInterface $patientLookupService,
        private readonly AppointmentLookupServiceInterface $appointmentLookupService,
        private readonly AdmissionLookupServiceInterface $admissionLookupService,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    public function execute(string $id, array $payload, ?int $actorId = null): ?array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $existing = $this->emergencyTriageCaseRepository->findById($id);
        if (! $existing) {
            return null;
        }

        $patientId = (string) ($payload['patient_id'] ?? $existing['patient_id']);
        if (! $this->patientLookupService->patientExists($patientId)) {
            throw new PatientNotEligibleForEmergencyTriageCaseException(
                'Emergency triage case can only be assigned to an existing patient.',
            );
        }

        $appointmentId = $payload['appointment_id'] ?? ($existing['appointment_id'] ?? null);
        if ($appointmentId !== null && ! $this->appointmentLookupService->isValidForPatient((string) $appointmentId, $patientId)) {
            throw new AppointmentNotEligibleForEmergencyTriageCaseException(
                'Appointment is not valid for the selected patient.',
            );
        }

        $admissionId = $payload['admission_id'] ?? ($existing['admission_id'] ?? null);
        if ($admissionId !== null && ! $this->admissionLookupService->isValidForPatient((string) $admissionId, $patientId)) {
            throw new AdmissionNotEligibleForEmergencyTriageCaseException(
                'Admission is not valid for the selected patient.',
            );
        }

        $updated = $this->emergencyTriageCaseRepository->update($id, $payload);
        if (! $updated) {
            return null;
        }

        $changes = $this->extractChanges($existing, $updated);
        if ($changes !== []) {
            $this->auditLogRepository->write(
                emergencyTriageCaseId: $id,
                action: 'emergency-triage-case.updated',
                actorId: $actorId,
                changes: $changes,
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
            'patient_id',
            'admission_id',
            'appointment_id',
            'assigned_clinician_user_id',
            'arrived_at',
            'triage_level',
            'chief_complaint',
            'vitals_summary',
            'triaged_at',
            'disposition_notes',
            'completed_at',
            'status',
            'status_reason',
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
