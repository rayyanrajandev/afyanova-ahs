<?php

use App\Http\Middleware\EnsureFacilitySubscriptionEntitlement;
use App\Http\Middleware\EnsureMappedFacilitySubscriptionEntitlement;
use App\Models\User;
use App\Modules\Appointment\Infrastructure\Models\AppointmentModel;
use App\Modules\Encounter\Infrastructure\Models\EncounterModel;
use App\Modules\Patient\Infrastructure\Models\PatientModel;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * `POST nursing/assessments/{encounterId}` and `POST nursing/notes/{id}`
 * (Volume 2.3 §12.2) both 500'd on every real call until 2026-08-13 — the
 * routes had no URI parameter at all, but `NurseQueueController::assess()`/
 * `EncounterClinicalAttachmentController::store()` each require one
 * positionally, so Laravel's dependency resolution misaligned the remaining
 * class-typed parameters (confirmed live: a TypeError, not a validation
 * error — see routes/api-workspaces.php's own comment on the fix). Neither
 * endpoint had any test coverage before this, which is how the bug went
 * unnoticed. This file locks in the fix.
 */
beforeEach(function (): void {
    Storage::fake('local');
    $this->withoutMiddleware([
        PreventRequestForgery::class,
        EnsureMappedFacilitySubscriptionEntitlement::class,
        EnsureFacilitySubscriptionEntitlement::class,
    ]);
});

function makeNursingDocsActor(array $permissions = []): User
{
    $user = User::factory()->create();

    foreach ($permissions as $permission) {
        $user->givePermissionTo($permission);
    }

    return $user;
}

function makeNursingDocsEncounter(): array
{
    $patient = PatientModel::query()->create([
        'patient_number' => 'PT-NURS-'.strtoupper(Str::random(6)),
        'first_name' => 'Zuhura',
        'middle_name' => null,
        'last_name' => 'Hamisi',
        'gender' => 'female',
        'date_of_birth' => '1988-03-02',
        'phone' => '+255700333444',
        'email' => 'zuhura@example.test',
        'country_code' => 'TZ',
        'region' => 'Dar es Salaam',
        'district' => 'Ilala',
        'address_line' => 'Kariakoo',
        'status' => 'active',
    ]);
    $appointment = AppointmentModel::query()->create([
        'appointment_number' => 'APT-NURS-'.strtoupper(Str::random(6)),
        'patient_id' => $patient->id,
        'clinician_user_id' => null,
        'department' => 'General Medicine',
        'scheduled_at' => now(),
        'duration_minutes' => 30,
        'reason' => 'Walk-in',
        'notes' => null,
        'status' => 'checked_in',
        'status_reason' => null,
    ]);
    $encounter = EncounterModel::query()->create([
        'encounter_number' => 'ENC-NURS-'.strtoupper(Str::random(6)),
        'patient_id' => $patient->id,
        'appointment_id' => $appointment->id,
        'status' => 'opened',
        'opened_at' => now(),
    ]);

    return [$patient, $appointment, $encounter];
}

it('completes a nurse assessment and creates the downstream service request', function (): void {
    [, , $encounter] = makeNursingDocsEncounter();
    $nurse = makeNursingDocsActor(['service.requests.create']);

    $response = $this->actingAs($nurse)
        ->postJson("/api/v1/nursing/assessments/{$encounter->id}", [
            'clinicalNote' => 'Patient stable, ordering baseline labs.',
            'items' => [
                ['itemName' => 'CBC', 'serviceType' => 'laboratory', 'quantity' => 1],
            ],
        ]);

    $response->assertCreated();
    $response->assertJsonPath('data.encounterId', $encounter->id);
    $response->assertJsonPath('data.serviceType', 'laboratory');

    $this->assertDatabaseHas('service_requests', [
        'encounter_id' => $encounter->id,
        'assessed_by_user_id' => $nurse->id,
    ]);
});

it('forbids completing an assessment without service.requests.create', function (): void {
    [, , $encounter] = makeNursingDocsEncounter();
    $nurse = makeNursingDocsActor([]);

    $this->actingAs($nurse)
        ->postJson("/api/v1/nursing/assessments/{$encounter->id}", [
            'clinicalNote' => 'Note',
            'items' => [['itemName' => 'CBC', 'serviceType' => 'laboratory']],
        ])
        ->assertForbidden();
});

it('completes an assessment with no service items — a real outcome, not an error', function (): void {
    // Volume 3.8 Phase 5, 2026-08-13: a nurse who reviews a patient and
    // decides no downstream orders are needed must still be able to
    // complete the assessment — this is also what makes the removed
    // `nursing/tasks/{id}/complete` route (never implemented, dead) fully
    // redundant rather than a missing feature.
    [, , $encounter] = makeNursingDocsEncounter();
    $nurse = makeNursingDocsActor(['service.requests.create']);

    $response = $this->actingAs($nurse)
        ->postJson("/api/v1/nursing/assessments/{$encounter->id}", [
            'clinicalNote' => 'Reviewed, stable, no further orders needed.',
            'items' => [],
        ]);

    $response->assertCreated();
    $response->assertJsonPath('data.encounterId', $encounter->id);

    $this->assertDatabaseHas('service_requests', [
        'encounter_id' => $encounter->id,
        'assessed_by_user_id' => $nurse->id,
    ]);
});

it('rejects an assessment with the items field missing entirely', function (): void {
    [, , $encounter] = makeNursingDocsEncounter();
    $nurse = makeNursingDocsActor(['service.requests.create']);

    $this->actingAs($nurse)
        ->postJson("/api/v1/nursing/assessments/{$encounter->id}", [
            'clinicalNote' => 'Note',
        ])
        ->assertUnprocessable();
});

it('uploads a nursing note document to the encounter', function (): void {
    [, , $encounter] = makeNursingDocsEncounter();
    $nurse = makeNursingDocsActor(['medical.records.create']);
    $file = UploadedFile::fake()->create('shift-note.pdf', 50, 'application/pdf');

    $response = $this->actingAs($nurse)
        ->post("/api/v1/nursing/notes/{$encounter->id}", [
            'documentType' => 'nursing_note',
            'title' => 'Shift handover note',
            'file' => $file,
        ]);

    $response->assertCreated();
    $response->assertJsonPath('data.encounterId', $encounter->id);
    $response->assertJsonPath('data.documentType', 'nursing_note');

    $this->assertDatabaseHas('encounter_clinical_documents', [
        'encounter_id' => $encounter->id,
        'title' => 'Shift handover note',
    ]);
});

it('forbids uploading a nursing note without medical.records.create', function (): void {
    [, , $encounter] = makeNursingDocsEncounter();
    $nurse = makeNursingDocsActor([]);
    $file = UploadedFile::fake()->create('note.pdf', 10, 'application/pdf');

    $this->actingAs($nurse)
        ->post("/api/v1/nursing/notes/{$encounter->id}", [
            'documentType' => 'nursing_note',
            'title' => 'Note',
            'file' => $file,
        ])
        ->assertForbidden();
});
