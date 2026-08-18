<?php

/**
 * The pharmacy workspace gets what its header renders.
 *
 * PharmacyOrderHeader shows the patient's name, MRN, sex, age and phone, the
 * ordering clinician, and where the patient stands in the visit. None of that is
 * on the pharmacy_orders table — it is attached by enrichers on the way out. If
 * one is missing the header still renders, quietly, with "Patient", "MRN-0000"
 * and em dashes, which is exactly what a missing enricher looks like from the
 * screen.
 *
 * `visitStage` was in fact missing from both endpoints, so the shared stage
 * badge could never appear on a pharmacy row.
 */

use App\Models\Permission;
use App\Models\User;
use App\Modules\Appointment\Infrastructure\Models\AppointmentModel;
use App\Modules\Patient\Infrastructure\Models\PatientModel;
use App\Modules\Pharmacy\Infrastructure\Models\PharmacyOrderModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function workspacePayloadUser(): User
{
    $user = User::factory()->create();

    foreach (['pharmacy.orders.read', 'medication.prescribe', 'medication.dispense'] as $ability) {
        Permission::query()->firstOrCreate(['name' => $ability]);
        $user->givePermissionTo($ability);
    }

    return $user;
}

function workspacePayloadPatient(): PatientModel
{
    return PatientModel::query()->create([
        'patient_number' => 'PTWP'.strtoupper(Str::random(8)),
        'first_name' => 'Neema',
        'middle_name' => 'A',
        'last_name' => 'Kweka',
        'gender' => 'female',
        'date_of_birth' => '1992-03-11',
        'phone' => '+255700000123',
        'country_code' => 'TZ',
        'status' => 'active',
    ]);
}

function workspacePayloadOrder(string $patientId, ?string $appointmentId, ?int $orderedBy): PharmacyOrderModel
{
    return PharmacyOrderModel::query()->create([
        'order_number' => 'PHWP'.strtoupper(Str::random(8)),
        'patient_id' => $patientId,
        'appointment_id' => $appointmentId,
        'ordered_by_user_id' => $orderedBy,
        'ordered_at' => now(),
        'medication_code' => 'ATC:J01CA04',
        'medication_name' => 'Amoxicillin 500mg',
        'dosage_instruction' => '1 tablet three times daily',
        'clinical_indication' => 'Bacterial infection',
        'quantity_prescribed' => 21,
        'quantity_dispensed' => 0,
        'prescribed_unit' => 'tablet',
        'status' => 'pending',
        'entry_state' => 'active',
        'formulary_decision_status' => 'approved',
    ]);
}

it('returns the patient block the header renders', function (): void {
    $user = workspacePayloadUser();
    $patient = workspacePayloadPatient();
    workspacePayloadOrder($patient->id, null, $user->id);

    $row = $this->actingAs($user)
        ->getJson('/api/v1/pharmacy/orders')
        ->assertOk()
        ->json('data.0');

    expect($row)->toHaveKey('patient');
    expect($row['patient']['name'])->toBe('Neema A Kweka');
    expect($row['patient']['patientNumber'])->toBe($patient->patient_number);
    expect($row['patient']['gender'])->toBe('Female');
    // Age and phone are shown in the header beside the MRN.
    expect($row['patient']['age'])->not->toBeNull();
    expect($row['patient']['phone'])->toBe('+255700000123');
});

it('returns the prescriber under the key the enricher actually uses', function (): void {
    $user = workspacePayloadUser();
    $patient = workspacePayloadPatient();
    workspacePayloadOrder($patient->id, null, $user->id);

    $row = $this->actingAs($user)
        ->getJson('/api/v1/pharmacy/orders')
        ->assertOk()
        ->json('data.0');

    // `orderedBy`, not `orderingClinician`. The composables read the latter and
    // therefore always showed a placeholder prescriber.
    expect($row)->toHaveKey('orderedBy');
    expect($row['orderedBy']['name'] ?? null)->not->toBeNull();
});

it('returns visitStage so the shared stage badge can render', function (): void {
    $user = workspacePayloadUser();
    $patient = workspacePayloadPatient();
    $appointment = AppointmentModel::query()->create([
        'appointment_number' => 'APTWP'.strtoupper(Str::random(8)),
        'patient_id' => $patient->id,
        'department' => 'Outpatient',
        'scheduled_at' => now()->subHour(),
        'duration_minutes' => 30,
        'reason' => 'Visit',
        'status' => 'in_consultation',
        'consultation_started_at' => now()->subMinutes(20),
    ]);
    workspacePayloadOrder($patient->id, $appointment->id, $user->id);

    $row = $this->actingAs($user)
        ->getJson('/api/v1/pharmacy/orders')
        ->assertOk()
        ->json('data.0');

    expect($row)->toHaveKey('visitStage');
    expect($row['visitStage'])->not->toBeNull();
});

it('carries the same blocks on the single-order endpoint the header reads after selection', function (): void {
    $user = workspacePayloadUser();
    $patient = workspacePayloadPatient();
    $appointment = AppointmentModel::query()->create([
        'appointment_number' => 'APTWP'.strtoupper(Str::random(8)),
        'patient_id' => $patient->id,
        'department' => 'Outpatient',
        'scheduled_at' => now()->subHour(),
        'duration_minutes' => 30,
        'reason' => 'Visit',
        'status' => 'in_consultation',
        'consultation_started_at' => now()->subMinutes(20),
    ]);
    $order = workspacePayloadOrder($patient->id, $appointment->id, $user->id);

    $row = $this->actingAs($user)
        ->getJson('/api/v1/pharmacy/orders/'.$order->id)
        ->assertOk()
        ->json('data');

    expect($row['patient']['name'])->toBe('Neema A Kweka');
    expect($row['visitStage'])->not->toBeNull();
    expect($row['orderedBy']['name'] ?? null)->not->toBeNull();
});
