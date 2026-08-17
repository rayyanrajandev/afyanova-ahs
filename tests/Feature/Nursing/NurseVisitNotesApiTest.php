<?php

use App\Http\Middleware\EnforceTenantIsolationWhenEnabled;
use App\Http\Middleware\EnsureFacilitySubscriptionEntitlement;
use App\Http\Middleware\EnsureMappedFacilitySubscriptionEntitlement;
use App\Models\User;
use App\Modules\Appointment\Infrastructure\Models\AppointmentModel;
use App\Modules\Patient\Infrastructure\Models\PatientModel;
use App\Modules\Reception\Infrastructure\Models\ArrivalEventModel;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutMiddleware([
        PreventRequestForgery::class,
        EnsureMappedFacilitySubscriptionEntitlement::class,
        EnsureFacilitySubscriptionEntitlement::class,
        EnforceTenantIsolationWhenEnabled::class,
    ]);
});

function visitNoteUser(): User
{
    $user = User::factory()->create(['name' => 'Nurse Anna']);
    $user->givePermissionTo('patients.read');

    return $user;
}

it('fetches visit communication notes for an appointment', function (): void {
    $nurse = visitNoteUser();

    $patient = PatientModel::query()->create([
        'patient_number' => 'PT-VN-'.strtoupper(Str::random(6)),
        'first_name' => 'Neema',
        'last_name' => 'Joseph',
        'gender' => 'female',
        'date_of_birth' => '1993-11-05',
        'phone' => '+255755123456',
        'country_code' => 'TZ',
        'status' => 'active',
    ]);

    $appointment = AppointmentModel::query()->create([
        'appointment_number' => 'APT-VN-'.strtoupper(Str::random(6)),
        'patient_id' => $patient->id,
        'department' => 'Triage',
        'scheduled_at' => now(),
        'duration_minutes' => 30,
        'reason' => 'Routine',
        'status' => 'waiting_triage',
    ]);

    ArrivalEventModel::query()->create([
        'appointment_id' => $appointment->id,
        'arrival_mode' => 'walk_in',
        'arrived_at' => now(),
        'verification_notes' => 'Patient waiting for insurance clearance',
    ]);

    $response = $this->actingAs($nurse)->getJson("/api/v1/nursing/visit-notes/{$appointment->id}");

    $response->assertOk();
    $response->assertJsonPath('data.verificationNotes', 'Patient waiting for insurance clearance');
});

it('appends quick visit communication note to arrival event', function (): void {
    $nurse = visitNoteUser();

    $patient = PatientModel::query()->create([
        'patient_number' => 'PT-VN-'.strtoupper(Str::random(6)),
        'first_name' => 'Neema',
        'last_name' => 'Joseph',
        'gender' => 'female',
        'date_of_birth' => '1993-11-05',
        'phone' => '+255755123456',
        'country_code' => 'TZ',
        'status' => 'active',
    ]);

    $appointment = AppointmentModel::query()->create([
        'appointment_number' => 'APT-VN-'.strtoupper(Str::random(6)),
        'patient_id' => $patient->id,
        'department' => 'Triage',
        'scheduled_at' => now(),
        'duration_minutes' => 30,
        'reason' => 'Routine',
        'status' => 'waiting_triage',
    ]);

    ArrivalEventModel::query()->create([
        'appointment_id' => $appointment->id,
        'arrival_mode' => 'walk_in',
        'arrived_at' => now(),
        'verification_notes' => 'Patient waiting for insurance clearance',
    ]);

    $response = $this->actingAs($nurse)->postJson("/api/v1/nursing/visit-notes/{$appointment->id}", [
        'note' => 'Spouse arrived with payment receipt',
    ]);

    $response->assertOk();
    $response->assertJsonPath('data.verificationNotes', "Patient waiting for insurance clearance\n[".now()->format('H:i')." Nurse Anna]: Spouse arrived with payment receipt");
});

it('edits visit communication notes', function (): void {
    $nurse = visitNoteUser();

    $patient = PatientModel::query()->create([
        'patient_number' => 'PT-VN-'.strtoupper(Str::random(6)),
        'first_name' => 'Neema',
        'last_name' => 'Joseph',
        'gender' => 'female',
        'date_of_birth' => '1993-11-05',
        'phone' => '+255755123456',
        'country_code' => 'TZ',
        'status' => 'active',
    ]);

    $appointment = AppointmentModel::query()->create([
        'appointment_number' => 'APT-VN-'.strtoupper(Str::random(6)),
        'patient_id' => $patient->id,
        'department' => 'Triage',
        'scheduled_at' => now(),
        'duration_minutes' => 30,
        'reason' => 'Routine',
        'status' => 'waiting_triage',
    ]);

    ArrivalEventModel::query()->create([
        'appointment_id' => $appointment->id,
        'arrival_mode' => 'walk_in',
        'arrived_at' => now(),
        'verification_notes' => "Original Note\nSecond Note",
    ]);

    $response = $this->actingAs($nurse)->putJson("/api/v1/nursing/visit-notes/{$appointment->id}", [
        'verificationNotes' => "Edited First Note\nSecond Note",
    ]);

    $response->assertOk();
    $response->assertJsonPath('data.verificationNotes', "Edited First Note\nSecond Note");
});

it('deletes a specific visit communication note line by index', function (): void {
    $nurse = visitNoteUser();

    $patient = PatientModel::query()->create([
        'patient_number' => 'PT-VN-'.strtoupper(Str::random(6)),
        'first_name' => 'Neema',
        'last_name' => 'Joseph',
        'gender' => 'female',
        'date_of_birth' => '1993-11-05',
        'phone' => '+255755123456',
        'country_code' => 'TZ',
        'status' => 'active',
    ]);

    $appointment = AppointmentModel::query()->create([
        'appointment_number' => 'APT-VN-'.strtoupper(Str::random(6)),
        'patient_id' => $patient->id,
        'department' => 'Triage',
        'scheduled_at' => now(),
        'duration_minutes' => 30,
        'reason' => 'Routine',
        'status' => 'waiting_triage',
    ]);

    ArrivalEventModel::query()->create([
        'appointment_id' => $appointment->id,
        'arrival_mode' => 'walk_in',
        'arrived_at' => now(),
        'verification_notes' => "First Note\nSecond Note\nThird Note",
    ]);

    $response = $this->actingAs($nurse)->deleteJson("/api/v1/nursing/visit-notes/{$appointment->id}", [
        'index' => 1,
    ]);

    $response->assertOk();
    $response->assertJsonPath('data.verificationNotes', "First Note\nThird Note");
});
