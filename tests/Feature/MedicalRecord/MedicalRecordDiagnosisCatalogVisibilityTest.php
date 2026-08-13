<?php

use App\Modules\MedicalRecord\Infrastructure\Models\MedicalRecordAuditLogModel;
use App\Modules\Patient\Infrastructure\Models\PatientModel;
use App\Modules\Platform\Infrastructure\Models\ClinicalCatalogItemModel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Regression coverage for the C-10 fix, Option B (decided — see
 * reports/clinical-note-audit/16-remediation-options-c8-c9-c10-c12.md):
 * when the diagnosis-terminology catalog has zero active entries, a
 * shape-valid ICD-10-style code is still accepted (unchanged behavior —
 * Option A's fail-closed default was rejected as an onboarding-friction
 * risk), but that acceptance must now be visible, not silent: the audit-log
 * entry for the create/update carries
 * metadata.diagnosis_code_catalog_verified = false.
 */
function diagnosisVisibilityPatient(): PatientModel
{
    return PatientModel::query()->create([
        'patient_number' => 'PTDXV'.now()->format('Ymd').strtoupper(Str::random(6)),
        'first_name' => 'Diagnosis', 'last_name' => 'Visibility', 'gender' => 'male',
        'date_of_birth' => '1982-02-12', 'phone' => '+255700000021', 'country_code' => 'TZ',
        'status' => 'active',
    ]);
}

function diagnosisVisibilityUser(): User
{
    $user = User::factory()->create();
    foreach ([
        'medical.records.read',
        'medical.records.create',
        'medical.records.update',
    ] as $permission) {
        $user->givePermissionTo($permission);
    }

    return $user;
}

function diagnosisVisibilityPayload(string $patientId, array $overrides = []): array
{
    return array_merge([
        'patientId' => $patientId,
        'encounterAt' => now()->toDateTimeString(),
        'recordType' => 'progress_note',
        'subjective' => 'Fixture note.',
        'diagnosisCode' => 'R52',
    ], $overrides);
}

function diagnosisVisibilitySeedCatalogCode(string $code): void
{
    ClinicalCatalogItemModel::query()->create([
        'tenant_id' => null,
        'facility_id' => null,
        'catalog_type' => 'diagnosis_code',
        'code' => $code,
        'name' => 'Fixture diagnosis',
        'status' => 'active',
    ]);
}

it('flags a created record diagnosis code as catalog-unverified when the catalog is empty', function (): void {
    $user = diagnosisVisibilityUser();
    $patient = diagnosisVisibilityPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/medical-records', diagnosisVisibilityPayload($patient->id))
        ->assertCreated()
        ->json('data');

    $log = MedicalRecordAuditLogModel::query()
        ->where('medical_record_id', $created['id'])
        ->where('action', 'medical-record.created')
        ->first();

    expect($log)->not->toBeNull();
    expect($log->metadata['diagnosis_code_catalog_verified'] ?? null)->toBeFalse();
});

it('does not flag a created record when the code is verified against a populated catalog', function (): void {
    diagnosisVisibilitySeedCatalogCode('R52');

    $user = diagnosisVisibilityUser();
    $patient = diagnosisVisibilityPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/medical-records', diagnosisVisibilityPayload($patient->id))
        ->assertCreated()
        ->json('data');

    $log = MedicalRecordAuditLogModel::query()
        ->where('medical_record_id', $created['id'])
        ->where('action', 'medical-record.created')
        ->first();

    expect($log)->not->toBeNull();
    expect(array_key_exists('diagnosis_code_catalog_verified', $log->metadata ?? []))->toBeFalse();
});

it('does not flag a created record with no diagnosis code at all', function (): void {
    $user = diagnosisVisibilityUser();
    $patient = diagnosisVisibilityPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/medical-records', diagnosisVisibilityPayload($patient->id, ['diagnosisCode' => null]))
        ->assertCreated()
        ->json('data');

    $log = MedicalRecordAuditLogModel::query()
        ->where('medical_record_id', $created['id'])
        ->where('action', 'medical-record.created')
        ->first();

    expect(array_key_exists('diagnosis_code_catalog_verified', $log->metadata ?? []))->toBeFalse();
});

it('flags an updated record diagnosis code as catalog-unverified when the catalog is empty', function (): void {
    $user = diagnosisVisibilityUser();
    $patient = diagnosisVisibilityPatient();

    $created = $this->actingAs($user)
        ->postJson('/api/v1/medical-records', diagnosisVisibilityPayload($patient->id, ['diagnosisCode' => null]))
        ->assertCreated()
        ->json('data');

    $this->actingAs($user)
        ->patchJson('/api/v1/medical-records/'.$created['id'], ['diagnosisCode' => 'J11.0'])
        ->assertOk();

    $log = MedicalRecordAuditLogModel::query()
        ->where('medical_record_id', $created['id'])
        ->where('action', 'medical-record.updated')
        ->latest('created_at')
        ->first();

    expect($log)->not->toBeNull();
    expect($log->metadata['diagnosis_code_catalog_verified'] ?? null)->toBeFalse();
});
