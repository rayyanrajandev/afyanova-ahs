<?php

use App\Models\Permission;
use App\Models\User;
use App\Http\Middleware\EnsureMappedFacilitySubscriptionEntitlement;
use App\Modules\Appointment\Infrastructure\Models\AppointmentModel;
use App\Modules\Laboratory\Infrastructure\Models\LaboratoryOrderModel;
use App\Modules\Patient\Infrastructure\Models\PatientModel;
use App\Modules\PatientFlow\Infrastructure\Models\PatientFlowEventModel;
use App\Modules\PatientFlow\Domain\Events\PatientFlowBoardUpdated;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Laboratory flow plan, phase 2 — the lab records what it does.
|--------------------------------------------------------------------------
|
| Before this, Laboratory wrote zero patient_flow_events: specimen collection,
| testing and verification never reached the Activity timeline, so no other
| workspace could see who had done any of it. Helpers are local to this file
| rather than borrowed from LaboratoryOrderApiTest, so nothing here depends on
| which test file PHPUnit happens to load first.
*/

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutMiddleware(EnsureMappedFacilitySubscriptionEntitlement::class);
});

function labFlowUser(): User
{
    $user = User::factory()->create();

    // The routes gate on the workspace abilities, not the CRUD permission
    // names — see routes/api.php's can:lab.sample.collect / can:lab.result.verify.
    foreach (['laboratory.orders.read', 'lab.sample.collect', 'lab.result.verify'] as $permission) {
        Permission::query()->firstOrCreate(['name' => $permission]);
        $user->givePermissionTo($permission);
    }

    return $user;
}

function labFlowPatient(): PatientModel
{
    return PatientModel::query()->create([
        'patient_number' => 'PT'.now()->format('Ymd').strtoupper(Str::random(6)),
        'first_name' => 'Neema',
        'middle_name' => null,
        'last_name' => 'Mwakasege',
        'gender' => 'female',
        'date_of_birth' => '1992-03-11',
        'phone' => '+255700000009',
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
 * A visit the doctor has already sent out for labs — waiting_provider with
 * consultation_started_at preserved, exactly what updateProviderWorkflow()
 * leaves behind.
 */
function labFlowAppointment(string $patientId): AppointmentModel
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

function labFlowOrder(string $patientId, ?string $appointmentId, string $status = 'ordered'): LaboratoryOrderModel
{
    return LaboratoryOrderModel::query()->create([
        'order_number' => 'LAB'.now()->format('Ymd').strtoupper(Str::random(6)),
        'patient_id' => $patientId,
        'appointment_id' => $appointmentId,
        'ordered_at' => now(),
        'test_code' => 'LOINC:57021-8',
        'test_name' => 'Complete Blood Count',
        'priority' => 'routine',
        'status' => $status,
    ]);
}

/**
 * @return \Illuminate\Support\Collection<int, PatientFlowEventModel>
 */
function labFlowEvents(string $appointmentId)
{
    return PatientFlowEventModel::query()
        ->where('appointment_id', $appointmentId)
        ->orderBy('occurred_at')
        ->orderBy('id')
        ->get();
}

/**
 * facility_id is a real foreign key, so the broadcast tests need an actual
 * facility rather than a literal.
 */
function labFlowFacility(): string
{
    $tenantId = (string) Str::uuid();
    $facilityId = (string) Str::uuid();

    DB::table('tenants')->insert([
        'id' => $tenantId,
        'code' => 'LFB-'.Str::upper(Str::random(4)),
        'name' => 'Laboratory Flow Test Tenant',
        'country_code' => 'TZ',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('facilities')->insert([
        'id' => $facilityId,
        'tenant_id' => $tenantId,
        'code' => 'LFB-'.Str::upper(Str::random(4)),
        'name' => 'Laboratory Flow Test Facility',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $facilityId;
}

it('records a flow event when a specimen is collected', function (): void {
    $user = labFlowUser();
    $patient = labFlowPatient();
    $appointment = labFlowAppointment($patient->id);
    $order = labFlowOrder($patient->id, $appointment->id);

    $this->actingAs($user)
        ->patchJson('/api/v1/laboratory-orders/'.$order->id.'/status', [
            'status' => 'collected',
            'reason' => null,
        ])
        ->assertOk();

    $event = labFlowEvents($appointment->id)->last();

    expect($event)->not->toBeNull();
    expect($event->source)->toBe('laboratory.specimen_collected');
    expect($event->to_step)->toBe('in_lab');
    expect($event->actor_user_id)->toBe($user->id);
});

it('records testing started as dated work that moves nobody', function (): void {
    $user = labFlowUser();
    $patient = labFlowPatient();
    $appointment = labFlowAppointment($patient->id);
    $order = labFlowOrder($patient->id, $appointment->id, 'collected');

    $this->actingAs($user)
        ->patchJson('/api/v1/laboratory-orders/'.$order->id.'/status', [
            'status' => 'in_progress',
            'reason' => null,
        ])
        ->assertOk();

    $event = labFlowEvents($appointment->id)->last();

    expect($event->source)->toBe('laboratory.testing_started');
    // Same step in and out — the patient is still in the lab. Without
    // allowSameStep this would be dropped as "not a transition" and the work
    // would never appear on the timeline.
    expect($event->to_step)->toBe('in_lab');
});

it('records result entry without handing the patient back, since nobody has verified it yet', function (): void {
    $user = labFlowUser();
    $patient = labFlowPatient();
    $appointment = labFlowAppointment($patient->id);
    $order = labFlowOrder($patient->id, $appointment->id, 'in_progress');

    $this->actingAs($user)
        ->patchJson('/api/v1/laboratory-orders/'.$order->id.'/status', [
            'status' => 'completed',
            'reason' => null,
            'resultSummary' => 'Haemoglobin 13.1 g/dL',
        ])
        ->assertOk();

    $event = labFlowEvents($appointment->id)->last();

    expect($event->source)->toBe('laboratory.result_entered');
    // A completed-but-unverified order drops out of the open worklist, so the
    // resolver stops counting it — but the lab still holds this visit.
    expect($event->to_step)->toBe('in_lab');
});

it('moves the patient to waiting_clinician_review when the last result is verified', function (): void {
    $user = labFlowUser();
    $patient = labFlowPatient();
    $appointment = labFlowAppointment($patient->id);
    $order = labFlowOrder($patient->id, $appointment->id, 'in_progress');

    $this->actingAs($user)
        ->patchJson('/api/v1/laboratory-orders/'.$order->id.'/status', [
            'status' => 'completed',
            'reason' => null,
            'resultSummary' => 'Haemoglobin 13.1 g/dL',
        ])
        ->assertOk();

    $this->actingAs($user)
        ->patchJson('/api/v1/laboratory-orders/'.$order->id.'/verify', [
            'verificationNote' => 'Reviewed and released.',
        ])
        ->assertOk();

    $event = labFlowEvents($appointment->id)->last();

    expect($event->source)->toBe('laboratory.result_verified');
    expect($event->to_step)->toBe('waiting_clinician_review');
});

it('keeps the patient in the lab when one of several orders is verified', function (): void {
    $user = labFlowUser();
    $patient = labFlowPatient();
    $appointment = labFlowAppointment($patient->id);
    $first = labFlowOrder($patient->id, $appointment->id, 'in_progress');
    // Still running. The step belongs to the visit, not to the order being
    // written — completing one of several must not move the patient.
    labFlowOrder($patient->id, $appointment->id, 'ordered');

    $this->actingAs($user)
        ->patchJson('/api/v1/laboratory-orders/'.$first->id.'/status', [
            'status' => 'completed',
            'reason' => null,
            'resultSummary' => 'Haemoglobin 13.1 g/dL',
        ])
        ->assertOk();

    $this->actingAs($user)
        ->patchJson('/api/v1/laboratory-orders/'.$first->id.'/verify', [
            'verificationNote' => 'Reviewed and released.',
        ])
        ->assertOk();

    $event = labFlowEvents($appointment->id)->last();

    expect($event->source)->toBe('laboratory.result_verified');
    expect($event->to_step)->toBe('waiting_lab');
    expect($event->to_step)->not->toBe('waiting_clinician_review');
});

it('never lets the laboratory write with_clinician', function (): void {
    $user = labFlowUser();
    $patient = labFlowPatient();
    $appointment = labFlowAppointment($patient->id);
    $order = labFlowOrder($patient->id, $appointment->id, 'in_progress');

    $this->actingAs($user)
        ->patchJson('/api/v1/laboratory-orders/'.$order->id.'/status', [
            'status' => 'completed',
            'reason' => null,
            'resultSummary' => 'Haemoglobin 13.1 g/dL',
        ])
        ->assertOk();

    $this->actingAs($user)
        ->patchJson('/api/v1/laboratory-orders/'.$order->id.'/verify', [
            'verificationNote' => 'Reviewed and released.',
        ])
        ->assertOk();

    // Decision 2: the lab moves patients between queues; only the doctor's own
    // Call Patient In may claim the patient is in a room with them.
    expect(labFlowEvents($appointment->id)->pluck('to_step')->all())
        ->not->toContain('with_clinician');
});

it('records nothing for a direct-service lab order with no appointment', function (): void {
    $user = labFlowUser();
    $patient = labFlowPatient();
    $order = labFlowOrder($patient->id, null);

    $this->actingAs($user)
        ->patchJson('/api/v1/laboratory-orders/'.$order->id.'/status', [
            'status' => 'collected',
            'reason' => null,
        ])
        ->assertOk();

    // Walk-ins straight to the lab flow through ServiceRequest as
    // waiting_direct_service/in_direct_service — a separate path this must not
    // write into.
    expect(PatientFlowEventModel::query()->where('patient_id', $patient->id)->count())->toBe(0);
});

it('does not fail the clinical action when the flow log cannot be written', function (): void {
    $user = labFlowUser();
    $patient = labFlowPatient();
    $appointment = labFlowAppointment($patient->id);
    $order = labFlowOrder($patient->id, $appointment->id);

    \Illuminate\Support\Facades\Schema::drop('patient_flow_events');

    // The log is best-effort by contract: a reporting gap must never become a
    // specimen the lab could not accession.
    $this->actingAs($user)
        ->patchJson('/api/v1/laboratory-orders/'.$order->id.'/status', [
            'status' => 'collected',
            'reason' => null,
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'collected');
});

/*
|--------------------------------------------------------------------------
| Phase 3 — the other three workspaces refresh on more than completion.
|--------------------------------------------------------------------------
|
| LaboratoryOrderCompleted was Laboratory's only domain event and fired only on
| `completed`, so accessioning, testing and verification — the three tabs the
| workspace is built around — changed nothing on anyone else's board until the
| order finished. Recording a transition broadcasts, so phase 2 carries this;
| these tests hold that property in place.
*/

it('refreshes every board when a specimen is accessioned, not only when the order completes', function (): void {
    Event::fake([PatientFlowBoardUpdated::class]);

    $user = labFlowUser();
    $patient = labFlowPatient();
    $appointment = labFlowAppointment($patient->id);
    $facilityId = labFlowFacility();
    $order = labFlowOrder($patient->id, $appointment->id);
    $order->forceFill(['facility_id' => $facilityId])->save();

    $this->actingAs($user)
        ->patchJson('/api/v1/laboratory-orders/'.$order->id.'/status', [
            'status' => 'collected',
            'reason' => null,
        ])
        ->assertOk();

    Event::assertDispatched(
        PatientFlowBoardUpdated::class,
        fn (PatientFlowBoardUpdated $event): bool => $event->facilityId === $facilityId,
    );
});

it('refreshes every board when a result is verified', function (): void {
    Event::fake([PatientFlowBoardUpdated::class]);

    $user = labFlowUser();
    $patient = labFlowPatient();
    $appointment = labFlowAppointment($patient->id);
    $facilityId = labFlowFacility();
    $order = labFlowOrder($patient->id, $appointment->id, 'in_progress');
    $order->forceFill(['facility_id' => $facilityId])->save();

    $this->actingAs($user)
        ->patchJson('/api/v1/laboratory-orders/'.$order->id.'/status', [
            'status' => 'completed',
            'reason' => null,
            'resultSummary' => 'Haemoglobin 13.1 g/dL',
        ])
        ->assertOk();

    $this->actingAs($user)
        ->patchJson('/api/v1/laboratory-orders/'.$order->id.'/verify', [
            'verificationNote' => 'Reviewed and released.',
        ])
        ->assertOk();

    Event::assertDispatched(
        PatientFlowBoardUpdated::class,
        fn (PatientFlowBoardUpdated $event): bool => $event->facilityId === $facilityId,
    );
});
