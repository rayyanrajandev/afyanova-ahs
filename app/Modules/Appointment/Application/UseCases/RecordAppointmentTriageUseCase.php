<?php

namespace App\Modules\Appointment\Application\UseCases;

use App\Modules\Appointment\Domain\Events\AppointmentStatusChanged;
use App\Modules\Appointment\Domain\Repositories\AppointmentAuditLogRepositoryInterface;
use App\Modules\Appointment\Domain\Repositories\AppointmentRepositoryInterface;
use App\Modules\Appointment\Domain\ValueObjects\AppointmentStatus;
use App\Modules\Department\Domain\Repositories\DepartmentRepositoryInterface;
use App\Modules\PatientFlow\Application\Services\RecordPatientFlowTransitionService;
use App\Modules\PatientFlow\Domain\ValueObjects\PatientFlowStep;
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
        private readonly RecordPatientFlowTransitionService $recordPatientFlowTransition,
        private readonly DepartmentRepositoryInterface $departmentRepository,
    ) {}

    /**
     * @param  bool  $requireRouting  Whether a department or named provider must be
     *   chosen to complete triage. True for the triage handoff form, where routing
     *   IS the decision being made. False for the vitals-driven completion
     *   (PatientVitalSetController): recording observations is not the moment a
     *   nurse routes a patient, and ordinary walk-ins are created with no
     *   department at all by design (RegisterWalkInAndCheckInUseCase). Demanding
     *   routing there stalled every walk-in in waiting_triage after its vitals
     *   were taken — the visit simply never left triage (2026-08-16).
     */
    public function execute(
        string $id,
        string $triageVitalsSummary,
        ?string $triageNotes,
        ?string $triageCategory = null,
        array $routing = [],
        ?int $actorId = null,
        bool $requireRouting = true,
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

        // department_id is the relationship form of the routing target
        // (2026_08_16_000004). Resolved here so the name string and the id can
        // never disagree: whichever the caller supplies, both are written from
        // the same department row.
        $currentDepartmentId = $this->normalizeNullableString($existing['department_id'] ?? null);
        $nextDepartmentId = array_key_exists('department_id', $routing)
            ? $this->normalizeNullableString($routing['department_id'] ?? null)
            : $currentDepartmentId;

        if ($nextDepartmentId !== null) {
            $department = $this->departmentRepository->findById($nextDepartmentId);

            if ($department === null || ($department['status'] ?? null) !== 'active') {
                throw ValidationException::withMessages([
                    'departmentId' => ['Choose an active department to route this visit to.'],
                ]);
            }

            $nextDepartment = $this->normalizeNullableString($department['name'] ?? null);
        } elseif ($nextDepartment !== null && $nextDepartment !== $currentDepartment) {
            // A caller that still routes by name (the scheduling form) gets the
            // id resolved for it, so new writes are never id-less.
            $nextDepartmentId = $this->normalizeNullableString(
                $this->departmentRepository->findActiveByName($nextDepartment)['id'] ?? null,
            );
        }

        if ($requireRouting && $nextDepartmentId === null && $nextDepartment === null && $nextClinicianUserId === null) {
            throw ValidationException::withMessages([
                'department' => ['Choose a clinic/department or route the visit to a named provider before completing triage.'],
                'clinicianUserId' => ['Assign a provider or select a clinic/department pool before completing triage.'],
            ]);
        }

        $updated = $this->appointmentRepository->update($id, [
            'clinician_user_id' => $nextClinicianUserId,
            'department' => $nextDepartment,
            'department_id' => $nextDepartmentId,
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

        // Flow log — this use case writes status directly (see
        // AppointmentStatus::allowedForwardTransitions()'s note on why
        // WAITING_TRIAGE -> WAITING_PROVIDER is excluded from the generic
        // graph), so it records its own transition rather than inheriting
        // UpdateAppointmentStatusUseCase's. Triage handoff always lands on
        // WAITING_CLINICIAN: the patient has just been routed to a provider
        // for the first time, so there is no earlier consultation to review.
        $this->recordPatientFlowTransition->record(
            toStep: PatientFlowStep::WAITING_CLINICIAN,
            patientId: (string) $updated['patient_id'],
            appointmentId: (string) $updated['id'],
            actorId: $actorId,
            source: 'triage.handoff_recorded',
            metadata: array_filter([
                'triageCategory' => $updated['triage_category'] ?? null,
                'department' => $updated['department'] ?? null,
                'clinicianUserId' => $updated['clinician_user_id'] ?? null,
                'routingMode' => $nextClinicianUserId !== null ? 'specific_provider' : 'department_pool',
            ], static fn ($value) => $value !== null),
            facilityId: $updated['facility_id'] ?? null,
            appointmentStatusAlsoChanged: true,
        );

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
