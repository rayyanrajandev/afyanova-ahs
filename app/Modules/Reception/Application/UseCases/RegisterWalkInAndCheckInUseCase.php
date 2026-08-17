<?php

namespace App\Modules\Reception\Application\UseCases;

use App\Modules\Appointment\Application\Exceptions\ActiveAppointmentConflictException;
use App\Modules\Appointment\Application\Support\AppointmentConflictMessageFormatter;
use App\Modules\Appointment\Application\UseCases\CreateAppointmentUseCase;
use App\Modules\Appointment\Domain\Repositories\AppointmentRepositoryInterface;
use App\Modules\Patient\Infrastructure\Models\PatientModel;
use App\Modules\Department\Domain\Repositories\DepartmentRepositoryInterface;
use App\Modules\Reception\Domain\ValueObjects\ArrivalMode;
use Illuminate\Support\Facades\DB;

/**
 * Phase 1 of reports/patient-arrival-checkin-modernization-plan.md: replaces
 * the two-sequential-client-calls walk-in pattern found in
 * reports/patient-arrival-checkin-audit.md §4
 * (patients/Index.vue's startOutpatientWalkInFromHandoff():
 * POST /appointments then PATCH /appointments/{id}/status, with a race
 * window between them) with one backend transaction. Calls
 * CreateAppointmentUseCase and CheckInUseCase exactly as they exist —
 * neither is modified — so the existing POST /appointments and
 * PATCH /appointments/{id}/status endpoints keep working unchanged for any
 * caller that doesn't go through this coordination layer (plan §3.2).
 */
class RegisterWalkInAndCheckInUseCase
{
    public function __construct(
        private readonly CreateAppointmentUseCase $createAppointmentUseCase,
        private readonly CheckInUseCase $checkInUseCase,
        private readonly AppointmentRepositoryInterface $appointmentRepository,
        private readonly DepartmentRepositoryInterface $departmentRepository,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function execute(
        string $patientId,
        string $arrivalMode,
        ?string $reason,
        ?int $actorId,
    ): ?array {
        // Emergency arrivals go to the emergency department; everyone else lands
        // in general outpatients. Both are real rows now, so no arrival mode
        // produces an unrouted visit.
        $defaultDepartment = $arrivalMode === ArrivalMode::EMERGENCY->value
            ? $this->departmentRepository->findEmergencyDepartment()
            : $this->departmentRepository->findDefaultWalkInDepartment();

        return DB::transaction(function () use ($patientId, $arrivalMode, $reason, $actorId, $defaultDepartment): ?array {
            // Duplicate check-in guard (2026-08-12, bug fix): this is the
            // only caller of the walk-in/emergency arrival path, so the
            // "does this patient already have an unresolved visit" check
            // belongs here, not in CreateAppointmentUseCase — that use case
            // is shared with regular future-appointment scheduling, where
            // its own existing assertNoActiveSameDayConflict() (same-day
            // scoped) is the correct, unrelated check and stays untouched.
            //
            // Lock the *patient* row, not the appointment being searched
            // for — the patient row always exists (already registered), so
            // locking it serializes any two concurrent check-in attempts
            // for the same patient, including the very first one. A lock
            // on findActiveForPatient()'s result can't protect against a
            // race when no active appointment exists yet to lock (nothing
            // there to lock), which is exactly the case a double-click on
            // a not-yet-checked-in patient would hit.
            PatientModel::query()->where('id', $patientId)->lockForUpdate()->first();

            $existingActiveAppointment = $this->appointmentRepository->findActiveForPatient($patientId);
            if ($existingActiveAppointment !== null) {
                throw new ActiveAppointmentConflictException(
                    existingAppointment: $existingActiveAppointment,
                    message: AppointmentConflictMessageFormatter::activeSameDayConflict($existingActiveAppointment),
                );
            }

            $appointment = $this->createAppointmentUseCase->execute(
                payload: [
                    'patient_id' => $patientId,
                    'appointment_type' => 'walk_in',
                    'scheduled_at' => now()->addMinute()->toDateTimeString(),
                    'reason' => $reason ?? match ($arrivalMode) {
                        ArrivalMode::EMERGENCY->value => 'Emergency walk-in',
                        default => 'OPD walk-in',
                    },
                    // Since Phase 3, check-in opens the visit's Encounter, and
                    // EncounterResolverService::deriveEncounterType() classifies
                    // emergency-vs-outpatient from the appointment's department
                    // string — set it here so an emergency walk-in's encounter is
                    // correctly typed from the moment it's created, not left to
                    // whatever the default department heuristic would guess.
                    // The department name doubles as the signal
                    // EncounterResolverService::deriveEncounterType() matches on
                    // (`str_contains(..., 'emergency')`) to type the encounter.
                    // "Emergency Department" satisfies that, so routing to the
                    // real row preserves typing. The literal 'Emergency' remains
                    // the fallback for a facility that has no emergency
                    // department yet, so typing never silently regresses.
                    'department' => $defaultDepartment['name']
                        ?? ($arrivalMode === ArrivalMode::EMERGENCY->value ? 'Emergency' : null),
                    // Ordinary walk-ins land in general outpatients by default, so
                    // no visit is ever unrouted. Previously this was null and
                    // nothing downstream ever asked for a department, so walk-ins
                    // reached the provider queue belonging to no clinic. A nurse
                    // re-routes at triage when the patient needs another clinic —
                    // routing is a change, not a step someone must remember.
                    'department_id' => $defaultDepartment['id'] ?? null,
                ],
                actorId: $actorId,
            );

            return $this->checkInUseCase->execute(
                appointmentId: (string) $appointment['id'],
                arrivalMode: $arrivalMode,
                verificationNotes: null,
                actorId: $actorId,
            );
        });
    }
}
