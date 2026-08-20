<?php

/**
 * The whole consultation journey, through the doors a real visit uses.
 *
 * Two gaps this closes, both found on 2026-08-19 while answering a live
 * question — a patient had been checked in, triaged and passed to the doctor
 * without paying.
 *
 * 1. **The trigger was never tested.** Every existing test raises its
 *    consultation charge by calling RaiseServiceChargeUseCase directly, against
 *    a fabricated code (`'CONSULT-CI-'.Str::random(5)`). Nothing asserted that
 *    *booking an appointment* raises one, which is where it actually happens —
 *    so the charge machinery was thoroughly covered while the thing that fires
 *    it was not.
 *
 * 2. **The cashier queue was never asserted in this journey.** Reception's
 *    tests prove an unpaid arrival lands in AWAITING_PAYMENT, and Revenue's
 *    prove payment promotes it. Neither checks the patient is actually visible
 *    to the cashier in between — which, from the counter's point of view, is
 *    the only thing that matters.
 *
 * Deliberately uses the *configured* item code rather than a fabricated one, so
 * this fails if config and catalogue ever part company again.
 *
 * See reports/workspace-maturity/01-revenue-cashier.md, goal G5.
 */

use App\Modules\Appointment\Application\Exceptions\ActiveAppointmentConflictException;
use App\Modules\Appointment\Domain\Repositories\AppointmentRepositoryInterface;
use App\Modules\Appointment\Domain\ValueObjects\AppointmentStatus;
use App\Modules\Appointment\Infrastructure\Models\AppointmentModel;
use App\Modules\Reception\Application\UseCases\CheckInUseCase;
use App\Modules\Reception\Application\UseCases\RegisterWalkInAndCheckInUseCase;
use App\Modules\Revenue\Application\UseCases\ListCashierQueueUseCase;
use App\Modules\Revenue\Application\UseCases\OpenCashierSessionUseCase;
use App\Modules\Revenue\Application\UseCases\RecordCashPaymentUseCase;
use App\Modules\Revenue\Domain\ValueObjects\ChargeSourceKind;
use App\Modules\Revenue\Domain\ValueObjects\ServiceChargeStatus;
use App\Modules\Revenue\Infrastructure\Models\ServiceChargeModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Nursing\NursingTestSupport;
use Tests\Feature\Revenue\RevenueTestSupport;

function cashierQueueRowFor(string $patientId): ?array
{
    $queue = app(ListCashierQueueUseCase::class)->execute();

    return collect($queue['data'])->firstWhere('patientId', $patientId);
}

it('raises a consultation charge when the visit is booked, using the configured item code', function (): void {
    // The trigger, exercised through the real booking path rather than by
    // calling the raiser directly.
    RevenueTestSupport::configuredConsultationItem('15000.00');

    $patientId = RevenueTestSupport::registeredPatient();
    $appointment = RevenueTestSupport::bookConsultation($patientId);

    $charge = ServiceChargeModel::query()
        ->where('source_workflow_kind', ChargeSourceKind::CONSULTATION->value)
        ->where('source_workflow_id', $appointment['id'])
        ->first();

    expect($charge)->not->toBeNull()
        ->and($charge->status)->toBe(ServiceChargeStatus::PENDING_PAYMENT)
        ->and($charge->netAmount()->toDecimalString())->toBe('15000.00');
});

it('leaves the visit uncharged, and the gate open, when the configured item is missing', function (): void {
    // No item seeded — the exact production condition that went unnoticed. The
    // patient must still be bookable; fail-open is the contract.
    $patientId = RevenueTestSupport::registeredPatient();
    $appointment = RevenueTestSupport::bookConsultation($patientId);

    expect($appointment['id'])->not->toBeNull()
        ->and(ServiceChargeModel::query()
            ->where('source_workflow_id', $appointment['id'])
            ->exists())->toBeFalse();

    $result = app(CheckInUseCase::class)->execute($appointment['id'], 'scheduled_checkin', null, null);

    // Nothing to pay for, so nothing to hold them for.
    expect($result['status'])->toBe(AppointmentStatus::WAITING_TRIAGE->value);
});

it('walks a patient from booking to the counter and back into the clinical queue', function (): void {
    RevenueTestSupport::configuredConsultationItem('15000.00');

    $patientId = RevenueTestSupport::registeredPatient();
    $appointment = RevenueTestSupport::bookConsultation($patientId);

    // 1. Arrive — held at the cashier, but the arrival is still recorded.
    $checkedIn = app(CheckInUseCase::class)->execute($appointment['id'], 'scheduled_checkin', null, null);

    expect($checkedIn['status'])->toBe(AppointmentStatus::AWAITING_PAYMENT->value)
        ->and(DB::table('arrival_events')->where('appointment_id', $appointment['id'])->exists())->toBeTrue();

    // 2. Visible to the cashier, for the right amount. Never asserted before.
    $row = cashierQueueRowFor($patientId);

    expect($row)->not->toBeNull()
        ->and($row['amountDue'])->toBe('15000.00')
        ->and($row['chargeCount'])->toBe(1);

    // 3. Pay at the counter.
    $charge = ServiceChargeModel::query()
        ->where('source_workflow_id', $appointment['id'])
        ->firstOrFail();

    app(OpenCashierSessionUseCase::class)->execute(4242, 5000000);
    app(RecordCashPaymentUseCase::class)->execute(
        patientId: $patientId,
        serviceChargeIds: [(string) $charge->id],
        tenderedAmountMinor: 1500000,
        idempotencyKey: (string) Str::uuid(),
        cashierUserId: 4242,
    );

    // 4. The queue moves on its own — no second check-in.
    expect(AppointmentModel::query()->find($appointment['id'])->status)
        ->toBe(AppointmentStatus::WAITING_TRIAGE->value);

    // 5. And the patient is off the counter's list. Also never asserted before:
    //    a cashier must not keep seeing someone they have already served.
    expect(cashierQueueRowFor($patientId))->toBeNull();
});

it('keeps the patient at the counter until the money actually clears', function (): void {
    RevenueTestSupport::configuredConsultationItem('15000.00');

    $patientId = RevenueTestSupport::registeredPatient();
    $appointment = RevenueTestSupport::bookConsultation($patientId);

    app(CheckInUseCase::class)->execute($appointment['id'], 'scheduled_checkin', null, null);

    // Opening a drawer is not payment.
    app(OpenCashierSessionUseCase::class)->execute(4243, 5000000);

    expect(cashierQueueRowFor($patientId))->not->toBeNull()
        ->and(AppointmentModel::query()->find($appointment['id'])->status)
        ->toBe(AppointmentStatus::AWAITING_PAYMENT->value);
});

/*
|--------------------------------------------------------------------------
| A patient at the cashier is still a patient who has arrived
|--------------------------------------------------------------------------
|
| Regression guard, 2026-08-19. AWAITING_PAYMENT was added by the prepaid
| model but never added to the status list that answers "has this patient
| already arrived and not yet been resolved". One omission, three failures:
| the Reception profile crashed on a checked-in patient, the duplicate-arrival
| guard could not see them, and Check-In could not be pre-emptively disabled.
|
| Reported from a live environment as a Vue TypeError — the visible symptom of
| a backend query that had gone quiet.
|
*/

it('reports an unpaid arrival as the patient active appointment', function (): void {
    // Drives the Reception patient profile. When this returned null while the
    // encounter was open, the profile fell into a branch that assumed an
    // appointment existed and crashed on `activeAppointment.department`.
    RevenueTestSupport::configuredConsultationItem('15000.00');

    $patientId = RevenueTestSupport::registeredPatient();
    $appointment = RevenueTestSupport::bookConsultation($patientId);

    app(CheckInUseCase::class)->execute($appointment['id'], 'walk_in', null, null);

    $active = app(AppointmentRepositoryInterface::class)
        ->findActiveForPatient($patientId);

    expect($active)->not->toBeNull()
        ->and($active['id'])->toBe($appointment['id'])
        ->and($active['status'])->toBe(AppointmentStatus::AWAITING_PAYMENT->value);
});

it('refuses to check the same patient in twice while they wait at the cashier', function (): void {
    // The costlier half of the same omission: an unseen arrival could be
    // registered again, producing a second visit and a second consultation
    // charge for one person standing in one queue.
    RevenueTestSupport::configuredConsultationItem('15000.00');

    $patientId = RevenueTestSupport::registeredPatient();
    $appointment = RevenueTestSupport::bookConsultation($patientId);

    app(CheckInUseCase::class)->execute($appointment['id'], 'walk_in', null, null);

    expect(fn () => app(RegisterWalkInAndCheckInUseCase::class)
        ->execute($patientId, 'walk_in', null, null))
        ->toThrow(ActiveAppointmentConflictException::class);

    // And no second charge was raised for them.
    expect(ServiceChargeModel::query()
        ->where('source_workflow_kind', ChargeSourceKind::CONSULTATION->value)
        ->whereIn('source_workflow_id', AppointmentModel::query()->where('patient_id', $patientId)->pluck('id'))
        ->count())->toBe(1);
});

it('counts every arrived-and-unresolved status, so a new one cannot be forgotten', function (): void {
    expect(AppointmentStatus::arrivedAndUnresolved())->toBe([
        AppointmentStatus::AWAITING_PAYMENT->value,
        AppointmentStatus::WAITING_TRIAGE->value,
        AppointmentStatus::WAITING_PROVIDER->value,
        AppointmentStatus::IN_CONSULTATION->value,
    ]);

    // A booking is not an arrival.
    expect(AppointmentStatus::arrivedAndUnresolved())
        ->not->toContain(AppointmentStatus::SCHEDULED->value);
});

it('finishes the triage handoff that payment was holding up', function (): void {
    // Reported from a live counter. A nurse took observations while the visit
    // was still unpaid — correctly allowed, and correctly not advanced. The
    // charge then cleared and the visit was promoted to waiting_triage... and
    // stopped there, with vitals already on file, while every queue kept asking
    // for the vitals that had already been taken. Nothing resumed the deferred
    // handoff.
    RevenueTestSupport::configuredConsultationItem('15000.00');

    $patientId = RevenueTestSupport::registeredPatient();
    $appointment = RevenueTestSupport::bookConsultation($patientId);
    app(CheckInUseCase::class)->execute($appointment['id'], 'walk_in', null, null);

    $nurse = NursingTestSupport::nurse();

    // Observations, taken before the patient has paid.
    test()->actingAs($nurse)->postJson('/api/v1/nursing/vitals', [
        'patientId' => $patientId,
        'temperatureC' => 36.8,
        'systolicBpMmhg' => 120,
        'diastolicBpMmhg' => 80,
        'heartRateBpm' => 78,
    ])->assertCreated();

    // Held at the counter, as it should be.
    expect(AppointmentModel::query()->find($appointment['id'])->status)
        ->toBe(AppointmentStatus::AWAITING_PAYMENT->value);

    $charge = ServiceChargeModel::query()->where('source_workflow_id', $appointment['id'])->firstOrFail();

    $cashier = makeUserWithRole([], 'FINANCE.CASHIER');

    app(OpenCashierSessionUseCase::class)->execute($cashier->id, 5000000);
    app(RecordCashPaymentUseCase::class)->execute(
        patientId: $patientId,
        serviceChargeIds: [(string) $charge->id],
        tenderedAmountMinor: 1500000,
        idempotencyKey: (string) Str::uuid(),
        cashierUserId: $cashier->id,
    );

    $settled = AppointmentModel::query()->find($appointment['id']);

    // Payment resumes the handoff rather than stranding the visit in triage.
    expect($settled->status)->toBe(AppointmentStatus::WAITING_PROVIDER->value)
        ->and($settled->triaged_at)->not->toBeNull()
        ->and($settled->triage_vitals_summary)->toContain('BP: 120/80 mmHg');
});

it('leaves a paid visit in triage when nobody has taken observations yet', function (): void {
    // The ordinary case must not change: payment alone is not triage.
    RevenueTestSupport::configuredConsultationItem('15000.00');

    $patientId = RevenueTestSupport::registeredPatient();
    $appointment = RevenueTestSupport::bookConsultation($patientId);
    app(CheckInUseCase::class)->execute($appointment['id'], 'walk_in', null, null);

    $charge = ServiceChargeModel::query()->where('source_workflow_id', $appointment['id'])->firstOrFail();

    app(OpenCashierSessionUseCase::class)->execute(7712, 5000000);
    app(RecordCashPaymentUseCase::class)->execute(
        patientId: $patientId,
        serviceChargeIds: [(string) $charge->id],
        tenderedAmountMinor: 1500000,
        idempotencyKey: (string) Str::uuid(),
        cashierUserId: 7712,
    );

    expect(AppointmentModel::query()->find($appointment['id'])->status)
        ->toBe(AppointmentStatus::WAITING_TRIAGE->value);
});
