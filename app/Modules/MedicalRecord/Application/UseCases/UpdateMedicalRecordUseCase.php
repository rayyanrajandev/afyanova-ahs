<?php

namespace App\Modules\MedicalRecord\Application\UseCases;

use App\Modules\Encounter\Infrastructure\Models\EncounterModel;
use App\Modules\Encounter\Application\Services\EncounterLifecycleService;
use App\Modules\MedicalRecord\Application\Exceptions\AdmissionNotEligibleForMedicalRecordException;
use App\Modules\MedicalRecord\Application\Exceptions\AppointmentNotEligibleForMedicalRecordException;
use App\Modules\MedicalRecord\Application\Exceptions\AppointmentReferralNotEligibleForMedicalRecordException;
use App\Modules\MedicalRecord\Application\Exceptions\ConsultationOwnerConflictForMedicalRecordException;
use App\Modules\MedicalRecord\Application\Exceptions\InvalidMedicalRecordDiagnosisCodeException;
use App\Modules\MedicalRecord\Application\Exceptions\InvalidMedicalRecordTypeException;
use App\Modules\MedicalRecord\Application\Exceptions\MedicalRecordContentLockedException;
use App\Modules\MedicalRecord\Application\Exceptions\MedicalRecordDraftConflictException;
use App\Modules\MedicalRecord\Application\Exceptions\PatientNotEligibleForMedicalRecordException;
use App\Modules\MedicalRecord\Application\Exceptions\TheatreProcedureNotEligibleForMedicalRecordException;
use App\Modules\MedicalRecord\Domain\Repositories\MedicalRecordAuditLogRepositoryInterface;
use App\Modules\MedicalRecord\Domain\Repositories\MedicalRecordRepositoryInterface;
use App\Modules\MedicalRecord\Domain\Repositories\MedicalRecordVersionRepositoryInterface;
use App\Modules\MedicalRecord\Domain\Services\AdmissionLookupServiceInterface;
use App\Modules\MedicalRecord\Domain\Services\AppointmentLookupServiceInterface;
use App\Modules\MedicalRecord\Domain\Services\AppointmentReferralLookupServiceInterface;
use App\Modules\MedicalRecord\Domain\Services\DiagnosisTerminologyLookupServiceInterface;
use App\Modules\MedicalRecord\Domain\Services\PatientLookupServiceInterface;
use App\Modules\MedicalRecord\Domain\Services\TheatreProcedureLookupServiceInterface;
use App\Modules\MedicalRecord\Domain\ValueObjects\MedicalRecordNoteType;
use App\Modules\MedicalRecord\Domain\ValueObjects\MedicalRecordStatus;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;
use Illuminate\Support\Facades\DB;

class UpdateMedicalRecordUseCase
{
    private const ICD10_CODE_PATTERN = '/^[A-Z][0-9]{2}(?:\.[A-Z0-9]{1,4})?$/';

    public function __construct(
        private readonly MedicalRecordAuditLogRepositoryInterface $auditLogRepository,
        private readonly MedicalRecordRepositoryInterface $medicalRecordRepository,
        private readonly MedicalRecordVersionRepositoryInterface $medicalRecordVersionRepository,
        private readonly PatientLookupServiceInterface $patientLookupService,
        private readonly AppointmentLookupServiceInterface $appointmentLookupService,
        private readonly AppointmentReferralLookupServiceInterface $appointmentReferralLookupService,
        private readonly AdmissionLookupServiceInterface $admissionLookupService,
        private readonly TheatreProcedureLookupServiceInterface $theatreProcedureLookupService,
        private readonly DiagnosisTerminologyLookupServiceInterface $diagnosisTerminologyLookupService,
        private readonly EncounterLifecycleService $encounterLifecycleService,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    public function execute(
        string $id,
        array $payload,
        ?int $actorId = null,
        ?string $expectedUpdatedAt = null,
        bool $forceDraftSave = false,
    ): ?array {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $existing = $this->medicalRecordRepository->findById($id);
        if (! $existing) {
            return null;
        }

        if (($existing['status'] ?? null) !== MedicalRecordStatus::DRAFT->value) {
            throw new MedicalRecordContentLockedException(
                'Only draft medical records can be edited directly. Finalized, amended, and archived notes stay read-only; use the lifecycle workflow instead.',
            );
        }

        $patientId = (string) ($payload['patient_id'] ?? $existing['patient_id']);
        if (! $this->patientLookupService->patientExists($patientId)) {
            throw new PatientNotEligibleForMedicalRecordException(
                'Medical record can only be assigned to an existing patient.',
            );
        }

        $appointmentId = $payload['appointment_id'] ?? ($existing['appointment_id'] ?? null);
        if ($appointmentId !== null) {
            $appointment = $this->appointmentLookupService->findById((string) $appointmentId);
            if ($appointment === null || ($appointment['patient_id'] ?? null) !== $patientId) {
                throw new AppointmentNotEligibleForMedicalRecordException(
                    'Appointment is not valid for the selected patient.',
                );
            }

            $this->assertConsultationOwnershipForEncounterWrite($appointment, $actorId);
        }

        $admissionId = $payload['admission_id'] ?? ($existing['admission_id'] ?? null);
        if ($admissionId !== null && ! $this->admissionLookupService->isValidForPatient((string) $admissionId, $patientId)) {
            throw new AdmissionNotEligibleForMedicalRecordException(
                'Admission is not valid for the selected patient.',
            );
        }

        $encounterId = $payload['encounter_id'] ?? ($existing['encounter_id'] ?? null);
        if ($encounterId !== null) {
            $payload['encounter_id'] = $this->validatedEncounterId(
                encounterId: (string) $encounterId,
                patientId: $patientId,
                appointmentId: is_string($appointmentId) ? trim($appointmentId) : null,
                admissionId: is_string($admissionId) ? trim($admissionId) : null,
            );
        }

        $recordType = $this->applyRecordTypeValidationBaseline($payload, $existing);
        $this->applyAppointmentReferralValidationBaseline(
            $payload,
            $existing,
            $appointmentId,
            $recordType,
        );
        $this->applyTheatreProcedureValidationBaseline(
            $payload,
            $existing,
            $patientId,
            $appointmentId,
            $admissionId,
            $recordType,
        );
        $diagnosisCodeAcceptedUnverified = $this->applyDiagnosisCodeValidationBaseline($payload);

        $normalizedExpectedUpdatedAt = $expectedUpdatedAt !== null
            ? trim((string) $expectedUpdatedAt)
            : null;
        $normalizedExpectedUpdatedAt = $normalizedExpectedUpdatedAt === ''
            ? null
            : $normalizedExpectedUpdatedAt;

        // C-7 (reports/clinical-note-audit/15-critical-system-integrity-review.md):
        // the primary write, audit log, version snapshot, and encounter-status
        // sync used to be four independently-committed operations — a failure
        // between steps left the note and its encounter permanently out of
        // sync, with nothing to detect or reconcile the drift. Wrapping the
        // whole sequence in one transaction means a failure anywhere in it
        // rolls back everything, including the row-locked write inside
        // updateWithOptimisticLock() (nested via a savepoint, not a second
        // top-level transaction).
        return DB::transaction(function () use (
            $id,
            $payload,
            $actorId,
            $normalizedExpectedUpdatedAt,
            $forceDraftSave,
            $existing,
            $diagnosisCodeAcceptedUnverified,
        ): ?array {
            $updateResult = $this->medicalRecordRepository->updateWithOptimisticLock(
                id: $id,
                attributes: $payload,
                expectedUpdatedAt: $normalizedExpectedUpdatedAt,
                forceDraftSave: $forceDraftSave,
            );

            if (($updateResult['outcome'] ?? null) === 'missing') {
                return null;
            }

            if (($updateResult['outcome'] ?? null) === 'conflict') {
                throw new MedicalRecordDraftConflictException(
                    is_array($updateResult['record'] ?? null) ? $updateResult['record'] : $existing,
                );
            }

            $updated = is_array($updateResult['record'] ?? null) ? $updateResult['record'] : null;
            if (! $updated) {
                return null;
            }

            $changes = $this->extractChanges($existing, $updated);
            if ($changes !== []) {
                $this->auditLogRepository->write(
                    medicalRecordId: $id,
                    action: 'medical-record.updated',
                    actorId: $actorId,
                    changes: $changes,
                    metadata: $diagnosisCodeAcceptedUnverified
                        ? ['diagnosis_code_catalog_verified' => false]
                        : [],
                );

                $this->medicalRecordVersionRepository->create(
                    medicalRecordId: $id,
                    snapshot: $this->extractVersionSnapshot($updated),
                    changedFields: array_keys($changes),
                    createdByUserId: $actorId,
                );
            }

            $encounterId = trim((string) ($updated['encounter_id'] ?? ''));
            if ($encounterId !== '') {
                $this->encounterLifecycleService->markInProgress($encounterId, $actorId);
            }

            return $updated;
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function extractChanges(array $before, array $after): array
    {
        $trackedFields = [
            'patient_id',
            'encounter_id',
            'admission_id',
            'appointment_id',
            'appointment_referral_id',
            'theatre_procedure_id',
            'author_user_id',
            'encounter_at',
            'record_type',
            'subjective',
            'objective',
            'assessment',
            'plan',
            'diagnosis_code',
            'status',
            'status_reason',
            'signed_by_user_id',
            'signed_at',
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
     * C-10 (reports/clinical-note-audit/15-critical-system-integrity-review.md),
     * Option B (decided): when the catalog is empty, a shape-valid code is
     * still accepted (unchanged behavior), but the caller must be able to
     * tell "verified against real terminology" apart from "accepted because
     * there was nothing to verify against." The return value is that
     * signal: true means this code was accepted unverified.
     */
    private function applyDiagnosisCodeValidationBaseline(array &$payload): bool
    {
        if (! array_key_exists('diagnosis_code', $payload)) {
            return false;
        }

        $diagnosisCode = $payload['diagnosis_code'];
        if ($diagnosisCode === null) {
            return false;
        }

        $normalized = strtoupper(trim((string) $diagnosisCode));
        if ($normalized === '') {
            $payload['diagnosis_code'] = null;

            return false;
        }

        if (! preg_match(self::ICD10_CODE_PATTERN, $normalized)) {
            throw new InvalidMedicalRecordDiagnosisCodeException(
                'Diagnosis code must use ICD-10 style format (for example: R52 or J11.1).',
            );
        }

        $catalogHasActiveCodes = $this->diagnosisTerminologyLookupService->hasAnyActiveDiagnosisCodes();
        if ($catalogHasActiveCodes && ! $this->diagnosisTerminologyLookupService->isActiveDiagnosisCode($normalized)) {
            throw new InvalidMedicalRecordDiagnosisCodeException(
                'Diagnosis code must match an active diagnosis terminology catalog entry.',
            );
        }

        $payload['diagnosis_code'] = $normalized;

        return ! $catalogHasActiveCodes;
    }

    private function applyRecordTypeValidationBaseline(array &$payload, array $existing): string
    {
        if (! array_key_exists('record_type', $payload)) {
            return MedicalRecordNoteType::normalize($existing['record_type'] ?? null)
                ?? MedicalRecordNoteType::CONSULTATION_NOTE->value;
        }

        $recordType = MedicalRecordNoteType::normalize($payload['record_type']);

        if (! MedicalRecordNoteType::isValid($recordType)) {
            throw new InvalidMedicalRecordTypeException(
                'Record type must be one of the governed clinical note types.',
            );
        }

        $payload['record_type'] = $recordType;

        return $recordType;
    }

    private function applyAppointmentReferralValidationBaseline(
        array &$payload,
        array $existing,
        mixed $appointmentId,
        string $recordType,
    ): void {
        $appointmentReferralId = $payload['appointment_referral_id']
            ?? ($existing['appointment_referral_id'] ?? null);

        if ($appointmentReferralId === null) {
            return;
        }

        if ($recordType !== MedicalRecordNoteType::REFERRAL_NOTE->value) {
            throw new AppointmentReferralNotEligibleForMedicalRecordException(
                'Only referral notes can link to a referral handoff record.',
            );
        }

        if (! is_string($appointmentId) || trim($appointmentId) === '') {
            throw new AppointmentReferralNotEligibleForMedicalRecordException(
                'Referral linkage requires the linked appointment context.',
            );
        }

        $normalizedAppointmentReferralId = trim((string) $appointmentReferralId);
        $referral = $this->appointmentReferralLookupService->findByAppointment(
            trim($appointmentId),
            $normalizedAppointmentReferralId,
        );

        if ($referral === null) {
            throw new AppointmentReferralNotEligibleForMedicalRecordException(
                'Referral is not valid for the selected appointment.',
            );
        }

        $payload['appointment_referral_id'] = $normalizedAppointmentReferralId;
    }

    private function applyTheatreProcedureValidationBaseline(
        array &$payload,
        array $existing,
        string $patientId,
        mixed $appointmentId,
        mixed $admissionId,
        string $recordType,
    ): void {
        $theatreProcedureId = $payload['theatre_procedure_id']
            ?? ($existing['theatre_procedure_id'] ?? null);

        if ($theatreProcedureId === null) {
            return;
        }

        if ($recordType !== MedicalRecordNoteType::PROCEDURE_NOTE->value) {
            throw new TheatreProcedureNotEligibleForMedicalRecordException(
                'Only procedure notes can link to a theatre procedure record.',
            );
        }

        $normalizedTheatreProcedureId = trim((string) $theatreProcedureId);
        $theatreProcedure = $this->theatreProcedureLookupService->findById(
            $normalizedTheatreProcedureId,
        );

        if ($theatreProcedure === null || ($theatreProcedure['patient_id'] ?? null) !== $patientId) {
            throw new TheatreProcedureNotEligibleForMedicalRecordException(
                'Theatre procedure is not valid for the selected patient.',
            );
        }

        if (
            is_string($appointmentId)
            && trim($appointmentId) !== ''
            && ($theatreProcedure['appointment_id'] ?? null) !== trim($appointmentId)
        ) {
            throw new TheatreProcedureNotEligibleForMedicalRecordException(
                'Theatre procedure is not aligned to the selected appointment.',
            );
        }

        if (
            is_string($admissionId)
            && trim($admissionId) !== ''
            && ($theatreProcedure['admission_id'] ?? null) !== trim($admissionId)
        ) {
            throw new TheatreProcedureNotEligibleForMedicalRecordException(
                'Theatre procedure is not aligned to the selected admission.',
            );
        }

        $payload['theatre_procedure_id'] = $normalizedTheatreProcedureId;
    }
    /**
     * @param  array<string, mixed>  $appointment
     */
    private function assertConsultationOwnershipForEncounterWrite(array $appointment, ?int $actorId): void
    {
        $status = strtolower(trim((string) ($appointment['status'] ?? '')));
        if ($status !== 'in_consultation') {
            return;
        }

        $ownerUserId = $this->resolveConsultationOwnerUserId($appointment);
        if ($ownerUserId <= 0) {
            return;
        }

        if ($actorId !== null && $ownerUserId === $actorId) {
            return;
        }

        throw new ConsultationOwnerConflictForMedicalRecordException($ownerUserId);
    }

    /**
     * @param  array<string, mixed>  $appointment
     */
    private function resolveConsultationOwnerUserId(array $appointment): int
    {
        $ownerUserId = (int) ($appointment['consultation_owner_user_id'] ?? 0);
        if ($ownerUserId > 0) {
            return $ownerUserId;
        }

        return (int) ($appointment['clinician_user_id'] ?? 0);
    }

    private function validatedEncounterId(
        string $encounterId,
        string $patientId,
        ?string $appointmentId,
        ?string $admissionId,
    ): string {
        $normalizedEncounterId = trim($encounterId);
        $encounter = $normalizedEncounterId !== ''
            ? EncounterModel::query()->find($normalizedEncounterId)
            : null;

        if (
            $encounter === null ||
            (string) $encounter->patient_id !== $patientId ||
            ($appointmentId !== null && (string) ($encounter->appointment_id ?? '') !== $appointmentId) ||
            ($admissionId !== null && (string) ($encounter->admission_id ?? '') !== $admissionId)
        ) {
            throw new AppointmentNotEligibleForMedicalRecordException(
                'Encounter is not valid for the selected patient visit context.',
            );
        }

        return (string) $encounter->id;
    }

    /**
     * @return array<string, mixed>
     */
    private function extractVersionSnapshot(array $record): array
    {
        $tracked = [
            'patient_id',
            'encounter_id',
            'admission_id',
            'appointment_id',
            'appointment_referral_id',
            'theatre_procedure_id',
            'author_user_id',
            'encounter_at',
            'record_type',
            'subjective',
            'objective',
            'assessment',
            'plan',
            'diagnosis_code',
            'status',
            'status_reason',
            'signed_by_user_id',
            'signed_at',
        ];

        $snapshot = [];
        foreach ($tracked as $field) {
            $snapshot[$field] = $record[$field] ?? null;
        }

        return $snapshot;
    }
}
