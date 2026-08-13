<?php

namespace App\Modules\EmergencyTriage\Application\UseCases;

use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;
use App\Modules\EmergencyTriage\Application\Exceptions\ActiveEmergencyTriageCaseConflictException;
use App\Modules\EmergencyTriage\Application\Exceptions\AdmissionNotEligibleForEmergencyTriageCaseException;
use App\Modules\EmergencyTriage\Application\Exceptions\AppointmentNotEligibleForEmergencyTriageCaseException;
use App\Modules\EmergencyTriage\Application\Exceptions\PatientNotEligibleForEmergencyTriageCaseException;
use App\Modules\EmergencyTriage\Domain\Repositories\EmergencyTriageCaseAuditLogRepositoryInterface;
use App\Modules\EmergencyTriage\Domain\Repositories\EmergencyTriageCaseRepositoryInterface;
use App\Modules\EmergencyTriage\Domain\Services\AdmissionLookupServiceInterface;
use App\Modules\EmergencyTriage\Domain\Services\AppointmentLookupServiceInterface;
use App\Modules\EmergencyTriage\Domain\Services\PatientLookupServiceInterface;
use App\Modules\EmergencyTriage\Domain\ValueObjects\EmergencyTriageCaseStatus;
use Illuminate\Support\Str;
use RuntimeException;

class CreateEmergencyTriageCaseUseCase
{
    public function __construct(
        private readonly EmergencyTriageCaseAuditLogRepositoryInterface $auditLogRepository,
        private readonly EmergencyTriageCaseRepositoryInterface $emergencyTriageCaseRepository,
        private readonly PatientLookupServiceInterface $patientLookupService,
        private readonly AppointmentLookupServiceInterface $appointmentLookupService,
        private readonly AdmissionLookupServiceInterface $admissionLookupService,
        private readonly CurrentPlatformScopeContextInterface $platformScopeContext,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    public function execute(array $payload, ?int $actorId = null): array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $patientId = (string) $payload['patient_id'];
        if (! $this->patientLookupService->patientExists($patientId)) {
            throw new PatientNotEligibleForEmergencyTriageCaseException(
                'Emergency triage case can only be created for an existing patient.',
            );
        }

        $appointmentId = $payload['appointment_id'] ?? null;
        if ($appointmentId !== null && ! $this->appointmentLookupService->isValidForPatient((string) $appointmentId, $patientId)) {
            throw new AppointmentNotEligibleForEmergencyTriageCaseException(
                'Appointment is not valid for the selected patient.',
            );
        }

        $admissionId = $payload['admission_id'] ?? null;
        if ($admissionId !== null && ! $this->admissionLookupService->isValidForPatient((string) $admissionId, $patientId)) {
            throw new AdmissionNotEligibleForEmergencyTriageCaseException(
                'Admission is not valid for the selected patient.',
            );
        }

        $existingActiveCase = $this->emergencyTriageCaseRepository->findActiveForPatient($patientId);
        if ($existingActiveCase !== null) {
            throw new ActiveEmergencyTriageCaseConflictException(
                existingCase: $existingActiveCase,
                message: sprintf(
                    'Patient already has an active emergency case (%s) with status "%s". Complete or cancel it before creating a new one.',
                    $existingActiveCase['case_number'] ?? 'unknown',
                    $existingActiveCase['status'] ?? 'unknown',
                ),
            );
        }

        $payload['status'] = EmergencyTriageCaseStatus::WAITING->value;
        $payload['case_number'] = $this->generateCaseNumber();
        $payload['tenant_id'] = $this->platformScopeContext->tenantId();
        $payload['facility_id'] = $this->platformScopeContext->facilityId();

        $createdCase = $this->emergencyTriageCaseRepository->create($payload);

        $this->auditLogRepository->write(
            emergencyTriageCaseId: $createdCase['id'],
            action: 'emergency-triage-case.created',
            actorId: $actorId,
            changes: [
                'after' => $this->extractTrackedFields($createdCase),
            ],
        );

        return $createdCase;
    }

    private function generateCaseNumber(): string
    {
        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $candidate = 'ETC'.now()->format('Ymd').strtoupper(Str::random(6));

            if (! $this->emergencyTriageCaseRepository->existsByCaseNumber($candidate)) {
                return $candidate;
            }
        }

        throw new RuntimeException('Unable to generate unique emergency triage case number.');
    }

    /**
     * @return array<string, mixed>
     */
    private function extractTrackedFields(array $case): array
    {
        $tracked = [
            'case_number',
            'tenant_id',
            'facility_id',
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

        $result = [];
        foreach ($tracked as $field) {
            $result[$field] = $case[$field] ?? null;
        }

        return $result;
    }
}
