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

    expect($generator->nextForTenant(null))->toBe('00000001');
    expect($generator->nextForTenant(null))->toBe('00000002');
    expect($generator->nextForTenant(null))->toBe('00000003');

    $tenantA = (string) Str::uuid();
    $tenantB = (string) Str::uuid();

    expect($generator->nextForTenant($tenantA))->toBe('00000001');
    expect($generator->nextForTenant($tenantA))->toBe('00000002');
    expect($generator->nextForTenant($tenantB))->toBe('00000001');
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
