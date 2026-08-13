<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Phase 2 of reports/queue-based-workflow-modernization-plan.md. Reuses
 * makePatient()/appointmentPayload()/makeAppointmentUser()/
 * grantAppointmentTriagePermissions() from AppointmentApiTest.php (Pest
 * shares global function scope across sibling test files in the same run).
 */
function makeWaitingTriageAppointment(User $creator, string $patientId): array
{
    $created = test()
        ->actingAs($creator)
        ->postJson('/api/v1/appointments', appointmentPayload($patientId))
        ->assertCreated()
        ->json('data');

    return test()
        ->actingAs($creator)
        ->patchJson('/api/v1/appointments/'.$created['id'].'/status', [
            'status' => 'waiting_triage',
            'reason' => null,
        ])
        ->assertOk()
        ->json('data');
}

it('claims an unclaimed visit for triage', function (): void {
    $creator = makeAppointmentUser();
    $nurse = User::factory()->create();
    grantAppointmentTriagePermissions($nurse);
    $patient = makePatient();

    $appointment = makeWaitingTriageAppointment($creator, $patient->id);

    $this->actingAs($nurse)
        ->patchJson('/api/v1/appointments/'.$appointment['id'].'/claim-triage')
        ->assertOk()
        ->assertJsonPath('data.triageOwnerUserId', $nurse->id)
        ->assertJsonPath('data.status', 'waiting_triage');
});

it('rejects claiming a visit already claimed by another nurse', function (): void {
    $creator = makeAppointmentUser();
    $firstNurse = User::factory()->create();
    grantAppointmentTriagePermissions($firstNurse);
    $secondNurse = User::factory()->create();
    grantAppointmentTriagePermissions($secondNurse);
    $patient = makePatient();

    $appointment = makeWaitingTriageAppointment($creator, $patient->id);

    $this->actingAs($firstNurse)
        ->patchJson('/api/v1/appointments/'.$appointment['id'].'/claim-triage')
        ->assertOk();

    $this->actingAs($secondNurse)
        ->patchJson('/api/v1/appointments/'.$appointment['id'].'/claim-triage')
        ->assertStatus(409)
        ->assertJsonPath('code', 'TRIAGE_CLAIM_CONFLICT')
        ->assertJsonPath('context.triageOwnerUserId', $firstNurse->id);
});

it('allows a forced takeover of an existing triage claim', function (): void {
    $creator = makeAppointmentUser();
    $firstNurse = User::factory()->create();
    grantAppointmentTriagePermissions($firstNurse);
    $secondNurse = User::factory()->create();
    grantAppointmentTriagePermissions($secondNurse);
    $patient = makePatient();

    $appointment = makeWaitingTriageAppointment($creator, $patient->id);

    $this->actingAs($firstNurse)
        ->patchJson('/api/v1/appointments/'.$appointment['id'].'/claim-triage')
        ->assertOk();

    $this->actingAs($secondNurse)
        ->patchJson('/api/v1/appointments/'.$appointment['id'].'/claim-triage', [
            'forceTakeover' => true,
        ])
        ->assertOk()
        ->assertJsonPath('data.triageOwnerUserId', $secondNurse->id);
});

it('is idempotent when the same nurse claims twice', function (): void {
    $creator = makeAppointmentUser();
    $nurse = User::factory()->create();
    grantAppointmentTriagePermissions($nurse);
    $patient = makePatient();

    $appointment = makeWaitingTriageAppointment($creator, $patient->id);

    $this->actingAs($nurse)
        ->patchJson('/api/v1/appointments/'.$appointment['id'].'/claim-triage')
        ->assertOk();

    $this->actingAs($nurse)
        ->patchJson('/api/v1/appointments/'.$appointment['id'].'/claim-triage')
        ->assertOk()
        ->assertJsonPath('data.triageOwnerUserId', $nurse->id);
});

it('rejects claiming a visit that is not waiting for triage', function (): void {
    $creator = makeAppointmentUser();
    $nurse = User::factory()->create();
    grantAppointmentTriagePermissions($nurse);
    $patient = makePatient();

    $created = $this->actingAs($creator)
        ->postJson('/api/v1/appointments', appointmentPayload($patient->id))
        ->assertCreated()
        ->json('data');

    $this->actingAs($nurse)
        ->patchJson('/api/v1/appointments/'.$created['id'].'/claim-triage')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['status']);
});

it('forbids claiming triage without the record-triage permission', function (): void {
    $creator = makeAppointmentUser();
    $userWithoutTriage = User::factory()->create();
    grantAppointmentReadPermission($userWithoutTriage);
    $patient = makePatient();

    $appointment = makeWaitingTriageAppointment($creator, $patient->id);

    $this->actingAs($userWithoutTriage)
        ->patchJson('/api/v1/appointments/'.$appointment['id'].'/claim-triage')
        ->assertForbidden();
});

it('releases a triage claim held by the same nurse', function (): void {
    $creator = makeAppointmentUser();
    $nurse = User::factory()->create();
    grantAppointmentTriagePermissions($nurse);
    $patient = makePatient();

    $appointment = makeWaitingTriageAppointment($creator, $patient->id);

    $this->actingAs($nurse)
        ->patchJson('/api/v1/appointments/'.$appointment['id'].'/claim-triage')
        ->assertOk();

    $this->actingAs($nurse)
        ->patchJson('/api/v1/appointments/'.$appointment['id'].'/release-triage-claim')
        ->assertOk()
        ->assertJsonPath('data.triageOwnerUserId', null);
});

it('is a no-op releasing an unclaimed visit', function (): void {
    $creator = makeAppointmentUser();
    $nurse = User::factory()->create();
    grantAppointmentTriagePermissions($nurse);
    $patient = makePatient();

    $appointment = makeWaitingTriageAppointment($creator, $patient->id);

    $this->actingAs($nurse)
        ->patchJson('/api/v1/appointments/'.$appointment['id'].'/release-triage-claim')
        ->assertOk()
        ->assertJsonPath('data.triageOwnerUserId', null);
});

it('rejects releasing a claim held by another nurse', function (): void {
    $creator = makeAppointmentUser();
    $firstNurse = User::factory()->create();
    grantAppointmentTriagePermissions($firstNurse);
    $secondNurse = User::factory()->create();
    grantAppointmentTriagePermissions($secondNurse);
    $patient = makePatient();

    $appointment = makeWaitingTriageAppointment($creator, $patient->id);

    $this->actingAs($firstNurse)
        ->patchJson('/api/v1/appointments/'.$appointment['id'].'/claim-triage')
        ->assertOk();

    $this->actingAs($secondNurse)
        ->patchJson('/api/v1/appointments/'.$appointment['id'].'/release-triage-claim')
        ->assertStatus(409)
        ->assertJsonPath('code', 'TRIAGE_CLAIM_CONFLICT');
});
