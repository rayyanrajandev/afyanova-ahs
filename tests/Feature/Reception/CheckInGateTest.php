<?php

use App\Models\User;
use App\Modules\Appointment\Domain\ValueObjects\AppointmentStatus;
use App\Modules\Appointment\Infrastructure\Models\AppointmentModel;
use App\Modules\Reception\Application\UseCases\CheckInUseCase;
use App\Modules\Revenue\Application\UseCases\OpenCashierSessionUseCase;
use App\Modules\Revenue\Application\UseCases\RaiseServiceChargeUseCase;
use App\Modules\Revenue\Application\UseCases\RecordCashPaymentUseCase;
use App\Modules\Revenue\Application\UseCases\WaiveServiceChargeUseCase;
use App\Modules\Revenue\Domain\ValueObjects\AuthorizationBasis;
use App\Modules\Revenue\Domain\ValueObjects\ChargeSourceKind;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Revenue\RevenueTestSupport;

/**
 * The gate as reception actually meets it.
 *
 * Arrival is recorded either way — a patient standing at the cashier must be
 * visible to the desk that sent them there — but only a cleared charge puts
 * them in the clinical queue.
 */
function receptionistId(): int
{
    // arrival_events.recorded_by_user_id is a real foreign key, so the actor
    // has to exist.
    return (int) User::factory()->create()->id;
}

function seedPatientAndAppointment(): array
{
    $patientId = (string) Str::uuid();

    DB::table('patients')->insert([
        'id' => $patientId,
        'patient_number' => 'PT-'.Str::upper(Str::random(8)),
        'first_name' => 'Test',
        'last_name' => 'Patient',
        'gender' => 'female',
        'date_of_birth' => '1990-01-01',
        'country_code' => 'TZ',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $appointment = AppointmentModel::query()->create([
        'appointment_number' => 'APT-'.Str::upper(Str::random(8)),
        'patient_id' => $patientId,
        'department' => 'General',
        'scheduled_at' => now(),
        'status' => AppointmentStatus::SCHEDULED->value,
        'consultation_type' => 'new',
        'financial_coverage_type' => 'self_pay',
    ]);

    return [$patientId, (string) $appointment->id];
}

function raiseConsultationCharge(string $patientId, string $appointmentId, string $price = '15000.00')
{
    $item = RevenueTestSupport::pricedItem('CONSULT-CI-'.Str::upper(Str::random(5)), $price);

    return app(RaiseServiceChargeUseCase::class)->execute(
        patientId: $patientId,
        sourceKind: ChargeSourceKind::CONSULTATION,
        sourceId: $appointmentId,
        chargeableItemId: $item['chargeableItemId'],
        description: 'General outpatient consultation',
        appointmentId: $appointmentId,
    );
}

it('holds an unpaid arrival at the cashier instead of the clinical queue', function (): void {
    [$patientId, $appointmentId] = seedPatientAndAppointment();
    raiseConsultationCharge($patientId, $appointmentId);

    $result = app(CheckInUseCase::class)->execute(
        appointmentId: $appointmentId,
        arrivalMode: 'scheduled_checkin',
        verificationNotes: null,
        actorId: receptionistId(),
    );

    expect($result['status'])->toBe(AppointmentStatus::AWAITING_PAYMENT->value);

    // Arrival is still a recorded fact — that is the point of not refusing.
    expect(DB::table('arrival_events')->where('appointment_id', $appointmentId)->exists())->toBeTrue();
});

it('sends a paid arrival straight to triage', function (): void {
    [$patientId, $appointmentId] = seedPatientAndAppointment();
    $charge = raiseConsultationCharge($patientId, $appointmentId);

    app(OpenCashierSessionUseCase::class)->execute(910, 5000000);
    app(RecordCashPaymentUseCase::class)->execute(
        patientId: $patientId,
        serviceChargeIds: [(string) $charge->id],
        tenderedAmountMinor: 1500000,
        idempotencyKey: (string) Str::uuid(),
        cashierUserId: 910,
    );

    $result = app(CheckInUseCase::class)->execute(
        appointmentId: $appointmentId,
        arrivalMode: 'scheduled_checkin',
        verificationNotes: null,
        actorId: receptionistId(),
    );

    expect($result['status'])->toBe(AppointmentStatus::WAITING_TRIAGE->value);
});

it('promotes a waiting patient the moment their payment clears', function (): void {
    [$patientId, $appointmentId] = seedPatientAndAppointment();
    $charge = raiseConsultationCharge($patientId, $appointmentId);

    app(CheckInUseCase::class)->execute($appointmentId, 'scheduled_checkin', null, receptionistId());

    expect(AppointmentModel::query()->find($appointmentId)->status)
        ->toBe(AppointmentStatus::AWAITING_PAYMENT->value);

    app(OpenCashierSessionUseCase::class)->execute(911, 5000000);
    app(RecordCashPaymentUseCase::class)->execute(
        patientId: $patientId,
        serviceChargeIds: [(string) $charge->id],
        tenderedAmountMinor: 1500000,
        idempotencyKey: (string) Str::uuid(),
        cashierUserId: 911,
    );

    // No second check-in needed: the cashier knows the money arrived, and the
    // queue moves on its own.
    expect(AppointmentModel::query()->find($appointmentId)->status)
        ->toBe(AppointmentStatus::WAITING_TRIAGE->value);
});

it('promotes a waiting patient on an emergency override too', function (): void {
    [$patientId, $appointmentId] = seedPatientAndAppointment();
    $charge = raiseConsultationCharge($patientId, $appointmentId);

    app(CheckInUseCase::class)->execute($appointmentId, 'scheduled_checkin', null, receptionistId());

    app(WaiveServiceChargeUseCase::class)->execute(
        serviceChargeId: (string) $charge->id,
        basis: AuthorizationBasis::EMERGENCY,
        reason: 'Deteriorating in the queue',
        approvedByUserId: 777,
    );

    expect(AppointmentModel::query()->find($appointmentId)->status)
        ->toBe(AppointmentStatus::WAITING_TRIAGE->value);
});

it('does not gate a visit that was never charged', function (): void {
    // Nothing raised a charge — a walk-in booked before the gate was switched
    // on, or a service outside the prepaid rule. It must not be stranded.
    [, $appointmentId] = seedPatientAndAppointment();

    $result = app(CheckInUseCase::class)->execute($appointmentId, 'scheduled_checkin', null, receptionistId());

    expect($result['status'])->toBe(AppointmentStatus::WAITING_TRIAGE->value);
});

it('leaves a scheduled visit alone when its charge clears before arrival', function (): void {
    [$patientId, $appointmentId] = seedPatientAndAppointment();
    $charge = raiseConsultationCharge($patientId, $appointmentId);

    app(OpenCashierSessionUseCase::class)->execute(912, 5000000);
    app(RecordCashPaymentUseCase::class)->execute(
        patientId: $patientId,
        serviceChargeIds: [(string) $charge->id],
        tenderedAmountMinor: 1500000,
        idempotencyKey: (string) Str::uuid(),
        cashierUserId: 912,
    );

    // Paying early must not check the patient in on their behalf.
    expect(AppointmentModel::query()->find($appointmentId)->status)
        ->toBe(AppointmentStatus::SCHEDULED->value);
});
