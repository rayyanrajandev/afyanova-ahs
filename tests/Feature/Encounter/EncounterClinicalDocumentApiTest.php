<?php

use App\Http\Middleware\EnsureFacilitySubscriptionEntitlement;
use App\Http\Middleware\EnsureMappedFacilitySubscriptionEntitlement;
use App\Models\User;
use App\Modules\Appointment\Infrastructure\Models\AppointmentModel;
use App\Modules\Encounter\Infrastructure\Models\EncounterClinicalDocumentModel;
use App\Modules\Encounter\Infrastructure\Models\EncounterModel;
use App\Modules\Patient\Infrastructure\Models\PatientModel;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('local');
    $this->withoutMiddleware([
        PreventRequestForgery::class,
        EnsureMappedFacilitySubscriptionEntitlement::class,
        EnsureFacilitySubscriptionEntitlement::class,
    ]);
});

function makeEncounterClinicalDocumentActor(array $permissions = []): User
{
    $user = User::factory()->create();

    foreach ($permissions as $permission) {
        $user->givePermissionTo($permission);
    }

    return $user;
}

function makeEncounterClinicalDocumentPatient(): PatientModel
{
    return PatientModel::query()->create([
        'patient_number' => 'PT-ENC-DOC-'.strtoupper(Str::random(6)),
        'first_name' => 'Asha',
        'middle_name' => null,
        'last_name' => 'Kimaro',
        'gender' => 'female',
        'date_of_birth' => '1990-06-18',
        'phone' => '+255700111222',
        'email' => 'asha@example.test',
        'country_code' => 'TZ',
        'region' => 'Dar es Salaam',
        'district' => 'Ilala',
        'address_line' => 'Kariakoo',
        'status' => 'active',
    ]);
}

function makeEncounterClinicalDocumentContext(): array
{
    $patient = makeEncounterClinicalDocumentPatient();
    $appointment = AppointmentModel::query()->create([
        'appointment_number' => 'APT-ENC-DOC-'.strtoupper(Str::random(6)),
        'patient_id' => $patient->id,
        'clinician_user_id' => null,
        'department' => 'General Medicine',
        'scheduled_at' => '2026-05-21 10:00:00',
        'duration_minutes' => 30,
        'reason' => 'Review visit',
        'notes' => null,
        'status' => 'checked_in',
        'status_reason' => null,
    ]);
    $encounter = EncounterModel::query()->create([
        'encounter_number' => 'ENC-DOC-'.strtoupper(Str::random(6)),
        'patient_id' => $patient->id,
        'appointment_id' => $appointment->id,
        'status' => 'opened',
        'opened_at' => now(),
    ]);

    return [$patient, $appointment, $encounter];
}

/**
 * @return array<string, mixed>
 */
function encounterClinicalDocumentPayload(array $overrides = []): array
{
    return array_merge([
        'documentType' => 'lab_result',
        'title' => 'Outside CBC Report',
        'description' => 'External lab result from referral facility',
        'file' => UploadedFile::fake()->create('cbc-report.pdf', 120, 'application/pdf'),
    ], $overrides);
}

it('requires authentication for encounter clinical document endpoints', function (): void {
    [, , $encounter] = makeEncounterClinicalDocumentContext();

    $this->getJson('/api/v1/encounters/'.$encounter->id.'/clinical-documents')->assertUnauthorized();

    $this->withHeader('Accept', 'application/json')
        ->post('/api/v1/encounters/'.$encounter->id.'/clinical-documents', encounterClinicalDocumentPayload())
        ->assertUnauthorized();
});

it('creates lists and downloads encounter clinical documents when authorized', function (): void {
    $actor = makeEncounterClinicalDocumentActor([
        'medical.records.read',
        'medical.records.create',
        'medical.records.update',
    ]);
    [, , $encounter] = makeEncounterClinicalDocumentContext();

    $created = $this->actingAs($actor)
        ->withHeader('Accept', 'application/json')
        ->post('/api/v1/encounters/'.$encounter->id.'/clinical-documents', encounterClinicalDocumentPayload())
        ->assertCreated()
        ->assertJsonPath('data.title', 'Outside CBC Report')
        ->assertJsonPath('data.documentType', 'lab_result')
        ->assertJsonPath('data.status', 'active')
        ->json('data');

    $this->actingAs($actor)
        ->getJson('/api/v1/encounters/'.$encounter->id.'/clinical-documents?status=active')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.id', $created['id']);

    $this->actingAs($actor)
        ->get('/api/v1/encounters/'.$encounter->id.'/clinical-documents/'.$created['id'].'/download')
        ->assertOk()
        ->assertDownload('cbc-report.pdf');

    $this->actingAs($actor)
        ->patchJson('/api/v1/encounters/'.$encounter->id.'/clinical-documents/'.$created['id'], [
            'title' => 'Updated CBC Report',
        ])
        ->assertOk()
        ->assertJsonPath('data.title', 'Updated CBC Report');

    $this->actingAs($actor)
        ->patchJson('/api/v1/encounters/'.$encounter->id.'/clinical-documents/'.$created['id'].'/status', [
            'status' => 'archived',
            'reason' => 'Superseded by newer upload',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'archived')
        ->assertJsonPath('data.statusReason', 'Superseded by newer upload');

    expect(
        EncounterClinicalDocumentModel::query()
            ->where('id', $created['id'])
            ->value('status')
    )->toBe('archived');
});

it('returns not found when encounter does not exist', function (): void {
    $actor = makeEncounterClinicalDocumentActor(['medical.records.read']);

    $this->actingAs($actor)
        ->getJson('/api/v1/encounters/'.Str::uuid().'/clinical-documents')
        ->assertNotFound();
});
