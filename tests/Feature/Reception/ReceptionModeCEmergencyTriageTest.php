<?php

use App\Modules\EmergencyTriage\Application\UseCases\CreateEmergencyTriageCaseUseCase;
use App\Modules\EmergencyTriage\Infrastructure\Models\EmergencyTriageCaseModel;
use App\Modules\Patient\Infrastructure\Models\PatientModel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Coverage for Phase 5 Mode C of
 * reports/patient-arrival-checkin-modernization-plan.md §3.3: advisory
 * skeleton EmergencyTriageCase creation on emergency-mode check-in. No
 * clinical fields are ever set here — CreateSkeletonEmergencyTriageCase
 * reuses CreateEmergencyTriageCaseUseCase, the same validated path a
 * clinician's own request goes through.
 *
 * Enabled by default as of reports/emergency-queue-modernization-plan.md's
 * "sync gap" update — previously opt-in and disabled by default while this
 * was purely an engineering capability with no clinical sign-off; flipped
 * on once emergency/Queue.vue became a real, used page and the
 * disconnected-records gap this closes became operationally significant.
 * config/reception_automation.php still supports disabling it per
 * deployment via RECEPTION_MODE_C_SKELETON_TRIAGE_CASE_ENABLED=false.
 */
/**
 * `appointment.check-in`, not `appointments.update-status` — see
 * ReceptionCheckInApiTest.php's receptionUser() docblock (bug found
 * & fixed 2026-08-11, same root cause across all reception check-in
 * test files).
 */
function modeCUser(): User
{
    $user = User::factory()->create();
    foreach (['appointments.read', 'appointments.create', 'appointment.check-in'] as $permission) {
        $user->givePermissionTo($permission);
    }

    return $user;
}

function modeCPatient(): PatientModel
{
    return PatientModel::query()->create([
        'patient_number' => 'PTMC'.now()->format('Ymd').strtoupper(Str::random(6)),
        'first_name' => 'Mode', 'last_name' => 'C', 'gender' => 'male',
        'date_of_birth' => '1978-01-01', 'phone' => '+255700000023', 'country_code' => 'TZ',
        'status' => 'active',
    ]);
}

function modeCRegisterWalkIn(User $user, string $patientId, string $arrivalMode): string
{
    return (string) test()->actingAs($user)
        ->postJson('/api/v1/reception/walk-ins', [
            'patientId' => $patientId,
            'arrivalMode' => $arrivalMode,
        ])
        ->assertCreated()
        ->json('data.id');
}

it('does not create a skeleton triage case when Mode C is disabled', function (): void {
    // Mode C is now enabled by default (config/reception_automation.php) —
    // this test still covers the disabled path explicitly, in case a
    // deployment opts back out via RECEPTION_MODE_C_SKELETON_TRIAGE_CASE_ENABLED=false.
    config(['reception_automation.mode_c_skeleton_emergency_triage_case.enabled' => false]);

    $appointmentId = modeCRegisterWalkIn(modeCUser(), modeCPatient()->id, 'emergency');

    expect(EmergencyTriageCaseModel::query()->where('appointment_id', $appointmentId)->exists())->toBeFalse();
});

it('creates a skeleton triage case for an emergency arrival when Mode C is enabled', function (): void {
    config(['reception_automation.mode_c_skeleton_emergency_triage_case.enabled' => true]);

    $patient = modeCPatient();
    $appointmentId = modeCRegisterWalkIn(modeCUser(), $patient->id, 'emergency');

    $case = EmergencyTriageCaseModel::query()->where('appointment_id', $appointmentId)->first();

    expect($case)->not->toBeNull();
    expect($case->patient_id)->toBe($patient->id);
    expect($case->status)->toBe('waiting');
    // triage_level/chief_complaint are NOT NULL at the schema level, so a
    // true zero-clinical-fields skeleton isn't representable — both get
    // clearly-marked placeholders instead of a real clinical assessment.
    expect($case->triage_level)->toBe('unassigned');
    expect($case->chief_complaint)->toContain('Not yet assessed');
    expect($case->vitals_summary)->toBeNull();
    expect($case->status_reason)->toContain('pending clinician confirmation');
});

it('does not create a skeleton triage case for a non-emergency arrival even when Mode C is enabled', function (): void {
    config(['reception_automation.mode_c_skeleton_emergency_triage_case.enabled' => true]);

    $appointmentId = modeCRegisterWalkIn(modeCUser(), modeCPatient()->id, 'walk_in');

    expect(EmergencyTriageCaseModel::query()->where('appointment_id', $appointmentId)->exists())->toBeFalse();
});

it('does not create a second skeleton case for an appointment that already has one', function (): void {
    config(['reception_automation.mode_c_skeleton_emergency_triage_case.enabled' => true]);

    $user = modeCUser();
    $appointmentId = modeCRegisterWalkIn($user, modeCPatient()->id, 'emergency');

    expect(EmergencyTriageCaseModel::query()->where('appointment_id', $appointmentId)->count())->toBe(1);

    // Same-status re-check-in is a legitimate no-op per AppointmentStatus::
    // canTransitionTo() and still dispatches AppointmentCheckedIn; the
    // listener's idempotency check must not create a duplicate.
    $this->actingAs($user)
        ->patchJson('/api/v1/appointments/'.$appointmentId.'/check-in', [])
        ->assertOk();

    expect(EmergencyTriageCaseModel::query()->where('appointment_id', $appointmentId)->count())->toBe(1);
});

it('still succeeds the check-in even if skeleton triage case creation fails', function (): void {
    config(['reception_automation.mode_c_skeleton_emergency_triage_case.enabled' => true]);

    $failingUseCase = new class extends CreateEmergencyTriageCaseUseCase {
        public function __construct() {}

        public function execute(array $payload, ?int $actorId = null): array
        {
            throw new RuntimeException('simulated failure');
        }
    };
    app()->instance(CreateEmergencyTriageCaseUseCase::class, $failingUseCase);

    $patient = modeCPatient();

    $response = $this->actingAs(modeCUser())
        ->postJson('/api/v1/reception/walk-ins', [
            'patientId' => $patient->id,
            'arrivalMode' => 'emergency',
        ])
        ->assertCreated();

    expect($response->json('data.status'))->toBe('waiting_triage');
    expect(EmergencyTriageCaseModel::query()->where('patient_id', $patient->id)->exists())->toBeFalse();
});
