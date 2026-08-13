<?php

use App\Http\Middleware\EnsureFacilitySubscriptionEntitlement;
use App\Http\Middleware\EnsureMappedFacilitySubscriptionEntitlement;
use App\Models\User;
use App\Modules\Encounter\Infrastructure\Models\EncounterModel;
use App\Modules\Patient\Infrastructure\Models\PatientModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutMiddleware([
        EnsureMappedFacilitySubscriptionEntitlement::class,
        EnsureFacilitySubscriptionEntitlement::class,
    ]);
    config()->set('feature_flags.flags.platform.multi_tenant_isolation.enabled', false);
});

/**
 * Mirrors the Appointment-level consultation-owner conflict pattern
 * (tests/Feature/Appointment/AppointmentApiTest.php) for Encounter close/reopen,
 * which previously had no ownership check at all — any user holding
 * medical.records.* permissions could close or reopen an encounter primarily
 * assigned to a different clinician.
 */
function ownershipEncounterPatient(): PatientModel
{
    return PatientModel::query()->create([
        'patient_number' => 'PTOWN'.now()->format('Ymd').strtoupper(Str::random(6)),
        'first_name' => 'Owner', 'last_name' => 'Enforcement', 'gender' => 'male',
        'date_of_birth' => '1988-02-14', 'phone' => '+255700000033', 'country_code' => 'TZ',
        'status' => 'active',
    ]);
}

function ownershipEncounterUser(): User
{
    $user = User::factory()->create();
    foreach (['medical.records.read', 'medical.records.create', 'medical.records.finalize', 'medical.records.amend'] as $permission) {
        $user->givePermissionTo($permission);
    }

    return $user;
}

/**
 * note_signed is a blocking close-readiness item (GetEncounterCloseReadinessUseCase) —
 * every close attempt in this file needs one first, regardless of which user is
 * later used to test the ownership check.
 */
function ownershipEncounterSignedNote(User $user, string $patientId, string $encounterId): void
{
    $created = test()->actingAs($user)
        ->postJson('/api/v1/medical-records', [
            'patientId' => $patientId,
            'encounterId' => $encounterId,
            'encounterAt' => now()->toDateTimeString(),
            'recordType' => 'consultation_note',
            'subjective' => 'Fixture note.',
            'assessment' => 'Stable, recovering well.',
        ])
        ->assertCreated()
        ->json('data');

    test()->actingAs($user)
        ->patchJson('/api/v1/medical-records/'.$created['id'].'/status', ['status' => 'finalized'])
        ->assertOk();
}

function ownershipEncounterFacilitySuperAdminUser(): User
{
    $user = ownershipEncounterUser();

    $tenantId = (string) Str::uuid();
    DB::table('tenants')->insert([
        'id' => $tenantId,
        'code' => 'EO'.strtoupper(Str::random(6)),
        'name' => 'Encounter Ownership Test Tenant',
        'country_code' => 'TZ',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $facilityId = (string) Str::uuid();
    DB::table('facilities')->insert([
        'id' => $facilityId,
        'tenant_id' => $tenantId,
        'code' => 'EO'.strtoupper(Str::random(6)),
        'name' => 'Encounter Ownership Test Facility',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('facility_user')->insert([
        'facility_id' => $facilityId,
        'user_id' => $user->id,
        'role' => 'super_admin',
        'is_primary' => true,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $user;
}

function ownershipEncounter(string $patientId, array $overrides = []): EncounterModel
{
    return EncounterModel::query()->create(array_merge([
        'encounter_number' => 'ENCOWN'.strtoupper(Str::random(8)),
        'patient_id' => $patientId,
        'status' => 'opened',
        'type' => 'outpatient',
        'opened_at' => now(),
    ], $overrides));
}

it('forbids closing an encounter without medical.records.finalize/amend permission, even when unclaimed', function (): void {
    // RBAC_Remediation_Plan.md Task 3.4: the ownership check above is layered
    // on top of a real permission floor in UpdateEncounterStatusRequest::
    // authorize() (requires medical.records.read plus finalize/amend
    // depending on the target status) — this proves the floor holds even for
    // an encounter with no owner yet, where the ownership check alone would
    // let anyone through.
    $user = User::factory()->create();
    $user->givePermissionTo('medical.records.read');
    $patient = ownershipEncounterPatient();
    $encounter = ownershipEncounter($patient->id);

    $this->actingAs($user)
        ->patchJson('/api/v1/encounters/'.$encounter->id.'/status', [
            'status' => 'closed',
            'disposition' => 'discharged',
        ])
        ->assertForbidden();
});

it('blocks closing an encounter owned by another clinician', function (): void {
    $owner = ownershipEncounterUser();
    $otherClinician = ownershipEncounterUser();
    $patient = ownershipEncounterPatient();
    $encounter = ownershipEncounter($patient->id, ['primary_clinician_user_id' => $owner->id]);

    $response = $this->actingAs($otherClinician)
        ->patchJson('/api/v1/encounters/'.$encounter->id.'/status', [
            'status' => 'closed',
            'disposition' => 'discharged',
            'reason' => 'Attempting to close someone else\'s encounter.',
        ])
        ->assertStatus(409)
        ->assertJsonPath('code', 'ENCOUNTER_OWNER_REQUIRED');

    expect($response->json('context.encounterOwnerUserId'))->toBe($owner->id);
    expect(EncounterModel::query()->find($encounter->id)->status)->toBe('opened');
});

it('lets the primary clinician close their own encounter', function (): void {
    $owner = ownershipEncounterUser();
    $patient = ownershipEncounterPatient();
    $encounter = ownershipEncounter($patient->id, ['primary_clinician_user_id' => $owner->id]);
    ownershipEncounterSignedNote($owner, $patient->id, $encounter->id);

    $this->actingAs($owner)
        ->patchJson('/api/v1/encounters/'.$encounter->id.'/status', [
            'status' => 'closed',
            'disposition' => 'discharged',
            'reason' => 'Visit concluded normally.',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'closed');
});

it('lets a facility super admin close an encounter owned by another clinician', function (): void {
    $owner = ownershipEncounterUser();
    $superAdmin = ownershipEncounterFacilitySuperAdminUser();
    $patient = ownershipEncounterPatient();
    $encounter = ownershipEncounter($patient->id, ['primary_clinician_user_id' => $owner->id]);
    ownershipEncounterSignedNote($owner, $patient->id, $encounter->id);

    $this->actingAs($superAdmin)
        ->patchJson('/api/v1/encounters/'.$encounter->id.'/status', [
            'status' => 'closed',
            'disposition' => 'discharged',
            'reason' => 'Administrative override close-out.',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'closed');
});

it('lets any permitted user close a not-yet-claimed encounter, assigning ownership on close', function (): void {
    $user = ownershipEncounterUser();
    $patient = ownershipEncounterPatient();
    $encounter = ownershipEncounter($patient->id);
    ownershipEncounterSignedNote($user, $patient->id, $encounter->id);

    expect($encounter->primary_clinician_user_id)->toBeNull();

    $this->actingAs($user)
        ->patchJson('/api/v1/encounters/'.$encounter->id.'/status', [
            'status' => 'closed',
            'disposition' => 'discharged',
            'reason' => 'First-time close by an unclaimed encounter.',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'closed');

    expect(EncounterModel::query()->find($encounter->id)->primary_clinician_user_id)->toBe($user->id);
});

it('blocks reopening an encounter owned by another clinician', function (): void {
    $owner = ownershipEncounterUser();
    $otherClinician = ownershipEncounterUser();
    $patient = ownershipEncounterPatient();
    $encounter = ownershipEncounter($patient->id, [
        'status' => 'closed',
        'closed_at' => now(),
        'primary_clinician_user_id' => $owner->id,
    ]);

    $this->actingAs($otherClinician)
        ->patchJson('/api/v1/encounters/'.$encounter->id.'/status', [
            'status' => 'in_progress',
            'reason' => 'Attempting to reopen someone else\'s encounter.',
        ])
        ->assertStatus(409)
        ->assertJsonPath('code', 'ENCOUNTER_OWNER_REQUIRED');

    expect(EncounterModel::query()->find($encounter->id)->status)->toBe('closed');
});

it('lets the primary clinician reopen their own closed encounter', function (): void {
    $owner = ownershipEncounterUser();
    $patient = ownershipEncounterPatient();
    $encounter = ownershipEncounter($patient->id, [
        'status' => 'closed',
        'closed_at' => now(),
        'primary_clinician_user_id' => $owner->id,
    ]);

    $this->actingAs($owner)
        ->patchJson('/api/v1/encounters/'.$encounter->id.'/status', [
            'status' => 'in_progress',
            'reason' => 'Follow-up documentation required.',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'in_progress');
});
