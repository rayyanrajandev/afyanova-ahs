<?php

/*
|--------------------------------------------------------------------------
| Pharmacy records what it does.
|--------------------------------------------------------------------------
|
| The read side already worked — the resolver counts open pharmacy orders and
| answers `waiting_pharmacy` — but preparing and dispensing reached no timeline,
| so nobody outside the pharmacy could see who had done what.
|
| These exercise RecordPharmacyFlowTransitionService against the real resolver
| and the real flow writer. They deliberately do not drive the HTTP dispense
| endpoint: that path also resolves inventory units, allocates batches and
| enforces policy review, none of which is what this service decides. One
| end-to-end test below covers the wiring through the controller.
*/

use App\Models\Permission;
use App\Models\User;
use App\Modules\Appointment\Infrastructure\Models\AppointmentModel;
use App\Modules\Patient\Infrastructure\Models\PatientModel;
use App\Modules\PatientFlow\Infrastructure\Models\PatientFlowEventModel;
use App\Modules\Pharmacy\Application\Services\RecordPharmacyFlowTransitionService;
use App\Modules\Pharmacy\Infrastructure\Models\PharmacyOrderModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function pharmFlowUser(): User
{
    $user = User::factory()->create();

    foreach (['pharmacy.orders.read', 'medication.prescribe', 'medication.dispense'] as $ability) {
        Permission::query()->firstOrCreate(['name' => $ability]);
        $user->givePermissionTo($ability);
    }

    return $user;
}

function pharmFlowPatient(): PatientModel
{
    return PatientModel::query()->create([
        'patient_number' => 'PTPF'.strtoupper(Str::random(8)),
        'first_name' => 'Pharma',
        'last_name' => 'Flow',
        'gender' => 'female',
        'date_of_birth' => '1994-04-04',
        'phone' => '+2557'.random_int(10000000, 99999999),
        'country_code' => 'TZ',
        'status' => 'active',
    ]);
}

function pharmFlowAppointment(string $patientId): AppointmentModel
{
    return AppointmentModel::query()->create([
        'appointment_number' => 'APTPF'.strtoupper(Str::random(8)),
        'patient_id' => $patientId,
        'department' => 'Outpatient',
        'scheduled_at' => now()->subHour(),
        'duration_minutes' => 30,
        'reason' => 'Visit',
        'status' => 'waiting_provider',
        'consultation_started_at' => now()->subMinutes(30),
    ]);
}

function pharmFlowOrder(string $patientId, ?string $appointmentId, array $overrides = []): PharmacyOrderModel
{
    return PharmacyOrderModel::query()->create(array_merge([
        'order_number' => 'PHPF'.strtoupper(Str::random(8)),
        'patient_id' => $patientId,
        'appointment_id' => $appointmentId,
        'ordered_at' => now(),
        'medication_code' => 'ATC:J01CA04',
        'medication_name' => 'Amoxicillin 500mg',
        'dosage_instruction' => '1 tablet three times daily',
        'clinical_indication' => 'Bacterial infection',
        'quantity_prescribed' => 21,
        'quantity_dispensed' => 0,
        'prescribed_unit' => 'tablet',
        'dispensed_unit' => 'tablet',
        'status' => 'pending',
        'entry_state' => 'active',
        'formulary_decision_status' => 'approved',
    ], $overrides));
}

/**
 * @return Collection<int, PatientFlowEventModel>
 */
function pharmFlowEvents(string $appointmentId)
{
    return PatientFlowEventModel::query()
        ->where('appointment_id', $appointmentId)
        ->orderBy('occurred_at')
        ->orderBy('id')
        ->get();
}

function recordPharmFlow(PharmacyOrderModel $order, string $source, ?int $actorId, bool $complete = false): void
{
    app(RecordPharmacyFlowTransitionService::class)->recordForOrder(
        order: $order->fresh()->toArray(),
        source: $source,
        actorId: $actorId,
        isDispenseComplete: $complete,
    );
}

it('records preparation without moving the patient', function (): void {
    $user = pharmFlowUser();
    $patient = pharmFlowPatient();
    $appointment = pharmFlowAppointment($patient->id);
    $order = pharmFlowOrder($patient->id, $appointment->id, ['status' => 'in_preparation']);

    recordPharmFlow($order, 'pharmacy.preparation_started', $user->id);

    $event = pharmFlowEvents($appointment->id)->last();

    expect($event)->not->toBeNull();
    expect($event->source)->toBe('pharmacy.preparation_started');
    expect($event->to_step)->toBe('waiting_pharmacy');
    expect($event->actor_user_id)->toBe($user->id);
});

it('keeps the patient waiting through a partial fill', function (): void {
    // partially_dispensed is still in openWorklistValues(), so the visit is
    // still held — the patient is owed medicine and cannot be released. Neither
    // diagnostic module has a half-finished state like this.
    $user = pharmFlowUser();
    $patient = pharmFlowPatient();
    $appointment = pharmFlowAppointment($patient->id);
    $order = pharmFlowOrder($patient->id, $appointment->id, [
        'status' => 'partially_dispensed',
        'quantity_dispensed' => 10,
    ]);

    recordPharmFlow($order, 'pharmacy.partially_dispensed', $user->id);

    $event = pharmFlowEvents($appointment->id)->last();

    expect($event->source)->toBe('pharmacy.partially_dispensed');
    expect($event->to_step)->toBe('waiting_pharmacy');
});

it('hands the patient back to reception once everything is dispensed', function (): void {
    $user = pharmFlowUser();
    $patient = pharmFlowPatient();
    $appointment = pharmFlowAppointment($patient->id);
    $order = pharmFlowOrder($patient->id, $appointment->id, [
        'status' => 'dispensed',
        'quantity_dispensed' => 21,
    ]);

    recordPharmFlow($order, 'pharmacy.dispensed', $user->id, complete: true);

    $event = pharmFlowEvents($appointment->id)->last();

    // Not waiting_clinician_review: nothing clinical is outstanding. The patient
    // has what they came for and is going to the front, not back to the doctor.
    expect($event->source)->toBe('pharmacy.dispensed');
    expect($event->to_step)->toBe('returned_to_reception');
});

it('never declares the visit completed, and never puts the patient with a clinician', function (): void {
    $user = pharmFlowUser();
    $patient = pharmFlowPatient();
    $appointment = pharmFlowAppointment($patient->id);
    $order = pharmFlowOrder($patient->id, $appointment->id, [
        'status' => 'dispensed',
        'quantity_dispensed' => 21,
    ]);

    recordPharmFlow($order, 'pharmacy.dispensed', $user->id, complete: true);

    // Ending a visit is reception's call, not pharmacy's — a patient may still
    // owe a payment or need a follow-up booked.
    expect(pharmFlowEvents($appointment->id)->pluck('to_step')->all())
        ->not->toContain('completed')
        ->not->toContain('with_clinician');
});

it('does not release the visit while another prescription is still open', function (): void {
    $user = pharmFlowUser();
    $patient = pharmFlowPatient();
    $appointment = pharmFlowAppointment($patient->id);
    $first = pharmFlowOrder($patient->id, $appointment->id, [
        'status' => 'dispensed',
        'quantity_dispensed' => 21,
    ]);
    pharmFlowOrder($patient->id, $appointment->id, [
        'medication_code' => 'ATC:M01AE01',
        'medication_name' => 'Ibuprofen 400mg',
    ]);

    recordPharmFlow($first, 'pharmacy.dispensed', $user->id, complete: true);

    $event = pharmFlowEvents($appointment->id)->last();

    expect($event->to_step)->toBe('waiting_pharmacy');
    expect($event->to_step)->not->toBe('returned_to_reception');
});

it('records nothing for an over-the-counter sale with no appointment', function (): void {
    $user = pharmFlowUser();
    $patient = pharmFlowPatient();
    $order = pharmFlowOrder($patient->id, null, ['status' => 'in_preparation']);

    recordPharmFlow($order, 'pharmacy.preparation_started', $user->id);

    expect(PatientFlowEventModel::query()->where('patient_id', $patient->id)->count())->toBe(0);
});

it('makes no claim when an order leaves the worklist without being dispensed', function (): void {
    // A cancelled order stops holding the visit, but pharmacy has no basis to
    // say where the patient goes next.
    $user = pharmFlowUser();
    $patient = pharmFlowPatient();
    $appointment = pharmFlowAppointment($patient->id);
    $order = pharmFlowOrder($patient->id, $appointment->id, ['status' => 'cancelled']);

    recordPharmFlow($order, 'pharmacy.cancelled', $user->id);

    expect(pharmFlowEvents($appointment->id))->toHaveCount(0);
});

it('wires the service into the status endpoint', function (): void {
    // One end-to-end pass so the controller wiring is covered, using the
    // transition that needs no stock resolution.
    $user = pharmFlowUser();
    $patient = pharmFlowPatient();
    $appointment = pharmFlowAppointment($patient->id);
    $order = pharmFlowOrder($patient->id, $appointment->id);

    test()->actingAs($user)
        ->patchJson('/api/v1/pharmacy-orders/'.$order->id.'/status', [
            'status' => 'in_preparation',
            'dispensingNotes' => 'Prepared for dispensing.',
        ])
        ->assertOk();

    $event = pharmFlowEvents($appointment->id)->last();

    expect($event)->not->toBeNull();
    expect($event->source)->toBe('pharmacy.preparation_started');
    expect($event->to_step)->toBe('waiting_pharmacy');
});
