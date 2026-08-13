<?php

declare(strict_types=1);

use App\Http\Middleware\EnforceTenantIsolationWhenEnabled;
use App\Http\Middleware\EnsureFacilitySubscriptionEntitlement;
use App\Http\Middleware\EnsureMappedFacilitySubscriptionEntitlement;
use App\Models\User;
use App\Modules\Department\Infrastructure\Models\DepartmentModel;
use App\Modules\Patient\Infrastructure\Models\PatientModel;
use App\Modules\Staff\Infrastructure\Models\StaffProfileModel;
use Illuminate\Support\Str;

/**
 * Coverage for Direct Service Queue V2's hard department enforcement — see
 * ServiceRequestDepartmentScopeResolver's docblock and the patient flow
 * redesign plan's B1. Distinct from ServiceRequestWalkInApiTest.php's
 * generic create/list/status-transition coverage, which grants
 * service.requests.view-all-departments so it isn't affected by this.
 */
beforeEach(function (): void {
    $this->withoutMiddleware(EnsureFacilitySubscriptionEntitlement::class);
    $this->withoutMiddleware(EnsureMappedFacilitySubscriptionEntitlement::class);
    $this->withoutMiddleware(EnforceTenantIsolationWhenEnabled::class);
});

afterEach(function (): void {
    config()->set('feature_flags.flags.platform.multi_tenant_isolation.enabled', false);
    config()->set('feature_flags.flags.platform.multi_facility_scoping.enabled', false);
});

function scopeTestDepartment(string $code): DepartmentModel
{
    return DepartmentModel::query()->create([
        'code' => $code,
        'name' => $code.' Department',
        'service_type' => 'Clinical',
        'status' => 'active',
    ]);
}

function scopeTestPatient(string $suffix): PatientModel
{
    $stamp = Str::upper(Str::random(6));

    return PatientModel::query()->create([
        'patient_number' => 'PTDS'.$stamp.$suffix,
        'first_name' => 'Scope',
        'last_name' => 'Test'.$suffix,
        'gender' => 'female',
        'date_of_birth' => '1990-01-01',
        'phone' => '+255700'.substr($stamp.'000002', 0, 6),
        'country_code' => 'TZ',
        'status' => 'active',
    ]);
}

function userScopedToDepartment(?string $departmentId): User
{
    $user = User::factory()->create();
    foreach (['service.requests.create', 'service.requests.read', 'service.requests.update-status'] as $permission) {
        $user->givePermissionTo($permission);
    }

    if ($departmentId !== null) {
        StaffProfileModel::query()->create([
            'user_id' => $user->id,
            'department_id' => $departmentId,
            'employee_number' => 'EMP'.strtoupper(Str::random(8)),
            'department' => 'Legacy free-text field, unused by the scope resolver',
            'job_title' => 'Lab Technician',
            'employment_type' => 'full_time',
            'status' => 'active',
        ]);
    }

    return $user;
}

function userWithViewAllDepartments(): User
{
    $user = User::factory()->create();
    foreach ([
        'service.requests.create',
        'service.requests.read',
        'service.requests.update-status',
        'service.requests.view-all-departments',
    ] as $permission) {
        $user->givePermissionTo($permission);
    }

    return $user;
}

it('lists all service requests regardless of the actors department', function (): void {
    $deptA = scopeTestDepartment('SCA');
    $deptB = scopeTestDepartment('SCB');
    $creator = userWithViewAllDepartments();

    $ticketA = $this->actingAs($creator)->postJson('/api/v1/service-requests', [
        'patientId' => scopeTestPatient('A')->id,
        'serviceType' => 'laboratory',
        'departmentId' => $deptA->id,
    ])->assertCreated()->json('data');

    $ticketB = $this->actingAs($creator)->postJson('/api/v1/service-requests', [
        'patientId' => scopeTestPatient('B')->id,
        'serviceType' => 'laboratory',
        'departmentId' => $deptB->id,
    ])->assertCreated()->json('data');

    $scopedUser = userScopedToDepartment($deptA->id);

    $listed = $this->actingAs($scopedUser)
        ->getJson('/api/v1/service-requests')
        ->assertOk()
        ->json('data');

    $ids = collect($listed)->pluck('id')->all();
    expect($ids)->toContain($ticketA['id']);
    expect($ids)->toContain($ticketB['id']);
});

it('returns an empty list with departmentScopeMissing when the actor has no department assigned', function (): void {
    $creator = userWithViewAllDepartments();
    $dept = scopeTestDepartment('SCC');

    $this->actingAs($creator)->postJson('/api/v1/service-requests', [
        'patientId' => scopeTestPatient('C')->id,
        'serviceType' => 'laboratory',
        'departmentId' => $dept->id,
    ])->assertCreated();

    $unassignedUser = userScopedToDepartment(null);

    $response = $this->actingAs($unassignedUser)
        ->getJson('/api/v1/service-requests')
        ->assertOk();

    expect($response->json('data'))->toBe([]);
    expect($response->json('meta.departmentScopeMissing'))->toBeTrue();
});

it('lets a view-all-departments holder filter by any department', function (): void {
    $deptA = scopeTestDepartment('SCD');
    $deptB = scopeTestDepartment('SCE');
    $creator = userWithViewAllDepartments();

    $this->actingAs($creator)->postJson('/api/v1/service-requests', [
        'patientId' => scopeTestPatient('D')->id,
        'serviceType' => 'laboratory',
        'departmentId' => $deptA->id,
    ])->assertCreated();

    $ticketB = $this->actingAs($creator)->postJson('/api/v1/service-requests', [
        'patientId' => scopeTestPatient('E')->id,
        'serviceType' => 'laboratory',
        'departmentId' => $deptB->id,
    ])->assertCreated()->json('data');

    $listed = $this->actingAs($creator)
        ->getJson('/api/v1/service-requests?departmentId='.$deptB->id)
        ->assertOk()
        ->json('data');

    expect(collect($listed)->pluck('id')->all())->toBe([$ticketB['id']]);
});

it('blocks a department-scoped actor from transitioning another departments ticket', function (): void {
    $deptA = scopeTestDepartment('SCF');
    $deptB = scopeTestDepartment('SCG');
    $creator = userWithViewAllDepartments();

    $ticketB = $this->actingAs($creator)->postJson('/api/v1/service-requests', [
        'patientId' => scopeTestPatient('F')->id,
        'serviceType' => 'laboratory',
        'departmentId' => $deptB->id,
    ])->assertCreated()->json('data');

    $scopedToA = userScopedToDepartment($deptA->id);

    $this->actingAs($scopedToA)
        ->patchJson('/api/v1/service-requests/'.$ticketB['id'].'/status', ['status' => 'in_progress'])
        ->assertStatus(403)
        ->assertJsonPath('code', 'DEPARTMENT_SCOPE_FORBIDDEN');
});

it('allows a department-scoped actor to transition their own departments ticket', function (): void {
    $dept = scopeTestDepartment('SCH');
    $creator = userWithViewAllDepartments();

    $ticket = $this->actingAs($creator)->postJson('/api/v1/service-requests', [
        'patientId' => scopeTestPatient('H')->id,
        'serviceType' => 'laboratory',
        'departmentId' => $dept->id,
    ])->assertCreated()->json('data');

    $scopedUser = userScopedToDepartment($dept->id);

    $this->actingAs($scopedUser)
        ->patchJson('/api/v1/service-requests/'.$ticket['id'].'/status', ['status' => 'in_progress'])
        ->assertOk();
});

it('forbids status updates from an actor with no department assigned', function (): void {
    $dept = scopeTestDepartment('SCI');
    $creator = userWithViewAllDepartments();

    $ticket = $this->actingAs($creator)->postJson('/api/v1/service-requests', [
        'patientId' => scopeTestPatient('I')->id,
        'serviceType' => 'laboratory',
        'departmentId' => $dept->id,
    ])->assertCreated()->json('data');

    $unassignedUser = userScopedToDepartment(null);

    $this->actingAs($unassignedUser)
        ->patchJson('/api/v1/service-requests/'.$ticket['id'].'/status', ['status' => 'in_progress'])
        ->assertStatus(403)
        ->assertJsonPath('code', 'DEPARTMENT_SCOPE_FORBIDDEN');
});
