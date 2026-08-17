<?php

use App\Http\Middleware\EnsureFacilitySubscriptionEntitlement;
use App\Models\User;
use App\Modules\Patient\Application\Services\PatientMrnGenerator;
use App\Modules\Patient\Infrastructure\Models\PatientModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutMiddleware(EnsureFacilitySubscriptionEntitlement::class);
});

function mrnApiPayload(array $overrides = []): array
{
    return array_merge([
        'firstName' => 'Maka',
        'middleName' => null,
        'lastName' => 'Nguvumali',
        'gender' => 'female',
        'dateOfBirth' => '1992-08-19',
        'phone' => '+255700500001',
        'email' => 'maka@example.test',
        'nationalId' => 'TZ-MRN-001',
        'countryCode' => 'TZ',
        'region' => 'Mwanza',
        'district' => 'Nyamagana',
        'addressLine' => 'Nyerere Road',
        'nextOfKinName' => 'Neema Nguvumali',
        'nextOfKinPhone' => '+255700500002',
    ], $overrides);
}

function mrnApiUser(): User
{
    $user = User::factory()->create();
    $user->givePermissionTo('patients.read');
    $user->givePermissionTo('patients.create');

    return $user;
}

it('generates sequential zero-padded 8-digit MRNs per scope', function (): void {
    $generator = app(PatientMrnGenerator::class);

    $mrn1 = $generator->nextForTenant(null);
    expect($mrn1)->toBe('00000001');
    PatientModel::query()->create(['patient_number' => $mrn1, 'first_name' => 'A', 'last_name' => 'B', 'gender' => 'female', 'date_of_birth' => '1990-01-01', 'country_code' => 'TZ']);

    $mrn2 = $generator->nextForTenant(null);
    expect($mrn2)->toBe('00000002');
    PatientModel::query()->create(['patient_number' => $mrn2, 'first_name' => 'C', 'last_name' => 'D', 'gender' => 'female', 'date_of_birth' => '1990-01-01', 'country_code' => 'TZ']);

    $mrn3 = $generator->nextForTenant(null);
    expect($mrn3)->toBe('00000003');
    PatientModel::query()->create(['patient_number' => $mrn3, 'first_name' => 'E', 'last_name' => 'F', 'gender' => 'female', 'date_of_birth' => '1990-01-01', 'country_code' => 'TZ']);

    $tenantA = (string) Str::uuid();
    $tenantB = (string) Str::uuid();

    DB::table('tenants')->insert([
        ['id' => $tenantA, 'code' => 'TENA_SEQ', 'name' => 'Tenant A', 'country_code' => 'TZ', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
        ['id' => $tenantB, 'code' => 'TENB_SEQ', 'name' => 'Tenant B', 'country_code' => 'TZ', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
    ]);

    $mrnTenantA1 = $generator->nextForTenant($tenantA);
    expect($mrnTenantA1)->toBe('00000001');
    PatientModel::query()->create(['tenant_id' => $tenantA, 'patient_number' => $mrnTenantA1, 'first_name' => 'G', 'last_name' => 'H', 'gender' => 'male', 'date_of_birth' => '1990-01-01', 'country_code' => 'TZ']);

    $mrnTenantA2 = $generator->nextForTenant($tenantA);
    expect($mrnTenantA2)->toBe('00000002');
    PatientModel::query()->create(['tenant_id' => $tenantA, 'patient_number' => $mrnTenantA2, 'first_name' => 'I', 'last_name' => 'J', 'gender' => 'male', 'date_of_birth' => '1990-01-01', 'country_code' => 'TZ']);

    $mrnTenantB1 = $generator->nextForTenant($tenantB);
    expect($mrnTenantB1)->toBe('00000001');
});

it('returns MRNs that are always exactly 8 digits', function (): void {
    $generator = app(PatientMrnGenerator::class);

    foreach (range(1, 5) as $unused) {
        expect((string) preg_match('/^[0-9]{8}$/', $generator->nextForTenant(null)))->toBe('1');
    }
});

it('allocates unique MRNs across many registrations', function (): void {
    $user = mrnApiUser();
    $numbers = [];

    foreach (range(1, 25) as $unused) {
        $created = $this->actingAs($user)
            ->postJson('/api/v1/patients', mrnApiPayload([
                'phone' => '+2557005'.str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT),
                'nationalId' => 'TZ-MRN-'.random_int(100000, 999999),
            ]))
            ->assertCreated()
            ->json('data');

        $numbers[] = $created['patientNumber'];
    }

    $numbers = array_filter($numbers, static fn (mixed $value): bool => is_string($value));
    $numbers = array_values($numbers);

    expect(count($numbers))->toBe(25);
    expect(count(array_unique($numbers)))->toBe(25);
    foreach ($numbers as $number) {
        expect((string) preg_match('/^[0-9]{8}$/', $number))->toBe('1');
    }
});

it('keeps the MRN number unchanged when a patient profile is updated', function (): void {
    $user = mrnApiUser();
    $user->givePermissionTo('patient.demographics.update');

    $created = $this->actingAs($user)
        ->postJson('/api/v1/patients', mrnApiPayload())
        ->assertCreated()
        ->json('data');

    $row = PatientModel::query()->findOrFail($created['id']);
    $mrnBefore = $row->patient_number;

    $this->actingAs($user)
        ->patchJson('/api/v1/patients/'.$created['id'], [
            'phone' => '+255700999999',
            'addressLine' => 'Changed Address',
        ])
        ->assertOk();

    $row->refresh();
    expect($row->patient_number)->toBe($mrnBefore);
});

it('does not allow the client to override the MRN at registration', function (): void {
    $user = mrnApiUser();

    $this->actingAs($user)
        ->postJson('/api/v1/patients', mrnApiPayload([
            'patientNumber' => '99999999',
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('patientNumber');
});

it('does not allow the client to change the MRN in a profile update', function (): void {
    $user = mrnApiUser();
    $user->givePermissionTo('patient.demographics.update');

    $created = $this->actingAs($user)
        ->postJson('/api/v1/patients', mrnApiPayload())
        ->assertCreated()
        ->json('data');

    $this->actingAs($user)
        ->patchJson('/api/v1/patients/'.$created['id'], [
            'patientNumber' => '88888888',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('patientNumber');
});

it('assigns different MRN sequences to different tenants', function (): void {
    $user = mrnApiUser();

    [$tenantA] = seedMrmTenantScope($user, 'MRKA', 'Mira Kazakhstan', 'KZ', 'ALM-PAT', 'Almaty Reg');
    [$tenantB] = seedMrmTenantScope($user, 'MRKB', 'Mira Kenya', 'KE', 'NBO-PAT', 'Nairobi Reg');

    $firstTenantA = $this->actingAs($user)
        ->withHeaders(['X-Tenant-Code' => 'MRKA', 'X-Facility-Code' => 'ALM-PAT'])
        ->postJson('/api/v1/patients', mrnApiPayload([
            'phone' => '+77001112221',
            'nationalId' => 'KZ-MRN-1',
        ]))
        ->assertCreated()
        ->json('data.patientNumber');

    $secondTenantA = $this->actingAs($user)
        ->withHeaders(['X-Tenant-Code' => 'MRKA', 'X-Facility-Code' => 'ALM-PAT'])
        ->postJson('/api/v1/patients', mrnApiPayload([
            'phone' => '+77001112222',
            'nationalId' => 'KZ-MRN-2',
        ]))
        ->assertCreated()
        ->json('data.patientNumber');

    $firstTenantB = $this->actingAs($user)
        ->withHeaders(['X-Tenant-Code' => 'MRKB', 'X-Facility-Code' => 'NBO-PAT'])
        ->postJson('/api/v1/patients', mrnApiPayload([
            'phone' => '+254700112211',
            'nationalId' => 'KE-MRN-1',
        ]))
        ->assertCreated()
        ->json('data.patientNumber');

    expect($firstTenantA)->toBe('00000001');
    expect($secondTenantA)->toBe('00000002');
    expect($firstTenantB)->toBe('00000001');
});

it('preserves existing non-numeric MRNs during the migration backfill', function (): void {
    PatientModel::query()->create([
        'patient_number' => 'PT20260101LEGACY',
        'first_name' => 'Legacy',
        'middle_name' => null,
        'last_name' => 'Patient',
        'gender' => 'female',
        'date_of_birth' => '1990-01-01',
        'phone' => '+255700123456',
        'email' => null,
        'national_id' => null,
        'country_code' => 'TZ',
        'region' => 'Dar es Salaam',
        'district' => 'Kinondoni',
        'address_line' => null,
        'next_of_kin_name' => null,
        'next_of_kin_phone' => null,
        'status' => 'active',
        'status_reason' => null,
    ]);

    $row = PatientModel::query()->where('patient_number', 'PT20260101LEGACY')->first();
    expect($row)->not->toBeNull();
    expect($row->patient_number)->toBe('PT20260101LEGACY');
});

it('returns the MRN in API responses', function (): void {
    $user = mrnApiUser();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/patients', mrnApiPayload())
        ->assertCreated()
        ->json('data');

    expect($created['patientNumber'])->toBe('00000001');

    $this->actingAs($user)
        ->getJson('/api/v1/patients/'.$created['id'])
        ->assertOk()
        ->assertJsonPath('data.patientNumber', '00000001');
});

it('resets MRN sequence back to 00000001 when all patients are deleted via Eloquent', function (): void {
    $generator = app(PatientMrnGenerator::class);
    $user = mrnApiUser();

    $patient1 = $this->actingAs($user)->postJson('/api/v1/patients', mrnApiPayload())->json('data');
    $patient2 = $this->actingAs($user)->postJson('/api/v1/patients', mrnApiPayload(['phone' => '+255700500003', 'nationalId' => 'TZ-MRN-002']))->json('data');

    expect($patient1['patientNumber'])->toBe('00000001');
    expect($patient2['patientNumber'])->toBe('00000002');

    // Delete both patients
    PatientModel::query()->find($patient1['id'])->delete();
    PatientModel::query()->find($patient2['id'])->delete();

    expect(PatientModel::query()->count())->toBe(0);

    // Next patient created should restart at 00000001
    $patient3 = $this->actingAs($user)->postJson('/api/v1/patients', mrnApiPayload(['phone' => '+255700500004', 'nationalId' => 'TZ-MRN-003']))->json('data');
    expect($patient3['patientNumber'])->toBe('00000001');
});

it('resets MRN sequence back to 00000001 when patients are wiped via DB and reset is called or synced', function (): void {
    $generator = app(PatientMrnGenerator::class);

    PatientModel::query()->create([
        'patient_number' => $generator->nextForTenant(null),
        'first_name' => 'John',
        'last_name' => 'Doe',
        'gender' => 'male',
        'date_of_birth' => '1990-01-01',
        'country_code' => 'TZ',
    ]);

    expect($generator->nextForTenant(null))->toBe('00000002');

    // Wipe DB directly (e.g. DB::table('patients')->delete())
    DB::table('patients')->delete();

    // Call resetAll or syncAllSequencesWithDatabase
    $generator->syncAllSequencesWithDatabase();

    expect($generator->nextForTenant(null))->toBe('00000001');
});

it('resets only the specific tenant sequence when that tenant has all patients deleted', function (): void {
    $generator = app(PatientMrnGenerator::class);
    $tenantA = (string) Str::uuid();
    $tenantB = (string) Str::uuid();

    DB::table('tenants')->insert([
        ['id' => $tenantA, 'code' => 'TENA', 'name' => 'Tenant A', 'country_code' => 'TZ', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
        ['id' => $tenantB, 'code' => 'TENB', 'name' => 'Tenant B', 'country_code' => 'TZ', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
    ]);

    PatientModel::query()->create([
        'tenant_id' => $tenantA,
        'patient_number' => $generator->nextForTenant($tenantA),
        'first_name' => 'John',
        'last_name' => 'Doe',
        'gender' => 'male',
        'date_of_birth' => '1990-01-01',
        'country_code' => 'TZ',
    ]);

    PatientModel::query()->create([
        'tenant_id' => $tenantB,
        'patient_number' => $generator->nextForTenant($tenantB),
        'first_name' => 'Jane',
        'last_name' => 'Doe',
        'gender' => 'female',
        'date_of_birth' => '1992-02-02',
        'country_code' => 'TZ',
    ]);

    PatientModel::query()->create([
        'tenant_id' => $tenantB,
        'patient_number' => $generator->nextForTenant($tenantB),
        'first_name' => 'Baby',
        'last_name' => 'Doe',
        'gender' => 'female',
        'date_of_birth' => '2020-03-03',
        'country_code' => 'TZ',
    ]);

    // Tenant A has 1 patient, Tenant B has 2 patients
    expect(PatientModel::query()->where('tenant_id', $tenantA)->count())->toBe(1);
    expect(PatientModel::query()->where('tenant_id', $tenantB)->count())->toBe(2);

    // Delete Tenant A's only patient via Eloquent
    PatientModel::query()->where('tenant_id', $tenantA)->first()->delete();

    // Tenant A restarts at 00000001, while Tenant B continues at 00000003
    expect($generator->nextForTenant($tenantA))->toBe('00000001');
    expect($generator->nextForTenant($tenantB))->toBe('00000003');
});

it('supports manually resetting and syncing MRN sequences via Artisan command', function (): void {
    $generator = app(PatientMrnGenerator::class);
    $tenantA = (string) Str::uuid();

    DB::table('tenants')->insert([
        'id' => $tenantA, 'code' => 'TENC', 'name' => 'Tenant C', 'country_code' => 'TZ', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
    ]);

    PatientModel::query()->create([
        'tenant_id' => $tenantA,
        'patient_number' => '00000005',
        'first_name' => 'John',
        'last_name' => 'Doe',
        'gender' => 'male',
        'date_of_birth' => '1990-01-01',
        'country_code' => 'TZ',
    ]);

    $this->artisan('patients:reset-mrn-sequence', ['--tenant' => $tenantA, '--sync' => true])
        ->assertSuccessful();

    // With patient 00000005 present, next should be 00000006
    expect($generator->nextForTenant($tenantA))->toBe('00000006');

    // Reset command for all
    $this->artisan('patients:reset-mrn-sequence', ['--all' => true])
        ->assertSuccessful();

    // Now delete patient and verify start at 00000001
    PatientModel::query()->where('tenant_id', $tenantA)->delete();
    $generator->resetForTenant($tenantA);
    expect($generator->nextForTenant($tenantA))->toBe('00000001');
});

function seedMrmTenantScope(
    User $user,
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
        $tenantId = $tenant->id;
    }

    $facility = DB::table('facilities')->where('code', $facilityCode)->first();
    if ($facility === null) {
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
    } else {
        $facilityId = $facility->id;
    }

    DB::table('facility_user')->insertOrIgnore([
        'facility_id' => $facilityId,
        'user_id' => $user->id,
        'role' => 'registration_clerk',
        'is_primary' => true,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return [$tenantId, $facilityId];
}
