<?php

use App\Models\User;
use App\Modules\Appointment\Application\UseCases\UpdateAppointmentStatusUseCase;
use App\Modules\Appointment\Domain\Events\AppointmentStatusChanged;
use App\Modules\Laboratory\Domain\Events\LaboratoryOrderCompleted;
use App\Modules\PatientFlow\Application\Services\PatientFlowBoardChannelAuthorizer;
use App\Modules\PatientFlow\Domain\Events\PatientFlowBoardUpdated;
use App\Modules\Pharmacy\Domain\Events\PharmacyOrderDispensed;
use App\Modules\Radiology\Domain\Events\RadiologyOrderCompleted;
use App\Modules\Reception\Domain\Events\AppointmentCheckedIn;
use App\Modules\ServiceRequest\Application\Services\ServiceRequestDepartmentScope;
use App\Modules\ServiceRequest\Application\UseCases\UpdateServiceRequestStatusUseCase;
use App\Modules\ServiceRequest\Domain\Events\ServiceRequestStatusChanged;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Phase 2 of the Patient-Flow Board roadmap: one board-scoped broadcast
 * event (PatientFlowBoardUpdated), fired by a translating listener
 * (BroadcastPatientFlowBoardUpdate) that subscribes to 5 cross-module domain
 * events. These tests exercise the real, registered event->listener wiring
 * (PatientFlowServiceProvider::boot()) end-to-end, faking only the terminal
 * broadcast event so the actual listener chain runs for real.
 */
function seedPatientFlowFacility(int $userId, bool $active = true): string
{
    $tenantId = (string) Str::uuid();
    $facilityId = (string) Str::uuid();

    DB::table('tenants')->insert([
        'id' => $tenantId,
        'code' => 'PFB-'.Str::upper(Str::random(4)),
        'name' => 'PatientFlow Broadcast Test Tenant',
        'country_code' => 'TZ',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('facilities')->insert([
        'id' => $facilityId,
        'tenant_id' => $tenantId,
        'code' => 'PFB-'.Str::upper(Str::random(4)),
        'name' => 'PatientFlow Broadcast Test Facility',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('facility_user')->insert([
        'facility_id' => $facilityId,
        'user_id' => $userId,
        'role' => 'clinician',
        'is_primary' => true,
        'is_active' => $active,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $facilityId;
}

it('implements ShouldBroadcast, queued, not ShouldBroadcastNow', function (): void {
    $event = new PatientFlowBoardUpdated('some-facility-id');

    expect($event)->toBeInstanceOf(ShouldBroadcast::class);
    expect($event)->not->toBeInstanceOf(ShouldBroadcastNow::class);
});

it('broadcasts on the facility-scoped private channel when facilityId is set', function (): void {
    $event = new PatientFlowBoardUpdated('facility-123');

    $channels = $event->broadcastOn();

    expect($channels)->toHaveCount(1);
    expect($channels[0])->toBeInstanceOf(PrivateChannel::class);
    expect($channels[0]->name)->toBe('private-patient-flow.facility-123');
    expect($event->broadcastAs())->toBe('board.updated');
});

it('broadcasts on no channel at all when facilityId is null', function (): void {
    $event = new PatientFlowBoardUpdated(null);

    expect($event->broadcastOn())->toBe([]);
});

it('dispatches AppointmentStatusChanged with the appointment facility_id on a real status transition', function (): void {
    Event::fake([AppointmentStatusChanged::class]);

    $user = User::factory()->create();
    $facilityId = seedPatientFlowFacility($user->id);
    $patient = makePatientFlowPatient();
    $appointment = makePatientFlowAppointment($patient->id, [
        'status' => 'waiting_provider',
        'facility_id' => $facilityId,
    ]);

    app(UpdateAppointmentStatusUseCase::class)->execute(
        id: $appointment->id,
        status: 'in_consultation',
        reason: null,
    );

    Event::assertDispatched(
        AppointmentStatusChanged::class,
        fn (AppointmentStatusChanged $event): bool => $event->appointmentId === $appointment->id
            && $event->oldStatus === 'waiting_provider'
            && $event->newStatus === 'in_consultation'
            && $event->facilityId === $facilityId,
    );
});

it('translates AppointmentStatusChanged into PatientFlowBoardUpdated with the same facilityId', function (): void {
    Event::fake([PatientFlowBoardUpdated::class]);

    event(new AppointmentStatusChanged(
        appointmentId: (string) Str::uuid(),
        patientId: (string) Str::uuid(),
        oldStatus: 'waiting_provider',
        newStatus: 'in_consultation',
        actorId: null,
        facilityId: 'facility-abc',
    ));

    Event::assertDispatched(
        PatientFlowBoardUpdated::class,
        fn (PatientFlowBoardUpdated $event): bool => $event->facilityId === 'facility-abc',
    );
});

it('translates AppointmentCheckedIn into PatientFlowBoardUpdated with the same facilityId', function (): void {
    Event::fake([PatientFlowBoardUpdated::class]);

    event(new AppointmentCheckedIn(
        appointmentId: (string) Str::uuid(),
        patientId: (string) Str::uuid(),
        arrivalMode: 'walk_in',
        actorId: null,
        facilityId: 'facility-def',
    ));

    Event::assertDispatched(
        PatientFlowBoardUpdated::class,
        fn (PatientFlowBoardUpdated $event): bool => $event->facilityId === 'facility-def',
    );
});

it('translates LaboratoryOrderCompleted into PatientFlowBoardUpdated with the same facilityId', function (): void {
    Event::fake([PatientFlowBoardUpdated::class]);

    event(new LaboratoryOrderCompleted(
        laboratoryOrderId: (string) Str::uuid(),
        patientId: (string) Str::uuid(),
        appointmentId: null,
        orderedByUserId: null,
        actorId: null,
        facilityId: 'facility-ghi',
    ));

    Event::assertDispatched(
        PatientFlowBoardUpdated::class,
        fn (PatientFlowBoardUpdated $event): bool => $event->facilityId === 'facility-ghi',
    );
});

it('translates PharmacyOrderDispensed into PatientFlowBoardUpdated with the same facilityId', function (): void {
    Event::fake([PatientFlowBoardUpdated::class]);

    event(new PharmacyOrderDispensed(
        pharmacyOrderId: (string) Str::uuid(),
        patientId: (string) Str::uuid(),
        appointmentId: null,
        orderedByUserId: null,
        actorId: null,
        facilityId: 'facility-jkl',
    ));

    Event::assertDispatched(
        PatientFlowBoardUpdated::class,
        fn (PatientFlowBoardUpdated $event): bool => $event->facilityId === 'facility-jkl',
    );
});

it('translates RadiologyOrderCompleted into PatientFlowBoardUpdated with the same facilityId', function (): void {
    Event::fake([PatientFlowBoardUpdated::class]);

    event(new RadiologyOrderCompleted(
        radiologyOrderId: (string) Str::uuid(),
        patientId: (string) Str::uuid(),
        appointmentId: null,
        orderedByUserId: null,
        actorId: null,
        facilityId: 'facility-mno',
    ));

    Event::assertDispatched(
        PatientFlowBoardUpdated::class,
        fn (PatientFlowBoardUpdated $event): bool => $event->facilityId === 'facility-mno',
    );
});

it('dispatches ServiceRequestStatusChanged with the request facility_id on a real status transition', function (): void {
    Event::fake([ServiceRequestStatusChanged::class]);

    $user = User::factory()->create();
    $facilityId = seedPatientFlowFacility($user->id);
    $patient = makePatientFlowPatient();
    $serviceRequest = makePatientFlowServiceRequest($patient->id, 'pending');
    $serviceRequest->forceFill(['facility_id' => $facilityId])->save();

    app(UpdateServiceRequestStatusUseCase::class)->execute(
        id: $serviceRequest->id,
        newStatus: 'in_progress',
        scope: new ServiceRequestDepartmentScope(canViewAllDepartments: true, departmentId: null),
    );

    Event::assertDispatched(
        ServiceRequestStatusChanged::class,
        fn (ServiceRequestStatusChanged $event): bool => $event->serviceRequestId === $serviceRequest->id
            && $event->oldStatus === 'pending'
            && $event->newStatus === 'in_progress'
            && $event->facilityId === $facilityId,
    );
});

it('translates ServiceRequestStatusChanged into PatientFlowBoardUpdated with the same facilityId', function (): void {
    Event::fake([PatientFlowBoardUpdated::class]);

    event(new ServiceRequestStatusChanged(
        serviceRequestId: (string) Str::uuid(),
        patientId: (string) Str::uuid(),
        oldStatus: 'pending',
        newStatus: 'in_progress',
        actorId: null,
        facilityId: 'facility-pqr',
    ));

    Event::assertDispatched(
        PatientFlowBoardUpdated::class,
        fn (PatientFlowBoardUpdated $event): bool => $event->facilityId === 'facility-pqr',
    );
});

/**
 * Direct unit tests of the channel authorization logic, not an HTTP round
 * trip through /broadcasting/auth — the test suite forces
 * BROADCAST_CONNECTION=null (phpunit.xml), under which the null broadcaster
 * no-ops and never actually invokes a Broadcast::channel() closure, so an
 * HTTP-level test here would silently pass regardless of the real logic.
 * PatientFlowBoardChannelAuthorizer exists specifically so this logic has a
 * reliable, driver-independent test surface.
 */
it('authorizes the patient-flow board channel for a user with appointments.read and active facility access', function (): void {
    $user = User::factory()->create();
    $user->givePermissionTo('appointments.read');
    $facilityId = seedPatientFlowFacility($user->id);

    expect(app(PatientFlowBoardChannelAuthorizer::class)->authorize($user, $facilityId))->toBeTrue();
});

it('forbids the patient-flow board channel for a user without appointments.read', function (): void {
    $user = User::factory()->create();
    $facilityId = seedPatientFlowFacility($user->id);

    expect(app(PatientFlowBoardChannelAuthorizer::class)->authorize($user, $facilityId))->toBeFalse();
});

it('forbids the patient-flow board channel for a user with appointments.read but no facility access', function (): void {
    $user = User::factory()->create();
    $user->givePermissionTo('appointments.read');
    $otherUser = User::factory()->create();
    $facilityId = seedPatientFlowFacility($otherUser->id);

    expect(app(PatientFlowBoardChannelAuthorizer::class)->authorize($user, $facilityId))->toBeFalse();
});

it('forbids the patient-flow board channel for a user whose facility assignment is inactive', function (): void {
    $user = User::factory()->create();
    $user->givePermissionTo('appointments.read');
    $facilityId = seedPatientFlowFacility($user->id, active: false);

    expect(app(PatientFlowBoardChannelAuthorizer::class)->authorize($user, $facilityId))->toBeFalse();
});
