<?php

use App\Models\User;
use App\Http\Middleware\EnsureFacilitySubscriptionEntitlement;
use App\Modules\Patient\Infrastructure\Models\PatientModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutMiddleware(EnsureFacilitySubscriptionEntitlement::class);
});

function bulkPatientPayload(array $overrides = []): array
{
    return array_merge([
        'firstName' => 'Amina',
        'middleName' => null,
        'lastName' => 'Moshi',
        'gender' => 'female',
        'dateOfBirth' => '1996-04-21',
        'phone' => '+255700000001',
        'email' => 'amina@example.test',
        'nationalId' => 'TZ-123456789',
        'countryCode' => 'TZ',
        'region' => 'Dar es Salaam',
        'district' => 'Ilala',
        'addressLine' => 'Msasani',
        'nextOfKinName' => 'Juma Moshi',
        'nextOfKinPhone' => '+255700000002',
    ], $overrides);
}

function makeBulkExportUser(): User
{
    $user = User::factory()->create();
    $user->givePermissionTo('patients.read');
    $user->givePermissionTo('patients.create');
    $user->givePermissionTo('patients.export');

    return $user;
}

function makeBulkImportUser(): User
{
    $user = makeBulkExportUser();
    $user->givePermissionTo('patients.import');

    return $user;
}

function importRow(array $values): array
{
    $defaults = [
        'id' => '',
        'tenant_id' => '',
        'patient_number' => '',
        'first_name' => 'Jane',
        'middle_name' => '',
        'last_name' => 'Doe',
        'gender' => 'female',
        'date_of_birth' => '1990-05-14',
        'phone' => '0712345678',
        'email' => '',
        'national_id' => '',
        'country_code' => 'TZ',
        'region' => 'Dar es Salaam',
        'district' => 'Kinondoni',
        'address_line' => 'Mikocheni Street 12',
        'next_of_kin_name' => '',
        'next_of_kin_phone' => '',
        'status' => 'active',
        'status_reason' => '',
        'created_at' => '',
        'updated_at' => '',
    ];

    return array_merge($defaults, $values);
}

it('requires patients.export to download the CSV export', function (): void {
    $user = User::factory()->create();
    $user->givePermissionTo('patients.read');

    $this->actingAs($user)->getJson('/api/v1/patients/export/csv')->assertForbidden();
});

it('exports patients as CSV including patient_number, status, and id', function (): void {
    $user = makeBulkExportUser();

    $created = $this->actingAs($user)->postJson('/api/v1/patients', bulkPatientPayload())->json('data');

    $response = $this->actingAs($user)->get('/api/v1/patients/export/csv');

    $response->assertOk();
    $csv = $response->streamedContent();

    expect($csv)->toContain('patient_number');
    expect($csv)->toContain($created['patientNumber']);
    expect($csv)->toContain($created['id']);
    expect($csv)->toContain('Amina');
});

it('requires patients.import to run a bulk import', function (): void {
    $user = makeBulkExportUser();

    $this->actingAs($user)->postJson('/api/v1/patients/bulk-import', [
        'dryRun' => true,
        'rows' => [['rowNumber' => 2, 'values' => importRow([])]],
    ])->assertForbidden();
});

it('does not persist anything on a dry-run import', function (): void {
    $user = makeBulkImportUser();

    $response = $this->actingAs($user)->postJson('/api/v1/patients/bulk-import', [
        'dryRun' => true,
        'rows' => [['rowNumber' => 2, 'values' => importRow(['first_name' => 'Zainab'])]],
    ]);

    $response->assertOk();
    $response->assertJsonPath('data.dry_run', true);
    $response->assertJsonPath('data.created_count', 1);
    $response->assertJsonPath('data.results.0.outcome', 'would_create');

    expect(PatientModel::where('first_name', 'Zainab')->count())->toBe(0);
});

it('restores a patient preserving its original id, patient_number, and status', function (): void {
    $user = makeBulkImportUser();
    $originalId = (string) Str::uuid();

    $response = $this->actingAs($user)->postJson('/api/v1/patients/bulk-import', [
        'dryRun' => false,
        'rows' => [[
            'rowNumber' => 2,
            'values' => importRow([
                'id' => $originalId,
                'patient_number' => 'PT20260101ABCDEF',
                'first_name' => 'Restored',
                'last_name' => 'Patient',
                'status' => 'inactive',
            ]),
        ]],
    ]);

    $response->assertOk();
    $response->assertJsonPath('data.created_count', 1);
    $response->assertJsonPath('data.results.0.outcome', 'created');
    $response->assertJsonPath('data.results.0.patientId', $originalId);

    $patient = PatientModel::find($originalId);
    expect($patient)->not->toBeNull();
    expect($patient->patient_number)->toBe('PT20260101ABCDEF');
    expect($patient->status)->toBe('inactive');
    expect($patient->first_name)->toBe('Restored');
});

it('upserts an existing patient by id instead of creating a duplicate', function (): void {
    $user = makeBulkImportUser();

    $created = $this->actingAs($user)->postJson('/api/v1/patients', bulkPatientPayload())->json('data');
    $patientId = $created['id'];

    $response = $this->actingAs($user)->postJson('/api/v1/patients/bulk-import', [
        'dryRun' => false,
        'rows' => [[
            'rowNumber' => 2,
            'values' => importRow([
                'id' => $patientId,
                'patient_number' => $created['patientNumber'],
                'first_name' => 'AminaUpdated',
                'last_name' => 'Moshi',
                'status' => 'active',
            ]),
        ]],
    ]);

    $response->assertOk();
    $response->assertJsonPath('data.updated_count', 1);
    $response->assertJsonPath('data.created_count', 0);
    $response->assertJsonPath('data.results.0.outcome', 'updated');

    expect(PatientModel::where('id', $patientId)->count())->toBe(1);
    expect(PatientModel::find($patientId)->first_name)->toBe('Aminaupdated');
});

it('reports validation errors for malformed rows without failing the whole batch', function (): void {
    $user = makeBulkImportUser();

    $response = $this->actingAs($user)->postJson('/api/v1/patients/bulk-import', [
        'dryRun' => true,
        'rows' => [
            ['rowNumber' => 2, 'values' => importRow(['first_name' => ''])],
            ['rowNumber' => 3, 'values' => importRow(['first_name' => 'Valid'])],
        ],
    ]);

    $response->assertOk();
    $response->assertJsonPath('data.failed_count', 1);
    $response->assertJsonPath('data.created_count', 1);
    $response->assertJsonPath('data.results.0.outcome', 'failed');
});
