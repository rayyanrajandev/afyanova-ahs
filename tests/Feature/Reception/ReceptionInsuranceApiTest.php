<?php

use App\Modules\Billing\Infrastructure\Models\PatientInsuranceModel;
use App\Modules\Patient\Infrastructure\Models\PatientModel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Ties the user to a real tenant/facility — GetPatientSummaryUseCase's
 * insurance lookup resolves tenantId from CurrentPlatformScopeContextInterface
 * (the acting user's facility_user -> facility -> tenant chain), not from
 * the patient row directly; without this, findActiveInsurance() always
 * short-circuits to null regardless of what insurance data actually
 * exists. Same shape as ReceptionCallActionTest.php's callActionFacility().
 */
function insuranceScopeFacility(int $userId): string
{
    $tenantId = (string) Str::uuid();
    $facilityId = (string) Str::uuid();

    DB::table('tenants')->insert([
        'id' => $tenantId,
        'code' => 'INS-'.Str::upper(Str::random(4)),
        'name' => 'Insurance Test Tenant',
        'country_code' => 'TZ',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('facilities')->insert([
        'id' => $facilityId,
        'tenant_id' => $tenantId,
        'code' => 'INS-'.Str::upper(Str::random(4)),
        'name' => 'Insurance Test Facility',
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

    return $tenantId;
}

/**
 * Volume 2.1 §8.1/§16 #10 "Insurance add/verify UI" — decided + built
 * 2026-08-11: reception-scoped routes reusing PatientInsuranceController
 * directly (routes/api-workspaces.php), `patients.insurance.manage`/
 * `patients.insurance.verify` granted to ADMIN.REGISTRATION (routes/
 * console.php's defaultHospitalRolePermissionProfiles(), same permission-
 * sync mechanism as every other role-permission fix this session).
 */
function insuranceReceptionUser(): User
{
    $user = User::factory()->create();
    foreach (['patients.read', 'patients.insurance.manage', 'patients.insurance.verify'] as $permission) {
        $user->givePermissionTo($permission);
    }

    return $user;
}

function insurancePatient(): PatientModel
{
    return PatientModel::query()->create([
        'patient_number' => 'PTINS'.now()->format('Ymd').strtoupper(Str::random(6)),
        'first_name' => 'Insurance', 'last_name' => 'Fixture', 'gender' => 'male',
        'date_of_birth' => '1985-02-20', 'phone' => '+255700000201', 'country_code' => 'TZ',
        'status' => 'active',
    ]);
}

it('creates an insurance record for a patient through the reception-scoped route', function (): void {
    $user = insuranceReceptionUser();
    $patient = insurancePatient();

    $response = $this->actingAs($user)
        ->postJson('/api/v1/reception/patients/'.$patient->id.'/insurance', [
            'insuranceProvider' => 'NHIF',
            'memberId' => 'NHIF-00123',
            'planName' => 'Standard',
        ])
        ->assertCreated();

    $response->assertJsonPath('data.insuranceProvider', 'NHIF');
    $response->assertJsonPath('data.memberId', 'NHIF-00123');
    // Defaults to 'unverified', not null — a record always starts life
    // with an explicit not-yet-checked status, not an absent one.
    $response->assertJsonPath('data.verificationStatus', 'unverified');

    expect(PatientInsuranceModel::query()->where('patient_id', $patient->id)->count())->toBe(1);
});

it('rejects insurance creation without patients.insurance.manage', function (): void {
    $user = User::factory()->create();
    $user->givePermissionTo('patients.read');
    $patient = insurancePatient();

    $this->actingAs($user)
        ->postJson('/api/v1/reception/patients/'.$patient->id.'/insurance', [
            'insuranceProvider' => 'NHIF',
            'memberId' => 'NHIF-00123',
        ])
        ->assertForbidden();
});

it('requires memberId or cardNumber', function (): void {
    $user = insuranceReceptionUser();
    $patient = insurancePatient();

    $this->actingAs($user)
        ->postJson('/api/v1/reception/patients/'.$patient->id.'/insurance', [
            'insuranceProvider' => 'NHIF',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['memberId']);
});

it('updates an existing insurance record through the reception-scoped route', function (): void {
    $user = insuranceReceptionUser();
    $patient = insurancePatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/reception/patients/'.$patient->id.'/insurance', [
            'insuranceProvider' => 'NHIF',
            'memberId' => 'NHIF-00123',
        ])
        ->json('data.id');

    $this->actingAs($user)
        ->patchJson('/api/v1/reception/patients/'.$patient->id.'/insurance/'.$created, [
            'planName' => 'Updated Plan',
        ])
        ->assertOk()
        ->assertJsonPath('data.planName', 'Updated Plan')
        ->assertJsonPath('data.memberId', 'NHIF-00123'); // untouched fields survive a partial update
});

it('marks an insurance record verified through the reception-scoped verify route', function (): void {
    $user = insuranceReceptionUser();
    $patient = insurancePatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/reception/patients/'.$patient->id.'/insurance', [
            'insuranceProvider' => 'NHIF',
            'memberId' => 'NHIF-00123',
        ])
        ->json('data.id');

    $this->actingAs($user)
        ->patchJson('/api/v1/reception/patients/'.$patient->id.'/insurance/'.$created.'/verify', [
            'verificationStatus' => 'verified',
        ])
        ->assertOk()
        ->assertJsonPath('data.verificationStatus', 'verified')
        ->assertJsonPath('data.verifiedByUserId', $user->id);
});

it('rejects verify without patients.insurance.verify even if patients.insurance.manage is present', function (): void {
    $user = User::factory()->create();
    foreach (['patients.read', 'patients.insurance.manage'] as $permission) {
        $user->givePermissionTo($permission);
    }
    $patient = insurancePatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/reception/patients/'.$patient->id.'/insurance', [
            'insuranceProvider' => 'NHIF',
            'memberId' => 'NHIF-00123',
        ])
        ->json('data.id');

    $this->actingAs($user)
        ->patchJson('/api/v1/reception/patients/'.$patient->id.'/insurance/'.$created.'/verify', [
            'verificationStatus' => 'verified',
        ])
        ->assertForbidden();
});

it('surfaces the new insurance record through the summary endpoint reception already reads', function (): void {
    $user = insuranceReceptionUser();
    insuranceScopeFacility($user->id);
    $patient = insurancePatient();

    $this->actingAs($user)
        ->postJson('/api/v1/reception/patients/'.$patient->id.'/insurance', [
            'insuranceProvider' => 'NHIF',
            'memberId' => 'NHIF-00123',
        ])
        ->assertCreated();

    $this->actingAs($user)
        ->getJson('/api/v1/reception/patients/'.$patient->id.'/summary')
        ->assertOk()
        ->assertJsonPath('data.insurance.insuranceProvider', 'NHIF')
        ->assertJsonPath('data.insurance.memberId', 'NHIF-00123');
});
