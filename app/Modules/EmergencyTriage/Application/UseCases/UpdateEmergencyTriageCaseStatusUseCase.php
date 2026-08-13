<?php

namespace App\Modules\EmergencyTriage\Application\UseCases;

use App\Modules\Admission\Application\UseCases\CreateAdmissionUseCase;
use App\Modules\Appointment\Domain\Repositories\AppointmentAuditLogRepositoryInterface;
use App\Modules\Appointment\Domain\Repositories\AppointmentRepositoryInterface;
use App\Modules\Appointment\Domain\ValueObjects\AppointmentStatus;
use App\Modules\EmergencyTriage\Domain\Repositories\EmergencyTriageCaseAuditLogRepositoryInterface;
use App\Modules\EmergencyTriage\Domain\Repositories\EmergencyTriageCaseRepositoryInterface;
use App\Modules\EmergencyTriage\Domain\ValueObjects\EmergencyTriageCaseStatus;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;
use Illuminate\Support\Facades\DB;

class UpdateEmergencyTriageCaseStatusUseCase
{
    public function __construct(
        private readonly EmergencyTriageCaseRepositoryInterface $emergencyTriageCaseRepository,
        private readonly EmergencyTriageCaseAuditLogRepositoryInterface $auditLogRepository,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
        private readonly CreateAdmissionUseCase $createAdmissionUseCase,
        private readonly AppointmentRepositoryInterface $appointmentRepository,
        private readonly AppointmentAuditLogRepositoryInterface $appointmentAuditLogRepository,
    ) {}

    public function execute(
        string $id,
        string $status,
        ?string $reason,
        ?string $dispositionNotes,
        ?int $actorId = null,
        ?string $bedResourceId = null,
    ): ?array {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $existing = $this->emergencyTriageCaseRepository->findById($id);
        if (! $existing) {
            return null;
        }

        $payload = [
            'status' => $status,
            'status_reason' => $reason,
        ];

        if ($dispositionNotes !== null) {
            $payload['disposition_notes'] = $dispositionNotes;
        }

        if ($status === EmergencyTriageCaseStatus::TRIAGED->value) {
            $payload['triaged_at'] = now();
        }

        if (in_array($status, [
            EmergencyTriageCaseStatus::ADMITTED->value,
            EmergencyTriageCaseStatus::DISCHARGED->value,
            EmergencyTriageCaseStatus::CANCELLED->value,
        ], true)) {
            $payload['completed_at'] = now();
        } else {
            $payload['completed_at'] = null;
        }

        $createsAdmission = $status === EmergencyTriageCaseStatus::ADMITTED->value
            && ($existing['admission_id'] ?? null) === null;

        $updated = $createsAdmission
            ? DB::transaction(function () use ($id, $payload, $existing, $dispositionNotes, $bedResourceId, $actorId): ?array {
                $admission = $this->createAdmissionUseCase->execute(
                    payload: [
                        'patient_id' => $existing['patient_id'],
                        'attending_clinician_user_id' => $existing['assigned_clinician_user_id'] ?? null,
                        'bed_resource_id' => $bedResourceId,
                        'admitted_at' => now(),
                        'admission_reason' => $dispositionNotes ?? $existing['chief_complaint'] ?? null,
                    ],
                    actorId: $actorId,
                );

                $payload['admission_id'] = $admission['id'];

                return $this->emergencyTriageCaseRepository->update($id, $payload);
            })
            : $this->emergencyTriageCaseRepository->update($id, $payload);

        if (! $updated) {
            return null;
        }

        $appointmentHandoff = $this->handoffLinkedAppointmentAfterTriage($updated, $actorId);

        $this->auditLogRepository->write(
            emergencyTriageCaseId: $id,
            action: 'emergency-triage-case.status.updated',
            actorId: $actorId,
            changes: [
                'status' => [
                    'before' => $existing['status'] ?? null,
                    'after' => $updated['status'] ?? null,
                ],
                'status_reason' => [
                    'before' => $existing['status_reason'] ?? null,
                    'after' => $updated['status_reason'] ?? null,
                ],
                'disposition_notes' => [
                    'before' => $existing['disposition_notes'] ?? null,
                    'after' => $updated['disposition_notes'] ?? null,
                ],
                'completed_at' => [
                    'before' => $existing['completed_at'] ?? null,
                    'after' => $updated['completed_at'] ?? null,
                ],
                'triaged_at' => [
                    'before' => $existing['triaged_at'] ?? null,
                    'after' => $updated['triaged_at'] ?? null,
                ],
            ],
            metadata: [
                'transition' => [
                    'from' => $existing['status'] ?? null,
                    'to' => $updated['status'] ?? null,
                ],
                'linked_appointment_handoff' => $appointmentHandoff,
                'triage_timestamp_required' => $status === EmergencyTriageCaseStatus::TRIAGED->value,
                'triage_timestamp_provided' => ($updated['triaged_at'] ?? null) !== null,
                'completion_timestamp_required' => in_array($status, [
                    EmergencyTriageCaseStatus::ADMITTED->value,
                    EmergencyTriageCaseStatus::DISCHARGED->value,
                    EmergencyTriageCaseStatus::CANCELLED->value,
                ], true),
                'completion_timestamp_provided' => ($updated['completed_at'] ?? null) !== null,
                'cancellation_reason_required' => $status === EmergencyTriageCaseStatus::CANCELLED->value,
                'cancellation_reason_provided' => trim((string) ($updated['status_reason'] ?? '')) !== '',
                'disposition_notes_required' => in_array($status, [
                    EmergencyTriageCaseStatus::ADMITTED->value,
                    EmergencyTriageCaseStatus::DISCHARGED->value,
                ], true),
                'disposition_notes_provided' => trim((string) ($updated['disposition_notes'] ?? '')) !== '',
            ],
        );

        return $updated;
    }

    /**
     * ED triage and OPD triage stay separate modules. This bridge only moves an
     * already-linked appointment into the provider-ready queue so the clinician
     * can use the same consultation workspace as walk-in OPD visits.
     *
     * @return array<string, mixed>|null
     */
    private function handoffLinkedAppointmentAfterTriage(array $emergencyCase, ?int $actorId): ?array
    {
        if (($emergencyCase['status'] ?? null) !== EmergencyTriageCaseStatus::TRIAGED->value) {
            return null;
        }

        $appointmentId = trim((string) ($emergencyCase['appointment_id'] ?? ''));
        if ($appointmentId === '') {
            return [
                'result' => 'skipped',
                'reason' => 'no_linked_appointment',
            ];
        }

        $appointment = $this->appointmentRepository->findById($appointmentId);
        if ($appointment === null) {
            return [
                'result' => 'skipped',
                'reason' => 'linked_appointment_not_found',
                'appointment_id' => $appointmentId,
            ];
        }

        $currentStatus = strtolower(trim((string) ($appointment['status'] ?? '')));
        if (! in_array($currentStatus, [
            AppointmentStatus::SCHEDULED->value,
            AppointmentStatus::WAITING_TRIAGE->value,
            AppointmentStatus::WAITING_PROVIDER->value,
        ], true)) {
            return [
                'result' => 'skipped',
                'reason' => 'appointment_not_provider_handoff_ready',
                'appointment_id' => $appointmentId,
                'appointment_status' => $appointment['status'] ?? null,
            ];
        }

        $payload = [
            'status' => AppointmentStatus::WAITING_PROVIDER->value,
            'status_reason' => null,
            'triaged_at' => $emergencyCase['triaged_at'] ?? now(),
            'triaged_by_user_id' => $actorId,
        ];

        $vitalsSummary = $this->normalizeNullableString($emergencyCase['vitals_summary'] ?? null);
        if ($vitalsSummary !== null) {
            $payload['triage_vitals_summary'] = $vitalsSummary;
        }

        $triageCategory = $this->appointmentTriageCategoryFromEmergencyLevel($emergencyCase['triage_level'] ?? null);
        if ($triageCategory !== null) {
            $payload['triage_category'] = $triageCategory;
        }

        $assignedClinicianUserId = $this->normalizeNullableInt($emergencyCase['assigned_clinician_user_id'] ?? null);
        if ($assignedClinicianUserId !== null) {
            $payload['clinician_user_id'] = $assignedClinicianUserId;
        }

        if ($currentStatus === AppointmentStatus::SCHEDULED->value && empty($appointment['checked_in_at'])) {
            $payload['checked_in_at'] = now();
        }

        if ($this->normalizeNullableString($appointment['triage_notes'] ?? null) === null) {
            $caseNumber = $this->normalizeNullableString($emergencyCase['case_number'] ?? null);
            $chiefComplaint = $this->normalizeNullableString($emergencyCase['chief_complaint'] ?? null);
            $payload['triage_notes'] = trim(sprintf(
                'Emergency triage handoff%s%s',
                $caseNumber !== null ? " ({$caseNumber})" : '',
                $chiefComplaint !== null ? ": {$chiefComplaint}" : '',
            ));
        }

        $updatedAppointment = $this->appointmentRepository->update($appointmentId, $payload);
        if ($updatedAppointment === null) {
            return [
                'result' => 'skipped',
                'reason' => 'appointment_update_failed',
                'appointment_id' => $appointmentId,
            ];
        }

        $changes = $this->extractAppointmentChanges($appointment, $updatedAppointment, array_keys($payload));
        if ($changes !== []) {
            $this->appointmentAuditLogRepository->write(
                appointmentId: $appointmentId,
                action: 'appointment.emergency-triage.handoff',
                actorId: $actorId,
                changes: $changes,
                metadata: [
                    'emergency_triage_case_id' => $emergencyCase['id'] ?? null,
                    'emergency_case_number' => $emergencyCase['case_number'] ?? null,
                    'emergency_triage_level' => $emergencyCase['triage_level'] ?? null,
                    'previous_status' => $appointment['status'] ?? null,
                    'next_status' => $updatedAppointment['status'] ?? null,
                ],
            );
        }

        return [
            'result' => 'handed_off',
            'appointment_id' => $appointmentId,
            'from_status' => $appointment['status'] ?? null,
            'to_status' => $updatedAppointment['status'] ?? null,
        ];
    }

    private function appointmentTriageCategoryFromEmergencyLevel(mixed $triageLevel): ?string
    {
        return match (strtolower(trim((string) ($triageLevel ?? '')))) {
            'red' => 'P1',
            'yellow' => 'P3',
            'green' => 'P5',
            default => null,
        };
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        $normalized = trim((string) ($value ?? ''));

        return $normalized !== '' ? $normalized : null;
    }

    private function normalizeNullableInt(mixed $value): ?int
    {
        $normalized = (int) ($value ?? 0);

        return $normalized > 0 ? $normalized : null;
    }

    /**
     * @param  array<int, string>  $fields
     * @return array<string, array{before: mixed, after: mixed}>
     */
    private function extractAppointmentChanges(array $before, array $after, array $fields): array
    {
        $changes = [];
        foreach ($fields as $field) {
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
