<?php

use App\Modules\Appointment\Infrastructure\Models\AppointmentModel;
use App\Modules\Patient\Infrastructure\Models\PatientAuditLogModel;
use App\Modules\Patient\Infrastructure\Models\PatientModel;
use App\Modules\PatientFlow\Application\Services\PatientFlowBoardChannelAuthorizer;
use App\Modules\Reception\Domain\Events\AppointmentCalled;
use App\Models\User;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Volume 2.1 §10.3 "Call" (Volume 3.7 §16 #3, decided + implemented
 * 2026-08-11): ephemeral broadcast only — no persisted AppointmentStatus
 * case, no patient-audit-trail write. See AppointmentCalled/
 * CallQueueItemUseCase's own docblocks for the full reasoning. These tests
 * exercise both the event shape (mirrors PatientFlowBoardBroadcastTest.php's
 * own pattern) and the real HTTP round trip, with explicit negative
 * assertions (status unchanged, no audit entry) — the whole point of this
 * design is what it does NOT do.
 */
function callActionFacility(int $userId): string
{
    $tenantId = (string) Str::uuid();
    $facilityId = (string) Str::uuid();

    DB::table('tenants')->insert([
        'id' => $tenantId,
        'code' => 'CALL-'.Str::upper(Str::random(4)),
        'name' => 'Call Action Test Tenant',
        'country_code' => 'TZ',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('facilities')->insert([
        'id' => $facilityId,
        'tenant_id' => $tenantId,
        'code' => 'CALL-'.Str::upper(Str::random(4)),
        'name' => 'Call Action Test Facility',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('facility_user')->insert([
        'facility_id' => $facilityId,
        'user_id' => $userId,
        'role' => 'registration_clerk',
        'is_primary' => true,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $facilityId;
}

function callActionPatient(): PatientModel
{
    return PatientModel::query()->create([
        'patient_number' => 'PTCALL'.now()->format('Ymd').strtoupper(Str::random(6)),
        'first_name' => 'Neema', 'last_name' => 'Kessy', 'gender' => 'female',
        'date_of_birth' => '1988-03-02', 'phone' => '+255700000099', 'country_code' => 'TZ',
        'status' => 'active',
    ]);
}

it('implements ShouldBroadcast, queued, not ShouldBroadcastNow', function (): void {
    $event = new AppointmentCalled(facilityId: 'f1', appointmentId: 'a1', patientName: 'Neema Kessy');

    expect($event)->toBeInstanceOf(ShouldBroadcast::class);
    expect($event)->not->toBeInstanceOf(ShouldBroadcastNow::class);
});

it('broadcasts on its own reception-queue channel, not patient-flow', function (): void {
    $event = new AppointmentCalled(facilityId: 'facility-123', appointmentId: 'a1', patientName: 'Neema Kessy');

    $channels = $event->broadcastOn();

    expect($channels)->toHaveCount(1);
    expect($channels[0])->toBeInstanceOf(PrivateChannel::class);
    expect($channels[0]->name)->toBe('private-reception-queue.facility-123');
    expect($event->broadcastAs())->toBe('queue.appointment-called');
});

it('carries appointmentId and patientName in the broadcast payload, not facilityId', function (): void {
    $event = new AppointmentCalled(facilityId: 'facility-123', appointmentId: 'apt-1', patientName: 'Neema Kessy');

    expect($event->broadcastWith())->toBe([
        'appointmentId' => 'apt-1',
        'patientName' => 'Neema Kessy',
    ]);
});

it('dispatches AppointmentCalled with the appointment facility_id and patient name on POST reception/queue/{id}/call', function (): void {
    Event::fake([AppointmentCalled::class]);

    $user = User::factory()->create();
    $user->givePermissionTo('appointments.read');
    $facilityId = callActionFacility($user->id);
    $patient = callActionPatient();
    $appointment = AppointmentModel::query()->create([
        'appointment_number' => 'APTCALL'.strtoupper(Str::random(8)),
        'patient_id' => $patient->id,
        'facility_id' => $facilityId,
        'department' => 'Outpatient',
        'scheduled_at' => now(),
        'duration_minutes' => 30,
        'status' => 'waiting_triage',
    ]);

    $this->actingAs($user)
        ->postJson('/api/v1/reception/queue/'.$appointment->id.'/call')
        ->assertOk();

    Event::assertDispatched(
        AppointmentCalled::class,
        fn (AppointmentCalled $event): bool => $event->appointmentId === $appointment->id
            && $event->facilityId === $facilityId
            && $event->patientName === 'Neema Kessy',
    );
});

it('does not change the appointment status', function (): void {
    $user = User::factory()->create();
    $user->givePermissionTo('appointments.read');
    $facilityId = callActionFacility($user->id);
    $patient = callActionPatient();
    $appointment = AppointmentModel::query()->create([
        'appointment_number' => 'APTCALL'.strtoupper(Str::random(8)),
        'patient_id' => $patient->id,
        'facility_id' => $facilityId,
        'department' => 'Outpatient',
        'scheduled_at' => now(),
        'duration_minutes' => 30,
        'status' => 'waiting_triage',
    ]);

    $this->actingAs($user)
        ->postJson('/api/v1/reception/queue/'.$appointment->id.'/call')
        ->assertOk();

    expect($appointment->fresh()->status)->toBe('waiting_triage');
});

it('does not write to the patient audit trail — ephemeral by design, unlike check-in/cancel', function (): void {
    $user = User::factory()->create();
    $user->givePermissionTo('appointments.read');
    $facilityId = callActionFacility($user->id);
    $patient = callActionPatient();
    $appointment = AppointmentModel::query()->create([
        'appointment_number' => 'APTCALL'.strtoupper(Str::random(8)),
        'patient_id' => $patient->id,
        'facility_id' => $facilityId,
        'department' => 'Outpatient',
        'scheduled_at' => now(),
        'duration_minutes' => 30,
        'status' => 'waiting_triage',
    ]);

    $this->actingAs($user)
        ->postJson('/api/v1/reception/queue/'.$appointment->id.'/call')
        ->assertOk();

    expect(PatientAuditLogModel::query()->where('patient_id', $patient->id)->count())->toBe(0);
});

it('returns 404 for a nonexistent appointment', function (): void {
    $user = User::factory()->create();
    $user->givePermissionTo('appointments.read');

    $this->actingAs($user)
        ->postJson('/api/v1/reception/queue/'.Str::uuid().'/call')
        ->assertNotFound();
});

it('forbids the call action without appointments.read', function (): void {
    $user = User::factory()->create();
    $patient = callActionPatient();
    $appointment = AppointmentModel::query()->create([
        'appointment_number' => 'APTCALL'.strtoupper(Str::random(8)),
        'patient_id' => $patient->id,
        'department' => 'Outpatient',
        'scheduled_at' => now(),
        'duration_minutes' => 30,
        'status' => 'waiting_triage',
    ]);

    $this->actingAs($user)
        ->postJson('/api/v1/reception/queue/'.$appointment->id.'/call')
        ->assertForbidden();
});

/**
 * Direct unit test of the reused channel authorizer, same reasoning as
 * PatientFlowBoardBroadcastTest.php's own equivalent block: BROADCAST_
 * CONNECTION=null in tests means an HTTP round trip through
 * /broadcasting/auth would never actually invoke the Broadcast::channel()
 * closure either way.
 */
it('authorizes the reception-queue channel for a user with appointments.read and active facility access', function (): void {
    $user = User::factory()->create();
    $user->givePermissionTo('appointments.read');
    $facilityId = callActionFacility($user->id);

    expect(app(PatientFlowBoardChannelAuthorizer::class)->authorize($user, $facilityId))->toBeTrue();
});
