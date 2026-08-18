<?php

use App\Models\Permission;
use App\Models\User;
use App\Modules\Appointment\Infrastructure\Models\AppointmentModel;
use App\Modules\Encounter\Infrastructure\Models\EncounterModel;
use App\Modules\MedicalRecord\Domain\ValueObjects\MedicalRecordNoteType;
use App\Modules\MedicalRecord\Infrastructure\Models\MedicalRecordModel;
use App\Modules\Patient\Infrastructure\Models\PatientModel;
use App\Modules\Platform\Infrastructure\Models\RoleModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| The first save of a consultation note has to reach the database.
|--------------------------------------------------------------------------
|
| ClinicianWorkspaceNoteEditingTest covers the PATCH — every save after the
| first. Nothing covered the POST that creates the record, and it was broken:
| useClinicianEncounter.ts sent `recordType: "outpatient_consultation"`, which
| is not a MedicalRecordNoteType. Every autosave came back 422.
|
| It stayed invisible because saveDraftNote() never inspected res.ok. fetch()
| resolves for 4xx, so the rejection fell through to the success path, stamped
| lastSavedAt and painted a green "Draft saved HH:MM". A doctor could write a
| full SOAP note, watch it report itself saved, and find medical_records empty
| — the note only ever existed in localStorage.
|
| These tests therefore assert against the row, not the response: a 201 with
| nothing persisted is the failure mode being guarded, and the last test pins
| the frontend literal to the enum so the two cannot drift apart again.
*/

uses(RefreshDatabase::class);

function noteCreationPhysician(): User
{
    $definition = collect((array) config('roles', []))
        ->first(static fn (array $role): bool => ($role['code'] ?? null) === 'CLINICAL.PHYSICIAN');

    expect($definition)->not->toBeNull('CLINICAL.PHYSICIAN is not defined in config/roles.php.');

    $role = RoleModel::query()->create([
        'code' => $definition['code'],
        'name' => $definition['name'] ?? $definition['code'],
        'status' => 'active',
        'is_system' => true,
        'access_level' => $definition['access_level'] ?? 'request',
        'scope_type' => $definition['scope_type'] ?? 'facility',
    ]);

    $permissionIds = collect((array) ($definition['permissions'] ?? []))
        ->map(static fn (string $name) => Permission::query()->firstOrCreate(['name' => $name])->id)
        ->all();
    $role->permissions()->syncWithoutDetaching($permissionIds);

    $user = User::factory()->create();
    $user->roles()->syncWithoutDetaching([$role->id]);

    return $user->fresh();
}

/**
 * @return array{patient: PatientModel, encounter: EncounterModel}
 */
function noteCreationEncounter(User $physician): array
{
    $patient = PatientModel::query()->create([
        'patient_number' => 'PT'.now()->format('Ymd').strtoupper(Str::random(6)),
        'first_name' => 'Neema',
        'last_name' => 'Kessy',
        'gender' => 'female',
        'date_of_birth' => '1991-11-14',
        'phone' => '+255700000042',
        'country_code' => 'TZ',
        'status' => 'active',
    ]);

    $appointment = AppointmentModel::query()->create([
        'appointment_number' => 'APT'.now()->format('Ymd').strtoupper(Str::random(6)),
        'patient_id' => $patient->id,
        'department' => 'Outpatient',
        'scheduled_at' => now()->subHour(),
        'duration_minutes' => 30,
        'reason' => 'Consultation',
        'status' => 'in_consultation',
        'consultation_started_at' => now()->subMinutes(10),
        'consultation_owner_user_id' => $physician->id,
    ]);

    $encounter = EncounterModel::query()->create([
        'encounter_number' => 'ENC'.now()->format('Ymd').strtoupper(Str::random(6)),
        'patient_id' => $patient->id,
        'appointment_id' => $appointment->id,
        'status' => 'opened',
        'opened_at' => now()->subMinutes(10),
    ]);

    return ['patient' => $patient, 'encounter' => $encounter];
}

/**
 * The body useClinicianEncounter.ts::saveDraftNote() builds for its create
 * call, field for field. Chief complaint really is folded into `subjective`
 * behind a [CC: ...] prefix there — the round-trip test below depends on it.
 *
 * @return array<string, mixed>
 */
function workspaceNotePayload(string $patientId, string $encounterId, array $overrides = []): array
{
    return array_merge([
        'patientId' => $patientId,
        'encounterId' => $encounterId,
        'encounterAt' => now()->toISOString(),
        'recordType' => 'consultation_note',
        'subjective' => "[CC: Fever and headache]\nFever for three days, worse at night.",
        'objective' => 'Chest clear to auscultation bilaterally. Temp 38.4C.',
        'assessment' => 'Suspected uncomplicated malaria.',
        'plan' => 'Start ALu. Review in 3 days.',
        'diagnosisCode' => 'B54',
    ], $overrides);
}

it('persists a consultation note the workspace posts on its first save', function (): void {
    $physician = noteCreationPhysician();
    ['patient' => $patient, 'encounter' => $encounter] = noteCreationEncounter($physician);

    expect(MedicalRecordModel::query()->count())->toBe(0);

    $this->actingAs($physician)
        ->postJson('/api/v1/clinician/medical-records', workspaceNotePayload($patient->id, $encounter->id))
        ->assertCreated();

    // The row is the assertion. A green "saved" in the header proved nothing;
    // this is the only thing that survives the doctor closing the browser.
    $record = MedicalRecordModel::query()->where('patient_id', $patient->id)->first();

    expect($record)->not->toBeNull('The consultation note never reached medical_records.');
    expect($record->encounter_id)->toBe($encounter->id);
    expect($record->record_type)->toBe('consultation_note');
    expect($record->subjective)->toContain('Fever for three days');
    expect($record->objective)->toContain('Chest clear');
    expect($record->assessment)->toContain('malaria');
    expect($record->plan)->toContain('Review in 3 days');
});

it('rejects a record type outside the domain enum instead of silently dropping the note', function (): void {
    $physician = noteCreationPhysician();
    ['patient' => $patient, 'encounter' => $encounter] = noteCreationEncounter($physician);

    // The exact literal the workspace used to send. The API was always right to
    // refuse it; the bug was that the caller treated the refusal as success.
    $this->actingAs($physician)
        ->postJson('/api/v1/clinician/medical-records', workspaceNotePayload($patient->id, $encounter->id, [
            'recordType' => 'outpatient_consultation',
        ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('recordType');

    expect(MedicalRecordModel::query()->count())->toBe(0);
});

it('returns the saved note in the workspace payload so a later login still shows it', function (): void {
    $physician = noteCreationPhysician();
    ['patient' => $patient, 'encounter' => $encounter] = noteCreationEncounter($physician);

    $this->actingAs($physician)
        ->postJson('/api/v1/clinician/medical-records', workspaceNotePayload($patient->id, $encounter->id))
        ->assertCreated();

    // The scenario that exposed the bug: sign out, work as other staff, come
    // back later. Nothing of the note lives in the browser at that point, so
    // the workspace bundle has to carry it or the doctor sees a blank note.
    $response = $this->actingAs($physician)
        ->getJson('/api/v1/clinician/encounters/'.$encounter->id.'?view=workspace')
        ->assertOk();

    $record = $response->json('data.primaryMedicalRecord');

    expect($record)->not->toBeNull('The workspace did not hand back the saved note.');
    expect($record['subjective'])->toContain('[CC: Fever and headache]');
    expect($record['objective'])->toContain('Chest clear');
    expect($record['assessment'])->toContain('malaria');
    expect($record['plan'])->toContain('Review in 3 days');
});

it('keeps the frontend record type literal inside the domain enum', function (): void {
    $source = file_get_contents(
        base_path('resources/ts/pages/clinician/composables/useClinicianEncounter.ts')
    );

    expect($source)->not->toBeFalse('useClinicianEncounter.ts could not be read.');

    preg_match_all('/recordType:\s*"([^"]+)"/', (string) $source, $matches);

    expect($matches[1])->not->toBeEmpty(
        'No recordType literal found — if the create call moved, point this test at its new home.'
    );

    // The guard that actually catches this class of bug. Every other test here
    // sends a payload a human wrote; only this one reads what the app ships.
    foreach ($matches[1] as $literal) {
        expect(MedicalRecordNoteType::isValid($literal))->toBeTrue(
            sprintf(
                'useClinicianEncounter.ts sends recordType "%s", which MedicalRecordNoteType rejects. Allowed: %s.',
                $literal,
                implode(', ', MedicalRecordNoteType::values()),
            )
        );
    }
});
