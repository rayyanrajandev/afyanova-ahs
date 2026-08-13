<?php

namespace App\Modules\Appointment\Application\UseCases;

use App\Modules\Appointment\Domain\Events\AppointmentStatusChanged;
use App\Modules\Appointment\Domain\Repositories\AppointmentAuditLogRepositoryInterface;
use App\Modules\Appointment\Domain\Repositories\AppointmentRepositoryInterface;
use App\Modules\Appointment\Domain\ValueObjects\AppointmentStatus;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * OPD (outpatient) triage — a separate, structurally unrelated system from
 * Emergency Department triage despite the shared word "triage". ED triage is
 * its own module, App\Modules\EmergencyTriage (EmergencyTriageCaseModel, its
 * own status enum, own DB table); this use case and
 * resources/js/pages/triage/Queue.vue are the OPD-side equivalent and share
 * no code with it. Do not assume the two are interchangeable.
 */
class RecordAppointmentTriageUseCase
{
    public function __construct(
        private readonly AppointmentRepositoryInterface $appointmentRepository,
        private readonly AppointmentAuditLogRepositoryInterface $auditLogRepository,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    public function execute(
        string $id,
        string $triageVitalsSummary,
        ?string $triageNotes,
        ?string $triageCategory = null,
        array $routing = [],
        ?int $actorId = null,
    ): ?array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $existing = $this->appointmentRepository->findById($id);
        if (! $existing) {
            return null;
        }

        $currentStatus = strtolower((string) ($existing['status'] ?? ''));
        if (! in_array($currentStatus, [
            AppointmentStatus::WAITING_TRIAGE->value,
            AppointmentStatus::WAITING_PROVIDER->value,
        ], true)) {
            throw ValidationException::withMessages([
                'status' => ['Only appointments in the triage flow can be handed off to the provider queue.'],
            ]);
        }

        $validCategories = ['P1', 'P2', 'P3', 'P4', 'P5'];
        $normalizedCategory = $triageCategory !== null ? strtoupper(trim($triageCategory)) : null;
        if ($normalizedCategory !== null && ! in_array($normalizedCategory, $validCategories, true)) {
            $normalizedCategory = null;
        }

        $currentDepartment = $this->normalizeNullableString($existing['department'] ?? null);
        $currentClinicianUserId = $this->normalizeNullableInt($existing['clinician_user_id'] ?? null);

        $nextDepartment = array_key_exists('department', $routing)
            ? $this->normalizeNullableString($routing['department'] ?? null)
            : $currentDepartment;
        $nextClinicianUserId = array_key_exists('clinician_user_id', $routing)
            ? $this->normalizeNullableInt($routing['clinician_user_id'] ?? null)
            : $currentClinicianUserId;

        if ($nextDepartment === null && $nextClinicianUserId === null) {
            throw ValidationException::withMessages([
                'department' => ['Choose a clinic/department or route the visit to a named provider before completing triage.'],
                'clinicianUserId' => ['Assign a provider or select a clinic/department pool before completing triage.'],
            ]);
        }

        $updated = $this->appointmentRepository->update($id, [
            'clinician_user_id' => $nextClinicianUserId,
            'department' => $nextDepartment,
            'triage_vitals_summary' => $triageVitalsSummary,
            'triage_notes' => $triageNotes,
            'triage_category' => $normalizedCategory,
            'triaged_at' => now(),
            'triaged_by_user_id' => $actorId,
            'status' => AppointmentStatus::WAITING_PROVIDER->value,
            'status_reason' => null,
        ]);

        if (! $updated) {
            return null;
        }

        $changes = $this->extractChanges($existing, $updated);
        if ($changes !== []) {
            $this->auditLogRepository->write(
                appointmentId: $id,
                action: 'appointment.triage.recorded',
                actorId: $actorId,
                changes: $changes,
                metadata: [
                    'handoff_to_provider' => true,
                    'previous_status' => $existing['status'] ?? null,
                    'next_status' => $updated['status'] ?? null,
                    'routing_mode' => $nextClinicianUserId !== null ? 'specific_provider' : 'department_pool',
                    'department' => $updated['department'] ?? null,
                    'clinician_user_id' => $updated['clinician_user_id'] ?? null,
                ],
            );
        }

        DB::afterCommit(function () use ($updated, $existing, $actorId): void {
            event(new AppointmentStatusChanged(
                appointmentId: (string) $updated['id'],
                patientId: (string) $updated['patient_id'],
                oldStatus: (string) ($existing['status'] ?? ''),
                newStatus: (string) ($updated['status'] ?? ''),
                actorId: $actorId,
                facilityId: $updated['facility_id'] ?? null,
            ));
        });

        return $updated;
    }

    /**
     * @return array<string, mixed>
     */
    private function extractChanges(array $before, array $after): array
    {
        $trackedFields = [
            'clinician_user_id',
            'department',
            'triage_vitals_summary',
            'triage_notes',
            'triage_category',
            'triaged_at',
            'triaged_by_user_id',
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
}
