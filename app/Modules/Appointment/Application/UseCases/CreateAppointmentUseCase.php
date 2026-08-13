<?php

namespace App\Modules\Appointment\Application\UseCases;

use App\Modules\Appointment\Application\Exceptions\ActiveAppointmentConflictException;
use App\Modules\Appointment\Application\Exceptions\ClinicianScheduleConflictException;
use App\Modules\Appointment\Application\Exceptions\PatientActiveEncounterConflictException;
use App\Modules\Appointment\Application\Exceptions\PatientNotEligibleForAppointmentException;
use App\Modules\Appointment\Application\Exceptions\SourceAdmissionNotEligibleForAppointmentException;
use App\Modules\Appointment\Application\Support\AppointmentConflictMessageFormatter;
use App\Modules\Appointment\Domain\Repositories\AppointmentAuditLogRepositoryInterface;
use App\Modules\Appointment\Domain\Repositories\AppointmentRepositoryInterface;
use App\Modules\Appointment\Domain\Services\ConsultationClassificationServiceInterface;
use App\Modules\Appointment\Domain\Services\PatientLookupServiceInterface;
use App\Modules\Appointment\Domain\ValueObjects\AppointmentStatus;
use App\Modules\Admission\Domain\Repositories\AdmissionRepositoryInterface;
use App\Modules\EmergencyTriage\Domain\Repositories\EmergencyTriageCaseRepositoryInterface;
use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;
use App\Support\FinancialCoverage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use RuntimeException;

class CreateAppointmentUseCase
{
    public function __construct(
        private readonly AppointmentRepositoryInterface $appointmentRepository,
        private readonly AppointmentAuditLogRepositoryInterface $auditLogRepository,
        private readonly PatientLookupServiceInterface $patientLookupService,
        private readonly AdmissionRepositoryInterface $admissionRepository,
        private readonly EmergencyTriageCaseRepositoryInterface $emergencyTriageCaseRepository,
        private readonly ConsultationClassificationServiceInterface $consultationClassificationService,
        private readonly CurrentPlatformScopeContextInterface $platformScopeContext,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    public function execute(array $payload, ?int $actorId = null): array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        if (! $this->patientLookupService->isActivePatient((string) $payload['patient_id'])) {
            throw new PatientNotEligibleForAppointmentException(
                'Appointment can only be created for an active patient.',
            );
        }

        $this->assertSourceAdmissionEligibility(
            sourceAdmissionId: isset($payload['source_admission_id']) ? (string) $payload['source_admission_id'] : null,
            patientId: (string) $payload['patient_id'],
        );

        $this->assertNoActivePatientEncounterConflict(
            patientId: (string) $payload['patient_id'],
        );

        $this->assertNoActiveSameDayConflict(
            patientId: (string) $payload['patient_id'],
            scheduledAt: (string) $payload['scheduled_at'],
        );

        $this->assertNoClinicianScheduleConflict(
            clinicianUserId: isset($payload['clinician_user_id']) ? (int) $payload['clinician_user_id'] : null,
            scheduledAt: (string) $payload['scheduled_at'],
            durationMinutes: isset($payload['duration_minutes']) ? (int) $payload['duration_minutes'] : null,
        );

        $payload['status'] = AppointmentStatus::SCHEDULED->value;
        $payload['appointment_number'] = $this->generateAppointmentNumber();
        $payload['tenant_id'] = $this->platformScopeContext->tenantId();
        $payload['facility_id'] = $this->platformScopeContext->facilityId();
        $payload = $this->normalizeFinancialCoverage($payload);
        $consultationClassificationAudit = null;
        $payload = $this->applyConsultationClassification($payload, $consultationClassificationAudit);

        $createdAppointment = $this->appointmentRepository->create($payload);

        $this->auditLogRepository->write(
            appointmentId: $createdAppointment['id'],
            action: 'appointment.created',
            actorId: $actorId,
            changes: [
                'after' => $this->extractTrackedFields($createdAppointment),
            ],
            metadata: array_filter([
                'consultation_classification' => $consultationClassificationAudit,
            ]),
        );

        return $createdAppointment;
    }

    /**
     * Soft-warning check (2026-08-12, Reception scheduling-duplicate audit)
     * — deliberately called by AppointmentController::store() *after*
     * execute() succeeds, not folded into execute()'s own return value:
     * execute()'s return shape (the raw created-appointment array) is
     * relied on as-is by RegisterWalkInAndCheckInUseCase
     * ($appointment['id']) and changing it would mean threading a
     * warnings-vs-not distinction through a caller that has no use for
     * this feature (walk-in check-in already has its own, unrelated,
     * hard-block active-visit guard).
     *
     * @return array<string, mixed>|null
     */
    public function findUpcomingConflictWarning(string $patientId, string $excludeAppointmentId): ?array
    {
        return $this->appointmentRepository->findOtherUpcomingForPatient(
            patientId: $patientId,
            excludeAppointmentId: $excludeAppointmentId,
        );
    }

    private function generateAppointmentNumber(): string
    {
        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $candidate = 'APT'.now()->format('Ymd').strtoupper(Str::random(6));

            if (! $this->appointmentRepository->existsByAppointmentNumber($candidate)) {
                return $candidate;
            }
        }

        throw new RuntimeException('Unable to generate unique appointment number.');
    }

    private function assertNoActiveSameDayConflict(string $patientId, string $scheduledAt): void
    {
        $scheduledDate = Carbon::parse($scheduledAt)->toDateString();
        $existing = $this->appointmentRepository->findActiveForPatientOnDate(
            patientId: $patientId,
            scheduledDate: $scheduledDate,
        );

        if ($existing === null) {
            return;
        }

        throw new ActiveAppointmentConflictException(
            existingAppointment: $existing,
            message: AppointmentConflictMessageFormatter::activeSameDayConflict($existing),
        );
    }

    /**
     * P7 of the Reception/Emergency/Admission/Bed-Management audit
     * follow-through (deferred from P6): a patient with an active Emergency
     * case or Admission can't be booked into a new appointment/walk-in —
     * hard-blocked, matching every other conflict check in this use case,
     * per the user's explicit decision. The follow-up-from-admission path
     * (assertSourceAdmissionEligibility, above) already requires the source
     * admission to be 'discharged', so it never collides with this check.
     * Emergency is checked before Admission only because it's the more
     * time-sensitive state to surface first when a patient happens to have
     * both (shouldn't normally happen, since Emergency's own admit flow
     * transitions the case to a terminal status).
     */
    private function assertNoActivePatientEncounterConflict(string $patientId): void
    {
        $activeCase = $this->emergencyTriageCaseRepository->findActiveForPatient($patientId);
        if ($activeCase !== null) {
            throw new PatientActiveEncounterConflictException(
                conflictType: 'emergency_case',
                existingRecord: $activeCase,
                message: sprintf(
                    'Patient has an active emergency case (%s, status: %s). Resolve or discharge the emergency visit before scheduling a new appointment.',
                    (string) ($activeCase['case_number'] ?? 'existing case'),
                    (string) ($activeCase['status'] ?? 'unknown'),
                ),
            );
        }

        $activeAdmission = $this->admissionRepository->findActiveForPatient($patientId);
        if ($activeAdmission !== null) {
            throw new PatientActiveEncounterConflictException(
                conflictType: 'admission',
                existingRecord: $activeAdmission,
                message: sprintf(
                    'Patient is currently admitted (%s). Coordinate with the inpatient team before scheduling a new appointment.',
                    (string) ($activeAdmission['admission_number'] ?? 'existing admission'),
                ),
            );
        }
    }

    /**
     * Hard-blocks two appointments overlapping in time for the same
     * clinician — added for the patient flow redesign's appointment
     * workflow A3. Skipped when clinicianUserId is null (clinician
     * assignment stays optional). Candidates are fetched within a window
     * wide enough to catch any possible overlap (reference time +/- the max
     * appointment duration, 480 minutes) and the exact overlap comparison
     * happens here in PHP — see
     * AppointmentRepositoryInterface::findActiveForClinicianInWindow()'s
     * docblock for why.
     */
    private function assertNoClinicianScheduleConflict(
        ?int $clinicianUserId,
        string $scheduledAt,
        ?int $durationMinutes,
        ?string $excludeAppointmentId = null,
    ): void {
        if ($clinicianUserId === null) {
            return;
        }

        $duration = $durationMinutes ?? 30;
        $start = Carbon::parse($scheduledAt);
        $end = $start->copy()->addMinutes($duration);

        $candidates = $this->appointmentRepository->findActiveForClinicianInWindow(
            clinicianUserId: $clinicianUserId,
            windowStart: $start->copy()->subMinutes(480)->toDateTimeString(),
            windowEnd: $end->copy()->addMinutes(480)->toDateTimeString(),
            excludeAppointmentId: $excludeAppointmentId,
        );

        foreach ($candidates as $candidate) {
            $candidateStart = Carbon::parse((string) $candidate['scheduled_at']);
            $candidateEnd = $candidateStart->copy()->addMinutes((int) ($candidate['duration_minutes'] ?? 30));

            if ($candidateStart->lt($end) && $start->lt($candidateEnd)) {
                throw new ClinicianScheduleConflictException(
                    existingAppointment: $candidate,
                    message: sprintf(
                        'This clinician already has an appointment (%s) from %s to %s.',
                        (string) ($candidate['appointment_number'] ?? 'existing appointment'),
                        $candidateStart->format('d M Y H:i'),
                        $candidateEnd->format('H:i'),
                    ),
                );
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function extractTrackedFields(array $appointment): array
    {
        $tracked = [
            'appointment_number',
            'tenant_id',
            'facility_id',
            'patient_id',
            'source_admission_id',
            'clinician_user_id',
            'department',
            'scheduled_at',
            'duration_minutes',
            'reason',
            'notes',
            'financial_coverage_type',
            'billing_payer_contract_id',
            'coverage_reference',
            'coverage_notes',
            'status',
            'status_reason',
            'consultation_type',
            'consultation_type_source',
            'prior_completed_appointment_id',
        ];

        $result = [];
        foreach ($tracked as $field) {
            $result[$field] = $appointment[$field] ?? null;
        }

        return $result;
    }

    /**
     * Auto-classify the appointment as NEW or REVIEW and merge results into payload.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function applyConsultationClassification(array $payload, ?array &$auditMetadata = null): array
    {
        $facilityId = $this->normalizeNullableString($payload['facility_id'] ?? null);
        $patientId  = (string) ($payload['patient_id'] ?? '');
        $scheduledAt = (string) ($payload['scheduled_at'] ?? now()->toDateTimeString());
        $reason = isset($payload['reason']) ? (string) $payload['reason'] : null;

        if ($patientId === '') {
            $payload['consultation_type'] = 'new';
            $payload['consultation_type_source'] = 'auto';
            $payload['prior_completed_appointment_id'] = null;
            $auditMetadata = [
                'classification' => 'new',
                'source' => 'auto',
                'reasoning' => 'Patient ID was missing during classification; defaulted to NEW.',
                'facility_id' => $facilityId,
                'patient_id' => $patientId,
                'scheduled_at' => $scheduledAt,
            ];

            return $payload;
        }

        $result = $this->consultationClassificationService->classify(
            patientId: $patientId,
            facilityId: $facilityId,
            scheduledAt: $scheduledAt,
            reason: $reason,
        );

        $payload['consultation_type'] = $result['classification'];
        $payload['consultation_type_source'] = $result['source'];
        $payload['prior_completed_appointment_id'] = $result['prior_completed_appointment_id'];
        $auditMetadata = [
            'classification' => $result['classification'],
            'source' => $result['source'],
            'prior_completed_appointment_id' => $result['prior_completed_appointment_id'],
            'reasoning' => $result['reasoning'],
            'policy' => $result['policy'] ?? null,
            'facility_id' => $facilityId,
            'patient_id' => $patientId,
            'scheduled_at' => $scheduledAt,
        ];

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizeFinancialCoverage(array $payload): array
    {
        $payload['source_admission_id'] = $this->normalizeNullableString($payload['source_admission_id'] ?? null);
        $payload['financial_coverage_type'] = FinancialCoverage::normalize(
            isset($payload['financial_coverage_type']) ? (string) $payload['financial_coverage_type'] : null,
        );
        $payload['billing_payer_contract_id'] = $this->normalizeNullableString($payload['billing_payer_contract_id'] ?? null);
        $payload['coverage_reference'] = $this->normalizeNullableString($payload['coverage_reference'] ?? null);
        $payload['coverage_notes'] = $this->normalizeNullableString($payload['coverage_notes'] ?? null);

        return $payload;
    }

    private function assertSourceAdmissionEligibility(?string $sourceAdmissionId, string $patientId): void
    {
        $normalizedSourceAdmissionId = trim((string) $sourceAdmissionId);
        if ($normalizedSourceAdmissionId === '') {
            return;
        }

        $admission = $this->admissionRepository->findById($normalizedSourceAdmissionId);
        if ($admission === null) {
            throw new SourceAdmissionNotEligibleForAppointmentException(
                'Post-discharge follow-up can only start from an existing admission.',
            );
        }

        $linkedPatientId = trim((string) ($admission['patient_id'] ?? ''));
        if ($linkedPatientId === '' || $linkedPatientId !== $patientId) {
            throw new SourceAdmissionNotEligibleForAppointmentException(
                'Source admission must belong to the same patient as the follow-up appointment.',
            );
        }

        $status = strtolower(trim((string) ($admission['status'] ?? '')));
        if ($status !== 'discharged') {
            throw new SourceAdmissionNotEligibleForAppointmentException(
                'Only discharged admissions can seed post-discharge follow-up appointments.',
            );
        }
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }
}
