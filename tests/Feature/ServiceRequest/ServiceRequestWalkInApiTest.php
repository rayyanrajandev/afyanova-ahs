<?php

declare(strict_types=1);

use App\Http\Middleware\EnforceTenantIsolationWhenEnabled;
use App\Http\Middleware\EnsureFacilitySubscriptionEntitlement;
use App\Http\Middleware\EnsureMappedFacilitySubscriptionEntitlement;
use App\Models\User;
use App\Modules\Appointment\Infrastructure\Models\AppointmentModel;
use App\Modules\Department\Infrastructure\Models\DepartmentModel;
use App\Modules\Patient\Infrastructure\Models\PatientModel;
use App\Modules\ServiceRequest\Infrastructure\Models\ServiceRequestAuditEventModel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->withoutMiddleware(EnsureFacilitySubscriptionEntitlement::class);
    $this->withoutMiddleware(EnsureMappedFacilitySubscriptionEntitlement::class);
    $this->withoutMiddleware(EnforceTenantIsolationWhenEnabled::class);
});

afterEach(function (): void {
    config()->set('feature_flags.flags.platform.multi_tenant_isolation.enabled', false);
    config()->set('feature_flags.flags.platform.multi_facility_scoping.enabled', false);
});

function makeWalkInPatient(string $suffix = ''): PatientModel
{
    $stamp = Str::upper(Str::random(6));

    return PatientModel::query()->create([
        'patient_number' => 'PTSRA'.$stamp.$suffix,
        'first_name' => 'Walk',
        'middle_name' => null,
        'last_name' => 'InPatient'.$suffix,
        'gender' => 'female',
        'date_of_birth' => '1998-06-01',
        'phone' => '+255700'.substr($stamp.'000001', 0, 6),
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
 * Grants service.requests.view-all-departments so these generic
 * create/list/status-transition tests aren't affected by Direct Service
 * Queue V2's hard department enforcement (ServiceRequestDepartmentScope) —
 * that behavior has its own dedicated coverage in
 * ServiceRequestDepartmentScopeTest.php.
 */
function userWithWalkInRights(): User
{
    $user = User::factory()->create();

    foreach (
        [
            'patients.read',
            'patients.create',
            'service.requests.create',
            'service.requests.read',
            'service.requests.update-status',
            'service.requests.view-all-departments',
        ] as $permission
    ) {
        $user->givePermissionTo($permission);
    }

    return $user;
}

it('lists active department options for walk-in readers', function (): void {
    $user = userWithWalkInRights();

    $dept = DepartmentModel::query()->create([
        'code' => 'PHARM',
        'name' => 'Main Pharmacy',
        'service_type' => 'Pharmacy & Dispensing',
        'status' => 'active',
    ]);

    DepartmentModel::query()->create([
        'code' => 'REC',
        'name' => 'Medical Records',
        'service_type' => 'Medical Records',
        'status' => 'active',
    ]);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/service-requests/department-options?serviceType=pharmacy')
        ->assertOk();

    $rows = $response->json('data');
    expect($rows)->toBeArray();
    $match = collect($rows)->firstWhere('value', $dept->id);
    expect($match)->not->toBeNull()
        ->and($match['label'] ?? '')->toContain('Main Pharmacy')
        ->and(collect($rows)->pluck('label')->implode(' '))->not->toContain('Medical Records');
});

it('stores optional department id when creating a walk-in ticket', function (): void {
    $user = userWithWalkInRights();
    $patient = makeWalkInPatient('H');

    $dept = DepartmentModel::query()->create([
        'code' => 'WID2',
        'name' => 'Destination Dept',
        'service_type' => 'Clinical',
        'status' => 'active',
    ]);

    $this->actingAs($user)->postJson('/api/v1/service-requests', [
        'patientId' => $patient->id,
        'serviceType' => 'laboratory',
        'departmentId' => $dept->id,
    ])->assertCreated()
        ->assertJsonPath('data.departmentId', $dept->id)
        ->assertJsonPath('data.department.id', $dept->id)
        ->assertJsonPath('data.department.label', 'WID2 - Destination Dept');

    $this->assertDatabaseHas('service_requests', [
        'patient_id' => $patient->id,
        'department_id' => $dept->id,
    ]);
});

it('records audit events when creating and updating walk-in ticket status', function (): void {
    $user = userWithWalkInRights();
    $patient = makeWalkInPatient('A');

    $response = $this->actingAs($user)->postJson('/api/v1/service-requests', [
        'patientId' => $patient->id,
        'serviceType' => 'laboratory',
        'priority' => 'routine',
    ])->assertCreated();

    $id = $response->json('data.id');
    expect($id)->toBeString()
        ->and(ServiceRequestAuditEventModel::query()->where('service_request_id', $id)->where('action', 'service_request.created')->exists())->toBeTrue();

    $this->actingAs($user)->patchJson("/api/v1/service-requests/{$id}/status", [
        'status' => 'in_progress',
    ])->assertOk();

    expect(
        ServiceRequestAuditEventModel::query()
            ->where('service_request_id', $id)
            ->where('action', 'service_request.status_updated')
            ->where('from_status', 'pending')
            ->where('to_status', 'in_progress')
            ->exists(),
    )->toBeTrue();
});

it('stores optional appointment linkage when ids match patient', function (): void {
    $user = userWithWalkInRights();
    $patient = makeWalkInPatient('B');

    $appointment = AppointmentModel::query()->create([
        'id' => Str::uuid()->toString(),
        'appointment_number' => 'APTWSR'.Str::upper(Str::random(4)),
        'patient_id' => $patient->id,
        'clinician_user_id' => null,
        'department' => 'OPD',
        'scheduled_at' => Carbon::now()->addHour(),
        'duration_minutes' => 30,
        'reason' => 'Labs only',
        'notes' => null,
        'status' => 'scheduled',
        'status_reason' => null,
    ]);

    $created = $this->actingAs($user)->postJson('/api/v1/service-requests', [
        'patientId' => $patient->id,
        'appointmentId' => $appointment->id,
        'serviceType' => 'laboratory',
    ])->assertCreated()
        ->assertJsonPath('data.appointmentId', $appointment->id)
        ->json('data');

    $listed = $this->actingAs($user)->getJson('/api/v1/service-requests?serviceType=laboratory&status=pending')
        ->assertOk()
        ->json('data');

    $row = collect($listed)->firstWhere('id', $created['id']);

    expect($row)->toBeArray()
        ->and($row['appointmentId'] ?? null)->toBe($appointment->id);
});

it('can create a procedure walk-in ticket', function (): void {
    $user = userWithWalkInRights();
    $patient = makeWalkInPatient('P');

    $this->actingAs($user)->postJson('/api/v1/service-requests', [
        'patientId' => $patient->id,
        'serviceType' => 'theatre_procedure',
    ])->assertCreated()
        ->assertJsonPath('data.serviceType', 'theatre_procedure');
});

it('rejects duplicate active tickets for the same patient and service desk', function (): void {
    $user = userWithWalkInRights();
    $patient = makeWalkInPatient('Q');

    $payload = [
        'patientId' => $patient->id,
        'serviceType' => 'pharmacy',
    ];

    $this->actingAs($user)->postJson('/api/v1/service-requests', $payload)
        ->assertCreated();

    $this->actingAs($user)->postJson('/api/v1/service-requests', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['serviceType']);
});

it('rejects appointment linkage that points at another patient', function (): void {
    $user = userWithWalkInRights();
    $patientA = makeWalkInPatient('C');
    $patientB = makeWalkInPatient('D');

    $appointmentOther = AppointmentModel::query()->create([
        'id' => Str::uuid()->toString(),
        'appointment_number' => 'APTWSRO'.Str::upper(Str::random(4)),
        'patient_id' => $patientB->id,
        'clinician_user_id' => null,
        'department' => 'OPD',
        'scheduled_at' => Carbon::now()->addHour(),
        'duration_minutes' => 30,
        'reason' => null,
        'notes' => null,
        'status' => 'scheduled',
        'status_reason' => null,
    ]);

    $this->actingAs($user)->postJson('/api/v1/service-requests', [
        'patientId' => $patientA->id,
        'appointmentId' => $appointmentOther->id,
        'serviceType' => 'laboratory',
    ])->assertUnprocessable();
});

it('includes routing summary on patient index for plain patient readers when clinical flag grants it', function (): void {
    $creator = userWithWalkInRights();
    $patient = makeWalkInPatient('E');

    $this->actingAs($creator)->postJson('/api/v1/service-requests', [
        'patientId' => $patient->id,
        'serviceType' => 'pharmacy',
    ])->assertCreated();

    $viewer = User::factory()->create();
    $viewer->givePermissionTo('patients.read');

    $listed = $this->actingAs($viewer)->getJson('/api/v1/patients?perPage=64')->assertOk();
    $row = collect($listed->json('data'))->firstWhere('id', $patient->id);

    expect($row)->toBeArray()
        ->and($row['routingHandoffSummary'] ?? null)->not->toBeNull();
});

it('exposes immutable audit log list for authorized readers', function (): void {
    config()->set('feature_flags.flags.platform.multi_tenant_isolation.enabled', false);
    config()->set('feature_flags.flags.platform.multi_facility_scoping.enabled', false);

    $user = userWithWalkInRights();
    $patient = makeWalkInPatient('G');

    $created = $this->actingAs($user)->postJson('/api/v1/service-requests', [
        'patientId' => $patient->id,
        'serviceType' => 'pharmacy',
    ])->assertCreated()->json('data');

    $user->givePermissionTo('service.requests.audit-logs.read');

    $this->actingAs($user)->getJson('/api/v1/service-requests/'.$created['id'].'/audit-events')
        ->assertOk()
        ->assertJsonPath('data.0.action', 'service_request.created');
});

it('lets facility admin export csv', function (): void {
    config()->set('feature_flags.flags.platform.multi_tenant_isolation.enabled', false);
    config()->set('feature_flags.flags.platform.multi_facility_scoping.enabled', false);

    $user = userWithWalkInRights();
    $patient = makeWalkInPatient('F');

    $this->actingAs($user)->postJson('/api/v1/service-requests', [
        'patientId' => $patient->id,
        'serviceType' => 'radiology',
    ])->assertCreated();

    $user->givePermissionTo('service.requests.export');

    $this->actingAs($user)->get('/api/v1/service-requests/export/csv')
        ->assertOk()
        ->assertHeader('content-type', 'text/csv; charset=UTF-8');
});
