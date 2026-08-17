<?php

use App\Models\Permission;
use App\Models\User;
use App\Modules\Appointment\Infrastructure\Models\AppointmentModel;
use App\Modules\MedicalRecord\Infrastructure\Models\MedicalRecordModel;
use App\Modules\Patient\Infrastructure\Models\PatientModel;
use App\Modules\Platform\Infrastructure\Models\RoleModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| A doctor must be able to save a consultation note more than once.
|--------------------------------------------------------------------------
|
| useClinicianEncounter.ts POSTs the first save (create) and PATCHes every save
| after it. The PATCH goes to clinician/medical-records/{id}, which was guarded
| by `medical.records.update` — an ability no role in config/roles.php grants.
| Its legacy twin, medical-records/{id}, is guarded by
| `can:medical.records.draft.update,id`, which passes the record to a real gate
| and works. Same controller action, two doors, different locks.
|
| The existing MedicalRecord tests never caught it because their helper grants
| `medical.records.update` to the user directly. That is not a state any real
| login can be in, so the suite was green while the workspace was broken.
|
| These tests therefore build the user from config/roles.php — the file
| `roles:sync` actually ships — rather than hand-granting permissions.
*/

uses(RefreshDatabase::class);

/**
 * A real CLINICAL.PHYSICIAN, assembled the way the role ships rather than the
 * way a test would find convenient.
 */
function noteEditingPhysician(): User
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

function noteEditingDraft(User $author): MedicalRecordModel
{
    $patient = PatientModel::query()->create([
        'patient_number' => 'PT'.now()->format('Ymd').strtoupper(Str::random(6)),
        'first_name' => 'Baraka',
        'last_name' => 'Mushi',
        'gender' => 'male',
        'date_of_birth' => '1988-06-02',
        'phone' => '+255700000031',
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
        'consultation_owner_user_id' => $author->id,
    ]);

    return MedicalRecordModel::query()->create([
        'record_number' => 'MR'.now()->format('Ymd').strtoupper(Str::random(6)),
        'patient_id' => $patient->id,
        'appointment_id' => $appointment->id,
        'author_user_id' => $author->id,
        'record_type' => 'consultation_note',
        'encounter_at' => now()->subMinutes(10),
        'status' => 'draft',
        'subjective' => 'Headache for two days.',
    ]);
}

it('lets a physician save changes to their own draft note from the clinician workspace', function (): void {
    $physician = noteEditingPhysician();
    $draft = noteEditingDraft($physician);

    // This is the route the workspace actually calls
    // (useClinicianEncounter.ts:348) on every save after the first.
    $this->actingAs($physician)
        ->patchJson('/api/v1/clinician/medical-records/'.$draft->id, [
            'subjective' => 'Headache for two days, now with photophobia.',
        ])
        ->assertOk();
});

it('guards the workspace note route with an ability the physician role actually holds', function (): void {
    $physician = noteEditingPhysician();

    // The regression in one assertion: CLINICAL.PHYSICIAN ships with
    // draft.update, never plain `update`. A route demanding the latter is
    // unreachable for the only role that writes notes.
    expect($physician->hasPermissionTo('medical.records.draft.update'))->toBeTrue();
    expect($physician->hasPermissionTo('medical.records.update'))->toBeFalse();
});

it('keeps the legacy note route working, since it is the same controller action', function (): void {
    $physician = noteEditingPhysician();
    $draft = noteEditingDraft($physician);

    $this->actingAs($physician)
        ->patchJson('/api/v1/medical-records/'.$draft->id, [
            'subjective' => 'Headache for two days, now with photophobia.',
        ])
        ->assertOk();
});

it('still refuses a draft the physician did not author', function (): void {
    $author = noteEditingPhysician();
    $otherPhysician = noteEditingPhysician();
    $draft = noteEditingDraft($author);

    // The fix must not become "let any physician edit any draft" — the gate's
    // authorship rule is the actual protection and has to survive it.
    $this->actingAs($otherPhysician)
        ->patchJson('/api/v1/clinician/medical-records/'.$draft->id, [
            'subjective' => 'Edited by someone else.',
        ])
        ->assertForbidden();
});
