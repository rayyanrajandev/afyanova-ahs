<?php

use App\Models\User;
use App\Http\Middleware\EnforceTenantIsolationWhenEnabled;
use App\Modules\Staff\Infrastructure\Models\StaffProfileAuditLogModel;
use App\Modules\Staff\Infrastructure\Models\StaffProfileModel;
use App\Modules\Department\Infrastructure\Models\DepartmentModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

$testDepartmentId = null;
function getTestDepartment(): string
{
    global $testDepartmentId;
    // Reset if database was refreshed
    if ($testDepartmentId !== null && !DB::table('departments')->where('id', $testDepartmentId)->exists()) {
        $testDepartmentId = null;
    }
    if ($testDepartmentId === null) {
        $facilityId = getTestFacility();
        $department = DepartmentModel::create([
            'facility_id' => $facilityId,
            'code' => 'TEST',
            'name' => 'Test Department',
            'service_type' => 'general',
            'is_patient_facing' => true,
            'is_appointmentable' => false,
            'status' => 'active',
        ]);
        $testDepartmentId = $department->id;
    }
    return $testDepartmentId;
}

$testFacilityId = null;
function getTestFacility(): string
{
    global $testFacilityId;
    // Reset if database was refreshed
    if ($testFacilityId !== null && !DB::table('facilities')->where('id', $testFacilityId)->exists()) {
        $testFacilityId = null;
    }
    if ($testFacilityId === null) {
        $tenantId = getTestTenant();
        $facilityId = (string) Str::uuid();
        DB::table('facilities')->insert([
            'id' => $facilityId,
            'tenant_id' => $tenantId,
            'code' => 'TEST-FAC',
            'name' => 'Test Facility',
            'facility_type' => 'hospital',
            'timezone' => 'Africa/Dar_es_Salaam',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $testFacilityId = $facilityId;
    }
    return $testFacilityId;
}

$testTenantId = null;
function getTestTenant(): string
{
    global $testTenantId;
    // Reset if database was refreshed
    if ($testTenantId !== null && !DB::table('tenants')->where('id', $testTenantId)->exists()) {
        $testTenantId = null;
    }
    if ($testTenantId === null) {
        $testTenantId = (string) Str::uuid();
        DB::table('tenants')->insert([
            'id' => $testTenantId,
            'code' => 'TEST-TEN',
            'name' => 'Test Tenant',
            'country_code' => 'TZ',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
    return $testTenantId;
}

function staffProfilePayload(int $userId, array $overrides = []): array
{
    return array_merge([
        'userId' => $userId,
        'departmentId' => getTestDepartment(),
        'jobTitle' => 'Clinical Officer',
        'professionalLicenseNumber' => 'CO-TZ-12345',
        'licenseType' => 'Clinical Officer',
        'phoneExtension' => '204',
        'employmentType' => 'full_time',
    ], $overrides);
}

function grantStaffReadPermission(User $user): void
{
    $user->givePermissionTo('staff.read');
}

function grantClinicalDirectoryReadPermission(User $user): void
{
    $user->givePermissionTo('staff.clinical-directory.read');
}

function grantStaffMutationPermissions(User $user): void
{
    foreach (['staff.create', 'staff.update', 'staff.update-status'] as $permission) {
        $user->givePermissionTo($permission);
    }
}

function makeStaffReadUser(): User
{
    $user = User::factory()->create();
    grantStaffReadPermission($user);
    grantStaffMutationPermissions($user);

    return $user;
}

it('requires authentication for staff profile creation', function (): void {
    $user = User::factory()->create();

    $this->postJson('/api/v1/staff', staffProfilePayload($user->id))
        ->assertUnauthorized();
});

it('can create staff profile for existing user', function (): void {
    $actor = makeStaffReadUser();
    $targetUser = User::factory()->create();

    $this->actingAs($actor)
        ->postJson('/api/v1/staff', staffProfilePayload($targetUser->id))
        ->assertCreated()
        ->assertJsonPath('data.userId', $targetUser->id)
        ->assertJsonPath('data.userName', $targetUser->name)
        ->assertJsonPath('data.status', 'active');
});

it('forbids staff profile creation without create permission', function (): void {
    $actor = User::factory()->create();
    $targetUser = User::factory()->create();

    $this->actingAs($actor)
        ->postJson('/api/v1/staff', staffProfilePayload($targetUser->id))
        ->assertForbidden();
});

it('rejects staff profile for missing user', function (): void {
    $actor = makeStaffReadUser();

    $this->actingAs($actor)
        ->postJson('/api/v1/staff', staffProfilePayload(999999))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['userId']);
});

it('rejects staff profile for inactive user even when the id exists', function (): void {
    $actor = makeStaffReadUser();
    $inactiveUser = User::factory()->create([
        'status' => 'inactive',
    ]);

    $this->actingAs($actor)
        ->postJson('/api/v1/staff', staffProfilePayload($inactiveUser->id))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['userId']);
});

it('rejects duplicate staff profile for same user', function (): void {
    $actor = makeStaffReadUser();
    $targetUser = User::factory()->create();

    $this->actingAs($actor)
        ->postJson('/api/v1/staff', staffProfilePayload($targetUser->id))
        ->assertCreated();

    $this->actingAs($actor)
        ->postJson('/api/v1/staff', staffProfilePayload($targetUser->id, [
            'jobTitle' => 'Nurse',
        ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['userId']);
});

it('fetches staff profile by id', function (): void {
    $actor = makeStaffReadUser();
    $targetUser = User::factory()->create();

    $created = $this->actingAs($actor)
        ->postJson('/api/v1/staff', staffProfilePayload($targetUser->id))
        ->json('data');

    $this->actingAs($actor)
        ->getJson('/api/v1/staff/'.$created['id'])
        ->assertOk()
        ->assertJsonPath('data.id', $created['id'])
        ->assertJsonPath('data.userName', $targetUser->name);
});

it('forbids staff profile show without read permission', function (): void {
    $writer = User::factory()->create();
    grantStaffMutationPermissions($writer);
    $targetUser = User::factory()->create();

    $created = $this->actingAs($writer)
        ->postJson('/api/v1/staff', staffProfilePayload($targetUser->id))
        ->json('data');

    $userWithoutRead = User::factory()->create();

    $this->actingAs($userWithoutRead)
        ->getJson('/api/v1/staff/'.$created['id'])
        ->assertForbidden();
});

it('returns 404 for unknown staff profile id', function (): void {
    $actor = makeStaffReadUser();

    $this->actingAs($actor)
        ->getJson('/api/v1/staff/060afc03-2ce9-4b1d-a1c2-326d2722ce25')
        ->assertNotFound();
});

it('updates staff profile fields', function (): void {
    $actor = makeStaffReadUser();
    $targetUser = User::factory()->create();

    $created = $this->actingAs($actor)
        ->postJson('/api/v1/staff', staffProfilePayload($targetUser->id))
        ->json('data');

    $this->actingAs($actor)
        ->patchJson('/api/v1/staff/'.$created['id'], [
            'department' => 'Maternity',
            'jobTitle' => 'Senior Midwife',
        ])
        ->assertOk()
        ->assertJsonPath('data.department', 'Maternity')
        ->assertJsonPath('data.jobTitle', 'Senior Midwife');
});

it('does not allow relinking a staff profile to a different user through edit', function (): void {
    $actor = makeStaffReadUser();
    $targetUser = User::factory()->create();
    $otherUser = User::factory()->create();

    $created = $this->actingAs($actor)
        ->postJson('/api/v1/staff', staffProfilePayload($targetUser->id))
        ->json('data');

    $this->actingAs($actor)
        ->patchJson('/api/v1/staff/'.$created['id'], [
            'userId' => $otherUser->id,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['userId']);
});

it('searches eligible linked users for staff profile creation', function (): void {
    $actor = makeStaffReadUser();
    $eligible = User::factory()->create([
        'name' => 'Angel Rukiza',
        'email' => 'angel.rukiza@example.test',
        'status' => 'active',
    ]);
    $linked = User::factory()->create([
        'name' => 'Angel Linked',
        'email' => 'angel.linked@example.test',
        'status' => 'active',
    ]);
    $inactive = User::factory()->create([
        'name' => 'Angel Inactive',
        'email' => 'angel.inactive@example.test',
        'status' => 'inactive',
    ]);

    $this->actingAs($actor)
        ->postJson('/api/v1/staff', staffProfilePayload($linked->id))
        ->assertCreated();

    $response = $this->actingAs($actor)
        ->getJson('/api/v1/staff/linkable-users?q=angel')
        ->assertOk()
        ->assertJsonCount(1, 'data');

    expect($response->json('data.0.id'))->toBe($eligible->id)
        ->and($response->json('data.0.displayName'))->toBe('Angel Rukiza');
});

it('searches eligible linked users case-insensitively for staff profile creation', function (): void {
    $actor = makeStaffReadUser();
    $eligible = User::factory()->create([
        'name' => 'Angel Rukiza',
        'email' => 'angel.rukiza@example.test',
        'status' => 'active',
    ]);

    $this->actingAs($actor)
        ->getJson('/api/v1/staff/linkable-users?q=ANGEL')
        ->assertOk()
        ->assertJsonFragment([
            'id' => $eligible->id,
            'displayName' => 'Angel Rukiza',
        ]);
});

it('lists eligible linked users without a query for default picker options', function (): void {
    $actor = makeStaffReadUser();
    $eligible = User::factory()->create([
        'name' => 'Angel Rukiza',
        'email' => 'angel.rukiza@example.test',
        'status' => 'active',
    ]);

    $this->actingAs($actor)
        ->getJson('/api/v1/staff/linkable-users')
        ->assertOk()
        ->assertJsonFragment([
            'id' => $eligible->id,
            'displayName' => 'Angel Rukiza',
        ]);
});

it('hydrates one eligible linked user for staff profile creation', function (): void {
    $actor = makeStaffReadUser();
    $eligible = User::factory()->create([
        'name' => 'Angel Rukiza',
        'email' => 'angel.rukiza@example.test',
        'status' => 'active',
    ]);

    $this->actingAs($actor)
        ->getJson('/api/v1/staff/linkable-users/'.$eligible->id)
        ->assertOk()
        ->assertJsonPath('data.id', $eligible->id)
        ->assertJsonPath('data.displayName', 'Angel Rukiza');
});

it('forbids eligible linked user search without create permission', function (): void {
    $actor = User::factory()->create();
    grantStaffReadPermission($actor);

    $this->actingAs($actor)
        ->getJson('/api/v1/staff/linkable-users?q=angel')
        ->assertForbidden();
});

it('forbids staff profile update without update permission', function (): void {
    $writer = makeStaffReadUser();
    $targetUser = User::factory()->create();

    $created = $this->actingAs($writer)
        ->postJson('/api/v1/staff', staffProfilePayload($targetUser->id))
        ->json('data');

    $readerOnly = User::factory()->create();
    grantStaffReadPermission($readerOnly);

    $this->actingAs($readerOnly)
        ->patchJson('/api/v1/staff/'.$created['id'], [
            'department' => 'Maternity',
        ])
        ->assertForbidden();
});

it('rejects empty staff profile patch payload', function (): void {
    $actor = makeStaffReadUser();
    $targetUser = User::factory()->create();

    $created = $this->actingAs($actor)
        ->postJson('/api/v1/staff', staffProfilePayload($targetUser->id))
        ->json('data');

    $this->actingAs($actor)
        ->patchJson('/api/v1/staff/'.$created['id'], [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['payload']);
});

it('rejects status lifecycle fields on staff profile detail update endpoint', function (): void {
    $actor = makeStaffReadUser();
    $targetUser = User::factory()->create();

    $created = $this->actingAs($actor)
        ->postJson('/api/v1/staff', staffProfilePayload($targetUser->id))
        ->json('data');

    $this->actingAs($actor)
        ->patchJson('/api/v1/staff/'.$created['id'], [
            'status' => 'suspended',
            'reason' => 'Lifecycle update attempt',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['status', 'reason']);
});

it('updates staff profile status', function (): void {
    $actor = makeStaffReadUser();
    $targetUser = User::factory()->create();

    $created = $this->actingAs($actor)
        ->postJson('/api/v1/staff', staffProfilePayload($targetUser->id))
        ->json('data');

    $this->actingAs($actor)
        ->patchJson('/api/v1/staff/'.$created['id'].'/status', [
            'status' => 'suspended',
            'reason' => 'License review pending',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'suspended')
        ->assertJsonPath('data.statusReason', 'License review pending');
});

it('forbids staff profile status update without update-status permission', function (): void {
    $writer = makeStaffReadUser();
    $targetUser = User::factory()->create();

    $created = $this->actingAs($writer)
        ->postJson('/api/v1/staff', staffProfilePayload($targetUser->id))
        ->json('data');

    $readerOnly = User::factory()->create();
    grantStaffReadPermission($readerOnly);

    $this->actingAs($readerOnly)
        ->patchJson('/api/v1/staff/'.$created['id'].'/status', [
            'status' => 'suspended',
            'reason' => 'No mutation access',
        ])
        ->assertForbidden();
});

it('enforces reason for suspended status and writes transition metadata', function (): void {
    $actor = makeStaffReadUser();
    $targetUser = User::factory()->create();

    $created = $this->actingAs($actor)
        ->postJson('/api/v1/staff', staffProfilePayload($targetUser->id))
        ->json('data');

    $this->actingAs($actor)
        ->patchJson('/api/v1/staff/'.$created['id'].'/status', [
            'status' => 'suspended',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['reason']);

    $this->actingAs($actor)
        ->patchJson('/api/v1/staff/'.$created['id'].'/status', [
            'status' => 'suspended',
            'reason' => 'Credential review in progress',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'suspended')
        ->assertJsonPath('data.statusReason', 'Credential review in progress');

    $statusLog = StaffProfileAuditLogModel::query()
        ->where('staff_profile_id', $created['id'])
        ->where('action', 'staff-profile.status.updated')
        ->latest('created_at')
        ->first();

    expect($statusLog)->not->toBeNull();
    expect($statusLog?->metadata['transition']['from'] ?? null)->toBe('active');
    expect($statusLog?->metadata['transition']['to'] ?? null)->toBe('suspended');
    expect($statusLog?->metadata['reason_required'] ?? null)->toBeTrue();
    expect($statusLog?->metadata['reason_provided'] ?? null)->toBeTrue();
});

it('enforces reason on inactive or suspended status', function (): void {
    $actor = makeStaffReadUser();
    $targetUser = User::factory()->create();

    $created = $this->actingAs($actor)
        ->postJson('/api/v1/staff', staffProfilePayload($targetUser->id))
        ->json('data');

    $this->actingAs($actor)
        ->patchJson('/api/v1/staff/'.$created['id'].'/status', [
            'status' => 'inactive',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['reason']);
});

it('writes staff profile audit logs for create update and status change', function (): void {
    $actor = makeStaffReadUser();
    $targetUser = User::factory()->create();

    $created = $this->actingAs($actor)
        ->postJson('/api/v1/staff', staffProfilePayload($targetUser->id))
        ->json('data');

    $this->actingAs($actor)->patchJson('/api/v1/staff/'.$created['id'], [
        'department' => 'Theatre',
    ])->assertOk();

    $this->actingAs($actor)->patchJson('/api/v1/staff/'.$created['id'].'/status', [
        'status' => 'inactive',
        'reason' => 'Transferred out',
    ])->assertOk();

    $logs = StaffProfileAuditLogModel::query()
        ->where('staff_profile_id', $created['id'])
        ->orderBy('created_at')
        ->get();

    expect($logs)->toHaveCount(3);
    expect($logs->pluck('action')->all())->toContain(
        'staff-profile.created',
        'staff-profile.updated',
        'staff-profile.status.updated',
    );
    expect($logs->first()->actor_id)->toBe($actor->id);
});

it('lists staff profile audit logs when authorized', function (): void {
    $actor = makeStaffReadUser();
    $actor->givePermissionTo('staff.view-audit-logs');
    $targetUser = User::factory()->create();

    $created = $this->actingAs($actor)
        ->postJson('/api/v1/staff', staffProfilePayload($targetUser->id))
        ->json('data');

    $this->actingAs($actor)->patchJson('/api/v1/staff/'.$created['id'], [
        'department' => 'ICU',
    ])->assertOk();

    $this->actingAs($actor)->patchJson('/api/v1/staff/'.$created['id'].'/status', [
        'status' => 'suspended',
        'reason' => 'credential renewal',
    ])->assertOk();

    $this->actingAs($actor)
        ->getJson('/api/v1/staff/'.$created['id'].'/audit-logs?perPage=2')
        ->assertOk()
        ->assertJsonPath('meta.total', 3)
        ->assertJsonPath('meta.perPage', 2)
        ->assertJsonPath('data.0.action', 'staff-profile.status.updated')
        ->assertJsonPath('data.1.action', 'staff-profile.updated');
});

it('forbids staff audit log access without permission', function (): void {
    $actor = makeStaffReadUser();
    $targetUser = User::factory()->create();

    $created = $this->actingAs($actor)
        ->postJson('/api/v1/staff', staffProfilePayload($targetUser->id))
        ->json('data');

    $this->actingAs($actor)
        ->getJson('/api/v1/staff/'.$created['id'].'/audit-logs')
        ->assertForbidden();
});

it('forbids staff audit logs when gate override denies', function (): void {
    Gate::define('staff.view-audit-logs', static fn (): bool => false);

    $actor = makeStaffReadUser();
    $actor->givePermissionTo('staff.view-audit-logs');
    $targetUser = User::factory()->create();

    $created = $this->actingAs($actor)
        ->postJson('/api/v1/staff', staffProfilePayload($targetUser->id))
        ->json('data');

    $this->actingAs($actor)
        ->getJson('/api/v1/staff/'.$created['id'].'/audit-logs')
        ->assertForbidden();
});

it('returns 404 for staff audit logs of unknown id', function (): void {
    $actor = makeStaffReadUser();
    $actor->givePermissionTo('staff.view-audit-logs');

    $this->actingAs($actor)
        ->getJson('/api/v1/staff/060afc03-2ce9-4b1d-a1c2-326d2722ce25/audit-logs')
        ->assertNotFound();
});

it('lists and filters staff profiles', function (): void {
    $actor = makeStaffReadUser();
    $userA = User::factory()->create();
    $userB = User::factory()->create();

    StaffProfileModel::query()->create([
        'user_id' => $userA->id,
        'employee_number' => 'STF20260225AAAAAA',
        'department' => 'Outpatient',
        'job_title' => 'Clinical Officer',
        'professional_license_number' => 'CO-001',
        'license_type' => 'CO',
        'phone_extension' => '201',
        'employment_type' => 'full_time',
        'status' => 'active',
        'status_reason' => null,
    ]);

    StaffProfileModel::query()->create([
        'user_id' => $userB->id,
        'employee_number' => 'STF20260225BBBBBB',
        'department' => 'Laboratory',
        'job_title' => 'Lab Technician',
        'professional_license_number' => 'LAB-002',
        'license_type' => 'Lab',
        'phone_extension' => '301',
        'employment_type' => 'contract',
        'status' => 'inactive',
        'status_reason' => 'leave',
    ]);

    $this->actingAs($actor)
        ->getJson('/api/v1/staff?department=Outpatient&status=active')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.userName', $userA->name)
        ->assertJsonPath('data.0.department', 'Outpatient')
        ->assertJsonPath('data.0.status', 'active');
});

it('lists actual staff department options from staff records and filters departments case-insensitively', function (): void {
    $actor = makeStaffReadUser();
    $userA = User::factory()->create();
    $userB = User::factory()->create();

    StaffProfileModel::query()->create([
        'user_id' => $userA->id,
        'employee_number' => 'STF20260225DEPT01',
        'department' => '  maternity ward  ',
        'job_title' => 'Midwife',
        'professional_license_number' => 'MID-001',
        'license_type' => 'Midwife',
        'phone_extension' => '220',
        'employment_type' => 'full_time',
        'status' => 'active',
        'status_reason' => null,
    ]);

    StaffProfileModel::query()->create([
        'user_id' => $userB->id,
        'employee_number' => 'STF20260225DEPT02',
        'department' => 'Laboratory',
        'job_title' => 'Lab Technician',
        'professional_license_number' => 'LAB-020',
        'license_type' => 'Lab',
        'phone_extension' => '320',
        'employment_type' => 'contract',
        'status' => 'active',
        'status_reason' => null,
    ]);

    $this->actingAs($actor)
        ->getJson('/api/v1/staff/department-options')
        ->assertOk()
        ->assertJsonFragment(['value' => 'maternity ward', 'label' => 'maternity ward'])
        ->assertJsonFragment(['value' => 'Laboratory', 'label' => 'Laboratory']);

    $this->actingAs($actor)
        ->getJson('/api/v1/staff?department=Maternity Ward')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.userName', $userA->name);
});

it('searches staff profiles case-insensitively across visible queue fields', function (): void {
    $actor = makeStaffReadUser();
    $userA = User::factory()->create(['name' => 'Amina Mwakalinga']);
    $userB = User::factory()->create(['name' => 'Hamza Suleiman']);

    StaffProfileModel::query()->create([
        'user_id' => $userA->id,
        'employee_number' => 'STF-TZ-AMINA-001',
        'department' => 'Outpatient',
        'job_title' => 'Clinical Officer',
        'professional_license_number' => 'CO-SEARCH-001',
        'license_type' => 'CO',
        'phone_extension' => '211',
        'employment_type' => 'full_time',
        'status' => 'active',
        'status_reason' => null,
    ]);

    StaffProfileModel::query()->create([
        'user_id' => $userB->id,
        'employee_number' => 'STF-TZ-HAMZA-002',
        'department' => 'Laboratory',
        'job_title' => 'Lab Technician',
        'professional_license_number' => 'LAB-SEARCH-002',
        'license_type' => 'Lab',
        'phone_extension' => '312',
        'employment_type' => 'contract',
        'status' => 'active',
        'status_reason' => null,
    ]);

    $this->actingAs($actor)
        ->getJson('/api/v1/staff?q=mwakalinga')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.userName', 'Amina Mwakalinga')
        ->assertJsonPath('data.0.employeeNumber', 'STF-TZ-AMINA-001');

    $this->actingAs($actor)
        ->getJson('/api/v1/staff?q=lab technician')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.userName', 'Hamza Suleiman')
        ->assertJsonPath('data.0.department', 'Laboratory');

    $this->actingAs($actor)
        ->getJson('/api/v1/staff/status-counts?q=clinical officer')
        ->assertOk()
        ->assertJsonPath('data.total', 1)
        ->assertJsonPath('data.active', 1);
});

it('applies clinical-only filtering before pagination for staff queue requests', function (): void {
    $actor = makeStaffReadUser();

    foreach (range(1, 13) as $index) {
        $user = User::factory()->create(['name' => sprintf('Clinical User %02d', $index)]);

        StaffProfileModel::query()->create([
            'user_id' => $user->id,
            'employee_number' => sprintf('STF-CL-%03d', $index),
            'department' => $index % 2 === 0 ? 'Outpatient' : 'Maternity',
            'job_title' => $index % 2 === 0 ? 'Clinical Officer' : 'Staff Nurse',
            'professional_license_number' => sprintf('CL-%03d', $index),
            'license_type' => 'Clinical',
            'phone_extension' => sprintf('2%02d', $index),
            'employment_type' => 'full_time',
            'status' => 'active',
            'status_reason' => null,
        ]);
    }

    foreach (range(1, 2) as $index) {
        $user = User::factory()->create(['name' => sprintf('Support User %02d', $index)]);

        StaffProfileModel::query()->create([
            'user_id' => $user->id,
            'employee_number' => sprintf('STF-SUP-%03d', $index),
            'department' => 'Front Desk',
            'job_title' => 'Registration Officer',
            'professional_license_number' => sprintf('SUP-%03d', $index),
            'license_type' => 'Support',
            'phone_extension' => sprintf('5%02d', $index),
            'employment_type' => 'full_time',
            'status' => 'active',
            'status_reason' => null,
        ]);
    }

    $this->actingAs($actor)
        ->getJson('/api/v1/staff?status=active&clinicalOnly=1&perPage=12&page=1')
        ->assertOk()
        ->assertJsonPath('meta.total', 13)
        ->assertJsonPath('meta.perPage', 12)
        ->assertJsonPath('meta.currentPage', 1)
        ->assertJsonPath('meta.lastPage', 2)
        ->assertJsonCount(12, 'data');

    $this->actingAs($actor)
        ->getJson('/api/v1/staff?status=active&clinicalOnly=1&perPage=12&page=2')
        ->assertOk()
        ->assertJsonPath('meta.total', 13)
        ->assertJsonPath('meta.currentPage', 2)
        ->assertJsonCount(1, 'data');
});

it('forbids clinician-directory access without clinician-directory permission', function (): void {
    $actor = User::factory()->create();
    grantStaffReadPermission($actor);

    $this->actingAs($actor)
        ->getJson('/api/v1/staff/clinical-directory')
        ->assertForbidden();
});

it('lists only active clinical staff for the clinician directory', function (): void {
    $actor = User::factory()->create();
    grantClinicalDirectoryReadPermission($actor);

    $visibleUser = User::factory()->create(['name' => 'Emmily Rwamuhuru']);
    $supportUser = User::factory()->create(['name' => 'Firdaus Abdallah']);
    $inactiveClinicalUser = User::factory()->create(['name' => 'Asha Mollel']);

    $visible = StaffProfileModel::query()->create([
        'user_id' => $visibleUser->id,
        'employee_number' => 'STF-CLDIR-001',
        'department' => 'General OPD',
        'job_title' => 'Clinical Officer',
        'professional_license_number' => 'CO-CLDIR-001',
        'license_type' => 'Clinical Officer',
        'phone_extension' => '211',
        'employment_type' => 'full_time',
        'status' => 'active',
        'status_reason' => null,
    ]);

    StaffProfileModel::query()->create([
        'user_id' => $supportUser->id,
        'employee_number' => 'STF-CLDIR-002',
        'department' => 'Front Desk',
        'job_title' => 'Registration Officer',
        'professional_license_number' => 'REG-CLDIR-002',
        'license_type' => 'Support',
        'phone_extension' => '512',
        'employment_type' => 'full_time',
        'status' => 'active',
        'status_reason' => null,
    ]);

    StaffProfileModel::query()->create([
        'user_id' => $inactiveClinicalUser->id,
        'employee_number' => 'STF-CLDIR-003',
        'department' => 'Maternity',
        'job_title' => 'Staff Nurse',
        'professional_license_number' => 'NUR-CLDIR-003',
        'license_type' => 'Nursing',
        'phone_extension' => '318',
        'employment_type' => 'full_time',
        'status' => 'inactive',
        'status_reason' => 'leave',
    ]);

    $this->actingAs($actor)
        ->getJson('/api/v1/staff/clinical-directory?q=emmily')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.id', $visible->id)
        ->assertJsonPath('data.0.userName', 'Emmily Rwamuhuru')
        ->assertJsonPath('data.0.department', 'General OPD')
        ->assertJsonPath('data.0.jobTitle', 'Clinical Officer');
});

it('narrows the clinical directory to physicians only when physicianOnly is requested', function (): void {
    // Patient flow redesign: AppointmentCreateSheet.vue's clinician picker
    // needs a narrower slice of the clinical directory than
    // clinicalOnly=true alone provides — clinicalOnly deliberately includes
    // lab/pharmacy/radiology/theatre staff for its original triage/
    // referral-routing purpose, but an appointment's clinician should only
    // be an actual provider who holds consultations.
    $actor = User::factory()->create();
    grantClinicalDirectoryReadPermission($actor);

    $physician = StaffProfileModel::query()->create([
        'user_id' => User::factory()->create(['name' => 'Dr. Furaha Ngonyani'])->id,
        'employee_number' => 'STF-PHYS-001',
        'department' => 'General OPD',
        'job_title' => 'Medical Officer',
        'professional_license_number' => 'MO-PHYS-001',
        'license_type' => 'Medical Officer',
        'phone_extension' => '221',
        'employment_type' => 'full_time',
        'status' => 'active',
        'status_reason' => null,
    ]);

    StaffProfileModel::query()->create([
        'user_id' => User::factory()->create(['name' => 'Hamza Suleiman'])->id,
        'employee_number' => 'STF-PHYS-002',
        'department' => 'Laboratory',
        'job_title' => 'Lab Technician',
        'professional_license_number' => 'LAB-PHYS-002',
        'license_type' => 'Laboratory',
        'phone_extension' => '222',
        'employment_type' => 'full_time',
        'status' => 'active',
        'status_reason' => null,
    ]);

    StaffProfileModel::query()->create([
        'user_id' => User::factory()->create(['name' => 'Zainab Kombe'])->id,
        'employee_number' => 'STF-PHYS-003',
        'department' => 'Main Pharmacy',
        'job_title' => 'Pharmacist',
        'professional_license_number' => 'PHM-PHYS-003',
        'license_type' => 'Pharmacy',
        'phone_extension' => '223',
        'employment_type' => 'full_time',
        'status' => 'active',
        'status_reason' => null,
    ]);

    // Without physicianOnly, clinicalOnly alone still includes lab/pharmacy.
    $this->actingAs($actor)
        ->getJson('/api/v1/staff/clinical-directory')
        ->assertOk()
        ->assertJsonPath('meta.total', 3);

    $response = $this->actingAs($actor)
        ->getJson('/api/v1/staff/clinical-directory?physicianOnly=1')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.id', $physician->id);

    expect(collect($response->json('data'))->pluck('jobTitle')->all())->toBe(['Medical Officer']);
});

it('returns the correct queue page for a carried staff profile under current filters', function (): void {
    $actor = makeStaffReadUser();

    foreach (range(1, 13) as $index) {
        $user = User::factory()->create(['name' => sprintf('Clinical Queue %02d', $index)]);

        StaffProfileModel::query()->create([
            'user_id' => $user->id,
            'employee_number' => sprintf('STF-QUEUE-%03d', $index),
            'department' => $index % 2 === 0 ? 'Outpatient' : 'Maternity',
            'job_title' => $index % 2 === 0 ? 'Clinical Officer' : 'Staff Nurse',
            'professional_license_number' => sprintf('QUEUE-%03d', $index),
            'license_type' => 'Clinical',
            'phone_extension' => sprintf('7%02d', $index),
            'employment_type' => 'full_time',
            'status' => 'active',
            'status_reason' => null,
        ]);
    }

    foreach (range(1, 2) as $index) {
        $user = User::factory()->create(['name' => sprintf('Support Queue %02d', $index)]);

        StaffProfileModel::query()->create([
            'user_id' => $user->id,
            'employee_number' => sprintf('STF-QUEUE-SUP-%03d', $index),
            'department' => 'Front Desk',
            'job_title' => 'Registration Officer',
            'professional_license_number' => sprintf('QUEUE-SUP-%03d', $index),
            'license_type' => 'Support',
            'phone_extension' => sprintf('8%02d', $index),
            'employment_type' => 'full_time',
            'status' => 'active',
            'status_reason' => null,
        ]);
    }

    $target = StaffProfileModel::query()
        ->where('employee_number', 'STF-QUEUE-013')
        ->firstOrFail();

    $this->actingAs($actor)
        ->getJson(sprintf(
            '/api/v1/staff/%s/queue-position?status=active&clinicalOnly=1&perPage=12&sortBy=employeeNumber&sortDir=asc',
            $target->id,
        ))
        ->assertOk()
        ->assertJsonPath('data.page', 2)
        ->assertJsonPath('data.position', 13);
});

it('forbids staff profile list without read permission', function (): void {
    $userWithoutRead = User::factory()->create();
    $targetUser = User::factory()->create();

    StaffProfileModel::query()->create([
        'user_id' => $targetUser->id,
        'employee_number' => 'STF20260225READ00',
        'department' => 'Outpatient',
        'job_title' => 'Clinical Officer',
        'professional_license_number' => 'CO-READ-001',
        'license_type' => 'CO',
        'phone_extension' => '205',
        'employment_type' => 'full_time',
        'status' => 'active',
        'status_reason' => null,
    ]);

    $this->actingAs($userWithoutRead)
        ->getJson('/api/v1/staff')
        ->assertForbidden();
});

it('stamps staff profile tenant scope when created under resolved platform scope', function (): void {
    $actor = makeStaffReadUser();
    $targetUser = User::factory()->create();

    [$tenantId] = seedStaffPlatformScopeAssignment(
        userId: $actor->id,
        tenantCode: 'TZH',
        tenantName: 'Tanzania Health Network',
        countryCode: 'TZ',
        facilityCode: 'DAR-STF',
        facilityName: 'Dar HR Office',
    );

    $created = $this->actingAs($actor)
        ->withHeaders([
            'X-Tenant-Code' => 'TZH',
            'X-Facility-Code' => 'DAR-STF',
        ])
        ->postJson('/api/v1/staff', staffProfilePayload($targetUser->id))
        ->assertCreated()
        ->json('data');

    $row = StaffProfileModel::query()->findOrFail($created['id']);

    expect($row->tenant_id)->toBe($tenantId);
});

it('filters staff profile reads by tenant scope when platform multi tenant isolation is enabled', function (): void {
    config()->set('feature_flags.flags.platform.multi_tenant_isolation.enabled', true);

    $actor = makeStaffReadUser();
    $tenantUser = User::factory()->create();
    $otherTenantUser = User::factory()->create();

    [$tenantId] = seedStaffPlatformScopeAssignment(
        userId: $actor->id,
        tenantCode: 'EAH',
        tenantName: 'East Africa Health Group',
        countryCode: 'KE',
        facilityCode: 'NAI-STF',
        facilityName: 'Nairobi HR Office',
    );

    [$otherTenantId] = seedStaffPlatformScopeFacility(
        tenantCode: 'TZH',
        tenantName: 'Tanzania Health Network',
        countryCode: 'TZ',
        facilityCode: 'DAR-STF',
        facilityName: 'Dar HR Office',
    );

    $visible = StaffProfileModel::query()->create([
        'tenant_id' => $tenantId,
        'user_id' => $tenantUser->id,
        'employee_number' => 'STF20260225SCOPS1',
        'department' => 'Outpatient',
        'job_title' => 'Clinical Officer',
        'professional_license_number' => 'CO-STAFF-001',
        'license_type' => 'CO',
        'phone_extension' => '201',
        'employment_type' => 'full_time',
        'status' => 'active',
        'status_reason' => null,
    ]);

    $hidden = StaffProfileModel::query()->create([
        'tenant_id' => $otherTenantId,
        'user_id' => $otherTenantUser->id,
        'employee_number' => 'STF20260225SCOPS2',
        'department' => 'Laboratory',
        'job_title' => 'Lab Technician',
        'professional_license_number' => 'LAB-STAFF-002',
        'license_type' => 'Lab',
        'phone_extension' => '301',
        'employment_type' => 'contract',
        'status' => 'active',
        'status_reason' => null,
    ]);

    $this->actingAs($actor)
        ->withHeaders([
            'X-Tenant-Code' => 'EAH',
            'X-Facility-Code' => 'NAI-STF',
        ])
        ->getJson('/api/v1/staff')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.id', $visible->id)
        ->assertJsonPath('data.0.department', 'Outpatient');

    $this->actingAs($actor)
        ->withHeaders([
            'X-Tenant-Code' => 'EAH',
            'X-Facility-Code' => 'NAI-STF',
        ])
        ->getJson('/api/v1/staff/'.$hidden->id)
        ->assertNotFound();

    $this->actingAs($actor)
        ->withHeaders([
            'X-Tenant-Code' => 'EAH',
            'X-Facility-Code' => 'NAI-STF',
        ])
        ->patchJson('/api/v1/staff/'.$hidden->id, [
            'department' => 'Attempted cross-tenant update',
        ])
        ->assertNotFound();
});

it('filters staff profile reads by tenant scope when enabled via feature flag override', function (): void {
    config()->set('feature_flags.flags.platform.multi_tenant_isolation.enabled', false);

    $actor = makeStaffReadUser();
    $tenantUser = User::factory()->create();
    $otherTenantUser = User::factory()->create();

    [$tenantId] = seedStaffPlatformScopeAssignment(
        userId: $actor->id,
        tenantCode: 'EAH',
        tenantName: 'East Africa Health Group',
        countryCode: 'KE',
        facilityCode: 'NAI-STF',
        facilityName: 'Nairobi HR Office',
    );

    [$otherTenantId] = seedStaffPlatformScopeFacility(
        tenantCode: 'UGH',
        tenantName: 'Uganda Health Group',
        countryCode: 'UG',
        facilityCode: 'KLA-STF',
        facilityName: 'Kampala HR Office',
    );

    DB::table('feature_flag_overrides')->insert([
        'id' => (string) Str::uuid(),
        'flag_name' => 'platform.multi_tenant_isolation',
        'scope_type' => 'country',
        'scope_key' => 'KE',
        'enabled' => true,
        'reason' => 'enable staff tenant isolation for Kenya rollout',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $visible = StaffProfileModel::query()->create([
        'tenant_id' => $tenantId,
        'user_id' => $tenantUser->id,
        'employee_number' => 'STF20260225SCOPS3',
        'department' => 'Outpatient',
        'job_title' => 'Clinical Officer',
        'professional_license_number' => 'CO-STAFF-003',
        'license_type' => 'CO',
        'phone_extension' => '203',
        'employment_type' => 'full_time',
        'status' => 'active',
        'status_reason' => null,
    ]);

    StaffProfileModel::query()->create([
        'tenant_id' => $otherTenantId,
        'user_id' => $otherTenantUser->id,
        'employee_number' => 'STF20260225SCOPS4',
        'department' => 'Laboratory',
        'job_title' => 'Lab Technician',
        'professional_license_number' => 'LAB-STAFF-004',
        'license_type' => 'Lab',
        'phone_extension' => '304',
        'employment_type' => 'contract',
        'status' => 'active',
        'status_reason' => null,
    ]);

    $this->actingAs($actor)
        ->withHeaders([
            'X-Tenant-Code' => 'EAH',
            'X-Facility-Code' => 'NAI-STF',
        ])
        ->getJson('/api/v1/staff')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.id', $visible->id)
        ->assertJsonPath('data.0.department', 'Outpatient');
});

it('blocks staff profile creation in use case when tenant isolation is enabled and middleware is bypassed', function (): void {
    $this->withoutMiddleware(EnforceTenantIsolationWhenEnabled::class);
    config()->set('feature_flags.flags.platform.multi_tenant_isolation.enabled', true);

    $actor = makeStaffReadUser();
    $targetUser = User::factory()->create();

    $this->actingAs($actor)
        ->postJson('/api/v1/staff', staffProfilePayload($targetUser->id))
        ->assertForbidden()
        ->assertJsonPath('code', 'TENANT_SCOPE_REQUIRED');
});

/**
 * @return array{0:string,1:string}
 */
function seedStaffPlatformScopeAssignment(
    int $userId,
    string $tenantCode,
    string $tenantName,
    string $countryCode,
    string $facilityCode,
    string $facilityName,
): array {
    [$tenantId, $facilityId] = seedStaffPlatformScopeFacility(
        tenantCode: $tenantCode,
        tenantName: $tenantName,
        countryCode: $countryCode,
        facilityCode: $facilityCode,
        facilityName: $facilityName,
    );

    DB::table('facility_user')->insert([
        'facility_id' => $facilityId,
        'user_id' => $userId,
        'role' => 'hr',
        'is_primary' => true,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return [$tenantId, $facilityId];
}

/**
 * @return array{0:string,1:string}
 */
function seedStaffPlatformScopeFacility(
    string $tenantCode,
    string $tenantName,
    string $countryCode,
    string $facilityCode,
    string $facilityName,
): array {
    $tenant = DB::table('tenants')->where('code', $tenantCode)->first();

    if ($tenant === null) {
        $tenantId = (string) Str::uuid();
        DB::table('tenants')->insert([
            'id' => $tenantId,
            'code' => $tenantCode,
            'name' => $tenantName,
            'country_code' => $countryCode,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    } else {
        $tenantId = (string) $tenant->id;
    }

    $facilityId = (string) Str::uuid();
    DB::table('facilities')->insert([
        'id' => $facilityId,
        'tenant_id' => $tenantId,
        'code' => $facilityCode,
        'name' => $facilityName,
        'facility_type' => 'hospital',
        'timezone' => 'Africa/Nairobi',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return [$tenantId, $facilityId];
}

it('blocks staff profile update in use case when tenant isolation is enabled and middleware is bypassed', function (): void {
    $this->withoutMiddleware(EnforceTenantIsolationWhenEnabled::class);
    config()->set('feature_flags.flags.platform.multi_tenant_isolation.enabled', true);

    $actor = makeStaffReadUser();
    $targetUser = User::factory()->create();

    $profile = StaffProfileModel::query()->create([
        'user_id' => $targetUser->id,
        'employee_number' => 'STF20260225GUARDS1',
        'department' => 'Outpatient',
        'job_title' => 'Clinical Officer',
        'professional_license_number' => 'CO-GUARD-001',
        'license_type' => 'CO',
        'phone_extension' => '201',
        'employment_type' => 'full_time',
        'status' => 'active',
        'status_reason' => null,
    ]);

    $this->actingAs($actor)
        ->patchJson('/api/v1/staff/'.$profile->id, [
            'department' => 'Attempted guarded update',
        ])
        ->assertForbidden()
        ->assertJsonPath('code', 'TENANT_SCOPE_REQUIRED');
});

it('blocks staff profile status update in use case when tenant isolation is enabled and middleware is bypassed', function (): void {
    $this->withoutMiddleware(EnforceTenantIsolationWhenEnabled::class);
    config()->set('feature_flags.flags.platform.multi_tenant_isolation.enabled', true);

    $actor = makeStaffReadUser();
    $targetUser = User::factory()->create();

    $profile = StaffProfileModel::query()->create([
        'user_id' => $targetUser->id,
        'employee_number' => 'STF20260225GUARDS2',
        'department' => 'Outpatient',
        'job_title' => 'Clinical Officer',
        'professional_license_number' => 'CO-GUARD-002',
        'license_type' => 'CO',
        'phone_extension' => '202',
        'employment_type' => 'full_time',
        'status' => 'active',
        'status_reason' => null,
    ]);

    $this->actingAs($actor)
        ->patchJson('/api/v1/staff/'.$profile->id.'/status', [
            'status' => 'suspended',
            'reason' => 'Attempted guarded status update',
        ])
        ->assertForbidden()
        ->assertJsonPath('code', 'TENANT_SCOPE_REQUIRED');
});
