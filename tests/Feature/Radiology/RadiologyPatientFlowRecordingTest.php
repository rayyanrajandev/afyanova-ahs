<?php

/*
|--------------------------------------------------------------------------
| Radiology records what it does.
|--------------------------------------------------------------------------
|
| Before this, Radiology wrote zero patient_flow_events: scheduling a study,
| performing it, reporting it and releasing it never reached the Activity
| timeline, so a patient sent for imaging sat in `in_imaging` until somebody
| noticed by hand. Helpers are local to this file rather than borrowed from the
| other radiology suites, so nothing here depends on which file PHPUnit loads
| first.
*/

use App\Models\Permission;
use App\Models\User;
use App\Modules\Appointment\Infrastructure\Models\AppointmentModel;
use App\Modules\Laboratory\Infrastructure\Models\LaboratoryOrderModel;
use App\Modules\Patient\Infrastructure\Models\PatientModel;
use App\Modules\PatientFlow\Infrastructure\Models\PatientFlowEventModel;
use App\Modules\Radiology\Infrastructure\Models\RadiologyOrderModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * @param  array<int, string>  $abilities
 */
function radFlowUser(array $abilities = ['radiology.orders.read', 'imaging.perform', 'imaging.result.verify']): User
{
    $user = User::factory()->create();

    foreach ($abilities as $ability) {
        Permission::query()->firstOrCreate(['name' => $ability]);
        $user->givePermissionTo($ability);
    }

    return $user;
}

function radFlowPatient(): PatientModel
{
    return PatientModel::query()->create([
        'patient_number' => 'PT'.now()->format('Ymd').strtoupper(Str::random(6)),
        'first_name' => 'Neema',
        'middle_name' => null,
        'last_name' => 'Mwakasege',
        'gender' => 'female',
        'date_of_birth' => '1992-03-11',
        'phone' => '+255700000011',
        'email' => null,
        'national_id' => null,
        'country_code' => 'TZ',
        'region' => null,
        'district' => null,
        'address_line' => null,
        'next_of_kin_name' => null,
        'next_of_kin_phone' => null,
        'status' => 'active',
        'status_reason' => null,
    ]);
}

/**
 * A visit the doctor has already sent out for imaging — waiting_provider with
 * consultation_started_at preserved, exactly what updateProviderWorkflow()
 * leaves behind.
 */
function radFlowAppointment(string $patientId): AppointmentModel
{
    return AppointmentModel::query()->create([
        'appointment_number' => 'APT'.now()->format('Ymd').strtoupper(Str::random(6)),
        'patient_id' => $patientId,
        'clinician_user_id' => null,
        'department' => 'Outpatient',
        'scheduled_at' => now()->subHour()->toDateTimeString(),
        'duration_minutes' => 30,
        'reason' => 'Visit',
        'notes' => null,
        'status' => 'waiting_provider',
        'status_reason' => null,
        'consultation_started_at' => now()->subMinutes(30),
    ]);
}

function radFlowOrder(string $patientId, ?string $appointmentId, string $status = 'ordered'): RadiologyOrderModel
{
    return RadiologyOrderModel::query()->create([
        'order_number' => 'RAD'.now()->format('Ymd').strtoupper(Str::random(6)),
        'patient_id' => $patientId,
        'appointment_id' => $appointmentId,
        'ordered_at' => now(),
        'modality' => 'xray',
        'study_description' => 'Chest X-Ray (PA)',
        'status' => $status,
    ]);
}

/**
 * @return Collection<int, PatientFlowEventModel>
 */
function radFlowEvents(string $appointmentId)
{
    return PatientFlowEventModel::query()
        ->where('appointment_id', $appointmentId)
        ->orderBy('occurred_at')
        ->orderBy('id')
        ->get();
}

function patchRadStatus($test, User $user, string $orderId, string $status, ?string $reportSummary = null)
{
    return $test->actingAs($user)
        ->patchJson('/api/v1/radiology-orders/'.$orderId.'/status', array_filter([
            'status' => $status,
            'reason' => null,
            'reportSummary' => $reportSummary,
        ], static fn ($v) => $v !== null));
}

it('records a flow event when a study is scheduled', function (): void {
    $user = radFlowUser();
    $patient = radFlowPatient();
    $appointment = radFlowAppointment($patient->id);
    $order = radFlowOrder($patient->id, $appointment->id);

    patchRadStatus($this, $user, $order->id, 'scheduled')->assertOk();

    $event = radFlowEvents($appointment->id)->last();

    expect($event)->not->toBeNull();
    expect($event->source)->toBe('radiology.study_scheduled');
    // `scheduled` is still a waiting status for the resolver — the patient has
    // an appointment for the scanner, not the scanner itself.
    expect($event->to_step)->toBe('waiting_imaging');
    expect($event->actor_user_id)->toBe($user->id);
});

it('records the study starting and moves the patient into imaging', function (): void {
    $user = radFlowUser();
    $patient = radFlowPatient();
    $appointment = radFlowAppointment($patient->id);
    $order = radFlowOrder($patient->id, $appointment->id);

    patchRadStatus($this, $user, $order->id, 'scheduled')->assertOk();
    patchRadStatus($this, $user, $order->id, 'in_progress')->assertOk();

    $event = radFlowEvents($appointment->id)->last();

    expect($event->source)->toBe('radiology.study_started');
    expect($event->to_step)->toBe('in_imaging');
});

it('keeps the patient in imaging when the report is entered but not released', function (): void {
    $user = radFlowUser();
    $patient = radFlowPatient();
    $appointment = radFlowAppointment($patient->id);
    $order = radFlowOrder($patient->id, $appointment->id);

    patchRadStatus($this, $user, $order->id, 'scheduled')->assertOk();
    patchRadStatus($this, $user, $order->id, 'in_progress')->assertOk();
    patchRadStatus($this, $user, $order->id, 'completed', 'No acute findings.')->assertOk();

    $event = radFlowEvents($appointment->id)->last();

    // A completed-but-unverified study leaves openWorklistValues(), so the
    // resolver stops counting it — but radiology still holds this visit until
    // someone releases the report. Handing the patient back here would be a lie.
    expect($event->source)->toBe('radiology.report_entered');
    expect($event->to_step)->toBe('in_imaging');
});

it('hands the visit back to the clinician queue when the report is released', function (): void {
    $radiographer = radFlowUser(['radiology.orders.read', 'imaging.perform']);
    $reporter = radFlowUser(['radiology.orders.read', 'imaging.result.verify']);
    $patient = radFlowPatient();
    $appointment = radFlowAppointment($patient->id);
    $order = radFlowOrder($patient->id, $appointment->id);

    patchRadStatus($this, $radiographer, $order->id, 'scheduled')->assertOk();
    patchRadStatus($this, $radiographer, $order->id, 'in_progress')->assertOk();
    patchRadStatus($this, $radiographer, $order->id, 'completed', 'No acute findings.')->assertOk();

    $this->actingAs($reporter)
        ->patchJson('/api/v1/radiology-orders/'.$order->id.'/verify', [
            'verificationNote' => 'Reviewed and released.',
        ])
        ->assertOk();

    $event = radFlowEvents($appointment->id)->last();

    expect($event->source)->toBe('radiology.result_verified');
    expect($event->to_step)->toBe('waiting_clinician_review');
    expect($event->actor_user_id)->toBe($reporter->id);
});

it('never writes with_clinician — only a doctor may put a patient in a room', function (): void {
    $radiographer = radFlowUser(['radiology.orders.read', 'imaging.perform']);
    $reporter = radFlowUser(['radiology.orders.read', 'imaging.result.verify']);
    $patient = radFlowPatient();
    $appointment = radFlowAppointment($patient->id);
    $order = radFlowOrder($patient->id, $appointment->id);

    patchRadStatus($this, $radiographer, $order->id, 'scheduled')->assertOk();
    patchRadStatus($this, $radiographer, $order->id, 'in_progress')->assertOk();
    patchRadStatus($this, $radiographer, $order->id, 'completed', 'Clear.')->assertOk();
    $this->actingAs($reporter)
        ->patchJson('/api/v1/radiology-orders/'.$order->id.'/verify', ['verificationNote' => 'Released.'])
        ->assertOk();

    expect(radFlowEvents($appointment->id)->pluck('to_step')->all())
        ->not->toContain('with_clinician');
});

it('does not hand the visit back while another order on the same visit is open', function (): void {
    $radiographer = radFlowUser(['radiology.orders.read', 'imaging.perform']);
    $reporter = radFlowUser(['radiology.orders.read', 'imaging.result.verify']);
    $patient = radFlowPatient();
    $appointment = radFlowAppointment($patient->id);
    $order = radFlowOrder($patient->id, $appointment->id);

    // A blood test is still running on the same visit. The step belongs to the
    // visit, not to this order, so releasing the film must not free the patient.
    LaboratoryOrderModel::query()->create([
        'order_number' => 'LAB'.now()->format('Ymd').strtoupper(Str::random(6)),
        'patient_id' => $patient->id,
        'appointment_id' => $appointment->id,
        'ordered_at' => now(),
        'test_code' => 'LOINC:57021-8',
        'test_name' => 'Complete Blood Count',
        'priority' => 'routine',
        'status' => 'ordered',
    ]);

    patchRadStatus($this, $radiographer, $order->id, 'scheduled')->assertOk();
    patchRadStatus($this, $radiographer, $order->id, 'in_progress')->assertOk();
    patchRadStatus($this, $radiographer, $order->id, 'completed', 'Clear.')->assertOk();

    $this->actingAs($reporter)
        ->patchJson('/api/v1/radiology-orders/'.$order->id.'/verify', ['verificationNote' => 'Released.'])
        ->assertOk();

    $event = radFlowEvents($appointment->id)->last();

    expect($event->source)->toBe('radiology.result_verified');
    expect($event->to_step)->toBe('waiting_lab');
    expect($event->to_step)->not->toBe('waiting_clinician_review');
});

it('records nothing for a direct-service study with no appointment', function (): void {
    $user = radFlowUser();
    $patient = radFlowPatient();
    $order = radFlowOrder($patient->id, null);

    patchRadStatus($this, $user, $order->id, 'scheduled')->assertOk();

    expect(PatientFlowEventModel::query()->where('patient_id', $patient->id)->count())->toBe(0);
});

it('does not record a flow event when a study is cancelled', function (): void {
    $user = radFlowUser();
    $patient = radFlowPatient();
    $appointment = radFlowAppointment($patient->id);
    $order = radFlowOrder($patient->id, $appointment->id);

    $this->actingAs($user)
        ->patchJson('/api/v1/radiology-orders/'.$order->id.'/status', [
            'status' => 'cancelled',
            'reason' => 'Patient declined the study.',
        ])
        ->assertOk();

    // Cancellation withdraws work rather than advancing it, and the flow
    // vocabulary has no word for that yet.
    expect(radFlowEvents($appointment->id))->toHaveCount(0);
});
