<?php

namespace App\Modules\Appointment\Application\UseCases;

use App\Modules\Appointment\Application\Exceptions\ActiveAppointmentConflictException;
use App\Modules\Appointment\Application\Exceptions\ClinicianScheduleConflictException;
use App\Modules\Appointment\Application\Exceptions\PatientNotEligibleForAppointmentException;
use App\Modules\Appointment\Application\Support\AppointmentConflictMessageFormatter;
use App\Modules\Appointment\Domain\Repositories\AppointmentAuditLogRepositoryInterface;
use App\Modules\Appointment\Domain\Repositories\AppointmentRepositoryInterface;
use App\Modules\Appointment\Domain\Services\PatientLookupServiceInterface;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;
use App\Support\FinancialCoverage;
use Illuminate\Support\Carbon;

class UpdateAppointmentUseCase
{
    public function __construct(
        private readonly AppointmentRepositoryInterface $appointmentRepository,
        private readonly AppointmentAuditLogRepositoryInterface $auditLogRepository,
        private readonly PatientLookupServiceInterface $patientLookupService,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    public function execute(string $id, array $payload, ?int $actorId = null): ?array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $existing = $this->appointmentRepository->findById($id);
        if (! $existing) {
            return null;
        }

        if (
            array_key_exists('patient_id', $payload)
            && ! $this->patientLookupService->isActivePatient((string) $payload['patient_id'])
        ) {
            throw new PatientNotEligibleForAppointmentException(
                'Appointment can only be assigned to an active patient.',
            );
        }

        $this->assertNoActiveSameDayConflict(
            appointmentId: $id,
            existing: $existing,
            payload: $payload,
        );
        $this->assertNoClinicianScheduleConflict(
            appointmentId: $id,
            existing: $existing,
            payload: $payload,
        );
        $payload = $this->normalizeFinancialCoverage($payload, $existing);

        $updated = $this->appointmentRepository->update($id, $payload);
        if (! $updated) {
            return null;
        }

        $changes = $this->extractChanges($existing, $updated);
        if ($changes !== []) {
            $this->auditLogRepository->write(
                appointmentId: $id,
                action: 'appointment.updated',
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
            'triage_vitals_summary',
            'triage_notes',
            'triaged_at',
            'triaged_by_user_id',
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
     * @param array<string, mixed> $existing
     * @param array<string, mixed> $payload
     */
    private function assertNoActiveSameDayConflict(string $appointmentId, array $existing, array $payload): void
    {
        $patientId = (string) ($payload['patient_id'] ?? $existing['patient_id'] ?? '');
        $scheduledAt = (string) ($payload['scheduled_at'] ?? $existing['scheduled_at'] ?? '');
        $nextStatus = strtolower((string) ($payload['status'] ?? $existing['status'] ?? ''));

        if ($patientId === '' || $scheduledAt === '') {
            return;
        }

        if (! in_array($nextStatus, ['scheduled', 'waiting_triage', 'waiting_provider', 'in_consultation'], true)) {
            return;
        }

        $scheduledDate = Carbon::parse($scheduledAt)->toDateString();
        $existingConflict = $this->appointmentRepository->findActiveForPatientOnDate(
            patientId: $patientId,
            scheduledDate: $scheduledDate,
            excludeAppointmentId: $appointmentId,
        );

        if ($existingConflict === null) {
            return;
        }

        throw new ActiveAppointmentConflictException(
            existingAppointment: $existingConflict,
            message: AppointmentConflictMessageFormatter::activeSameDayConflict($existingConflict),
        );
    }

    /**
     * Same hard-block as CreateAppointmentUseCase, applied to reschedules
     * and clinician reassignments — see that class's own docblock for the
     * overlap-window reasoning. Skipped when the resolved (payload-or-
     * existing) clinicianUserId is null, or when the next status is
     * terminal (cancelled/completed/no_show — closing or cancelling a visit
     * should never be blocked by a schedule conflict).
     *
     * @param array<string, mixed> $existing
     * @param array<string, mixed> $payload
     */
    private function assertNoClinicianScheduleConflict(string $appointmentId, array $existing, array $payload): void
    {
        $nextStatus = strtolower((string) ($payload['status'] ?? $existing['status'] ?? ''));
        if (! in_array($nextStatus, ['scheduled', 'waiting_triage', 'waiting_provider', 'in_consultation'], true)) {
            return;
        }

        $clinicianUserId = array_key_exists('clinician_user_id', $payload)
            ? $payload['clinician_user_id']
            : ($existing['clinician_user_id'] ?? null);

        if ($clinicianUserId === null) {
            return;
        }

        $scheduledAt = (string) ($payload['scheduled_at'] ?? $existing['scheduled_at'] ?? '');
        if ($scheduledAt === '') {
            return;
        }

        $durationMinutes = array_key_exists('duration_minutes', $payload)
            ? $payload['duration_minutes']
            : ($existing['duration_minutes'] ?? null);

        $duration = $durationMinutes !== null ? (int) $durationMinutes : 30;
        $start = Carbon::parse($scheduledAt);
        $end = $start->copy()->addMinutes($duration);

        $candidates = $this->appointmentRepository->findActiveForClinicianInWindow(
            clinicianUserId: (int) $clinicianUserId,
            windowStart: $start->copy()->subMinutes(480)->toDateTimeString(),
            windowEnd: $end->copy()->addMinutes(480)->toDateTimeString(),
            excludeAppointmentId: $appointmentId,
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
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $existing
     * @return array<string, mixed>
     */
    private function normalizeFinancialCoverage(array $payload, array $existing): array
    {
        $hasCoverageFieldChange = array_key_exists('financial_coverage_type', $payload)
            || array_key_exists('billing_payer_contract_id', $payload)
            || array_key_exists('coverage_reference', $payload)
            || array_key_exists('coverage_notes', $payload);

        if (! $hasCoverageFieldChange) {
            return $payload;
        }

        $payload['financial_coverage_type'] = FinancialCoverage::normalize(
            isset($payload['financial_coverage_type'])
                ? (string) $payload['financial_coverage_type']
                : (isset($existing['financial_coverage_type']) ? (string) $existing['financial_coverage_type'] : null),
        );

        if (array_key_exists('billing_payer_contract_id', $payload)) {
            $payload['billing_payer_contract_id'] = $this->normalizeNullableString($payload['billing_payer_contract_id'] ?? null);
        }

        if (array_key_exists('coverage_reference', $payload)) {
            $payload['coverage_reference'] = $this->normalizeNullableString($payload['coverage_reference'] ?? null);
        }

        if (array_key_exists('coverage_notes', $payload)) {
            $payload['coverage_notes'] = $this->normalizeNullableString($payload['coverage_notes'] ?? null);
        }

        return $payload;
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }
}
