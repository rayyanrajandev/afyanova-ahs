<?php

/**
 * What happens to a patient when there is no drawer to take their money.
 *
 * CashierApiTest already proves the endpoint answers 409 CASHIER_SESSION_REQUIRED,
 * which is the cashier's half of the story. This is the patient's half, and it
 * was untested: a refused payment must leave the visit exactly where it was —
 * still awaiting payment, still visible at the counter, with no half-written
 * payment, allocation or receipt behind it.
 *
 * It matters most on the first morning the prepaid gate is switched on. From
 * that moment no patient can pass check-in until a cashier opens a drawer, so
 * "drawer not open yet" stops being an edge case and becomes the state the
 * whole hospital is in every day until someone logs in. If a failed attempt
 * corrupts anything, it corrupts it at the worst possible time.
 *
 * See reports/workspace-maturity/01-revenue-cashier.md.
 */

use App\Models\User;
use App\Modules\Appointment\Domain\ValueObjects\AppointmentStatus;
use App\Modules\Appointment\Infrastructure\Models\AppointmentModel;
use App\Modules\Reception\Application\UseCases\CheckInUseCase;
use App\Modules\Revenue\Application\UseCases\CloseCashierSessionUseCase;
use App\Modules\Revenue\Application\UseCases\ListCashierQueueUseCase;
use App\Modules\Revenue\Application\UseCases\OpenCashierSessionUseCase;
use App\Modules\Revenue\Domain\ValueObjects\ChargeSourceKind;
use App\Modules\Revenue\Domain\ValueObjects\ServiceChargeStatus;
use App\Modules\Revenue\Infrastructure\Models\ServiceChargeModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Revenue\RevenueTestSupport;

function drawerTestCashier(): User
{
    $roles = (array) config('roles');

    return makeUserWithRole((array) $roles['cashier']['permissions'], 'FINANCE.CASHIER');
}

/**
 * A patient booked, charged and checked in — waiting at the counter.
 *
 * @return array{patientId: string, appointmentId: string, chargeId: string}
 */
function patientWaitingAtCounter(): array
{
    RevenueTestSupport::configuredConsultationItem('15000.00');

    $patientId = RevenueTestSupport::registeredPatient();
    $appointment = RevenueTestSupport::bookConsultation($patientId);

    app(CheckInUseCase::class)->execute($appointment['id'], 'scheduled_checkin', null, null);

    $charge = ServiceChargeModel::query()
        ->where('source_workflow_kind', ChargeSourceKind::CONSULTATION->value)
        ->where('source_workflow_id', $appointment['id'])
        ->firstOrFail();

    return [
        'patientId' => $patientId,
        'appointmentId' => (string) $appointment['id'],
        'chargeId' => (string) $charge->id,
    ];
}

function payAtCounter(User $cashier, array $waiting, int $tenderedMinor = 1500000)
{
    return test()->actingAs($cashier)->postJson('/api/v1/cashier/payments', [
        'patientId' => $waiting['patientId'],
        'serviceChargeIds' => [$waiting['chargeId']],
        'tenderedAmountMinor' => $tenderedMinor,
        'idempotencyKey' => (string) Str::uuid(),
    ]);
}

it('leaves the patient exactly where they were when no drawer is open', function (): void {
    $waiting = patientWaitingAtCounter();

    payAtCounter(drawerTestCashier(), $waiting)
        ->assertStatus(409)
        ->assertJsonPath('code', 'CASHIER_SESSION_REQUIRED');

    // The visit has not moved, and has not been half-advanced.
    expect(AppointmentModel::query()->find($waiting['appointmentId'])->status)
        ->toBe(AppointmentStatus::AWAITING_PAYMENT->value);

    expect(ServiceChargeModel::query()->find($waiting['chargeId'])->status)
        ->toBe(ServiceChargeStatus::PENDING_PAYMENT);
});

it('writes nothing at all when the payment is refused for want of a drawer', function (): void {
    // A partially written payment or an orphan receipt number would corrupt the
    // day's reconciliation before the day had started.
    $waiting = patientWaitingAtCounter();

    payAtCounter(drawerTestCashier(), $waiting)->assertStatus(409);

    expect(DB::table('payments')->count())->toBe(0)
        ->and(DB::table('payment_allocations')->count())->toBe(0)
        ->and(DB::table('receipts')->count())->toBe(0);
});

it('keeps the patient visible at the counter so nobody loses them', function (): void {
    $waiting = patientWaitingAtCounter();

    payAtCounter(drawerTestCashier(), $waiting)->assertStatus(409);

    $row = collect(app(ListCashierQueueUseCase::class)->execute()['data'])
        ->firstWhere('patientId', $waiting['patientId']);

    expect($row)->not->toBeNull()
        ->and($row['amountDue'])->toBe('15000.00');
});

it('serves the same patient once a drawer is opened', function (): void {
    $cashier = drawerTestCashier();
    $waiting = patientWaitingAtCounter();

    payAtCounter($cashier, $waiting)->assertStatus(409);

    $this->actingAs($cashier)
        ->postJson('/api/v1/cashier/sessions', ['openingFloatMinor' => 5000000])
        ->assertCreated();

    payAtCounter($cashier, $waiting)->assertCreated();

    expect(AppointmentModel::query()->find($waiting['appointmentId'])->status)
        ->toBe(AppointmentStatus::WAITING_TRIAGE->value);

    expect(collect(app(ListCashierQueueUseCase::class)->execute()['data'])
        ->firstWhere('patientId', $waiting['patientId']))->toBeNull();
});

it('refuses the next patient cleanly once the drawer is closed for the day', function (): void {
    // End of shift, and someone arrives. The refusal must be the same
    // actionable 409, not an unhandled failure.
    $cashier = drawerTestCashier();

    $session = app(OpenCashierSessionUseCase::class)->execute($cashier->id, 5000000);
    app(CloseCashierSessionUseCase::class)->execute((string) $session->id, 5000000, $cashier->id);

    $waiting = patientWaitingAtCounter();

    payAtCounter($cashier, $waiting)
        ->assertStatus(409)
        ->assertJsonPath('code', 'CASHIER_SESSION_REQUIRED');

    expect(AppointmentModel::query()->find($waiting['appointmentId'])->status)
        ->toBe(AppointmentStatus::AWAITING_PAYMENT->value);
});

it('does not disturb a patient who was already paid for and let through', function (): void {
    $cashier = drawerTestCashier();
    $waiting = patientWaitingAtCounter();

    $session = app(OpenCashierSessionUseCase::class)->execute($cashier->id, 5000000);
    payAtCounter($cashier, $waiting)->assertCreated();

    app(CloseCashierSessionUseCase::class)->execute((string) $session->id, 6500000, $cashier->id);

    // Closing the drawer settles the day's cash; it must not reach back into a
    // visit that has already moved on.
    expect(AppointmentModel::query()->find($waiting['appointmentId'])->status)
        ->toBe(AppointmentStatus::WAITING_TRIAGE->value);

    expect(ServiceChargeModel::query()->find($waiting['chargeId'])->status)
        ->toBe(ServiceChargeStatus::AUTHORIZED);
});
