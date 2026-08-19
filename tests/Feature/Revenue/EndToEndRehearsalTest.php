<?php

use App\Models\User;
use App\Modules\Appointment\Domain\ValueObjects\AppointmentStatus;
use App\Modules\Appointment\Infrastructure\Models\AppointmentModel;
use App\Modules\Reception\Application\UseCases\CheckInUseCase;
use App\Modules\Revenue\Application\UseCases\RaiseServiceChargeUseCase;
use App\Modules\Revenue\Domain\Services\ServiceAuthorizationReaderInterface;
use App\Modules\Revenue\Domain\ValueObjects\ChargeSourceKind;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Revenue\RevenueTestSupport;

/**
 * One day at the counter, start to finish.
 *
 * Register a patient, raise the consultation charge, watch them be held at the
 * cashier, take the money, watch them released into triage, then bank some
 * cash, correct a mistake, and close the drawer against a blind count that
 * balances.
 *
 * The point is not any single step — each has its own test — but that the
 * numbers still agree after a realistic sequence of them. The retired design
 * could not make that claim: two ledgers with one day close reported two
 * different totals for the same day.
 */
function rehearsalRole(string $key): User
{
    $roles = (array) config('roles');

    return makeUserWithRole((array) $roles[$key]['permissions'], (string) $roles[$key]['code']);
}

function rehearsalPatient(string $firstName): string
{
    $id = (string) Str::uuid();

    DB::table('patients')->insert([
        'id' => $id,
        'patient_number' => 'PT-'.Str::upper(Str::random(8)),
        'first_name' => $firstName, 'last_name' => 'Rehearsal',
        'gender' => 'female', 'date_of_birth' => '1991-07-14',
        'country_code' => 'TZ', 'status' => 'active',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return $id;
}

it('runs a full counter day and still reconciles', function (): void {
    $cashier = rehearsalRole('cashier');
    $supervisor = rehearsalRole('finance-manager');
    $receptionist = rehearsalRole('receptionist');

    // ---- Open the drawer with a 50,000 float ---------------------------
    $sessionId = $this->actingAs($cashier)
        ->postJson('/api/v1/cashier/sessions', ['openingFloatMinor' => 5000000])
        ->assertCreated()
        ->json('data.id');

    $item = RevenueTestSupport::pricedItem('CONSULT-REHEARSAL', '15000.00');
    $takings = 0;

    // ---- Four patients register, are charged, and are held at the counter
    $appointments = [];

    foreach (['Amina', 'Baraka', 'Chausiku', 'Daudi'] as $name) {
        $patientId = rehearsalPatient($name);

        $appointment = AppointmentModel::query()->create([
            'appointment_number' => 'APT-'.Str::upper(Str::random(8)),
            'patient_id' => $patientId,
            'department' => 'General',
            'scheduled_at' => now(),
            'status' => AppointmentStatus::SCHEDULED->value,
            'consultation_type' => 'new',
            'financial_coverage_type' => 'self_pay',
        ]);

        $charge = app(RaiseServiceChargeUseCase::class)->execute(
            patientId: $patientId,
            sourceKind: ChargeSourceKind::CONSULTATION,
            sourceId: (string) $appointment->id,
            chargeableItemId: $item['chargeableItemId'],
            description: 'General outpatient consultation',
            appointmentId: (string) $appointment->id,
        );

        // Arrival is recorded, but the gate holds them at the cashier.
        app(CheckInUseCase::class)->execute(
            (string) $appointment->id, 'scheduled_checkin', null, (int) $receptionist->id,
        );

        expect(AppointmentModel::query()->find($appointment->id)->status)
            ->toBe(AppointmentStatus::AWAITING_PAYMENT->value);

        $appointments[$name] = ['appointment' => $appointment, 'charge' => $charge, 'patient' => $patientId];
    }

    // ---- The cashier serves three of them --------------------------------
    foreach (['Amina', 'Baraka', 'Chausiku'] as $name) {
        $row = $appointments[$name];

        $this->actingAs($cashier)
            ->postJson('/api/v1/cashier/payments', [
                'patientId' => $row['patient'],
                'serviceChargeIds' => [(string) $row['charge']->id],
                // Amina hands over 20,000; the rest pay exactly.
                'tenderedAmountMinor' => $name === 'Amina' ? 2000000 : 1500000,
                'idempotencyKey' => (string) Str::uuid(),
            ])
            ->assertCreated();

        $takings += 1500000;

        // Paying releases them into triage without reception touching anything.
        expect(AppointmentModel::query()->find($row['appointment']->id)->status)
            ->toBe(AppointmentStatus::WAITING_TRIAGE->value);
    }

    // Daudi has not paid and is still at the counter.
    expect(AppointmentModel::query()->find($appointments['Daudi']['appointment']->id)->status)
        ->toBe(AppointmentStatus::AWAITING_PAYMENT->value);

    // ---- A mistake, corrected in-session ---------------------------------
    $baraka = $appointments['Baraka'];
    $barakaPaymentId = DB::table('payments')
        ->where('patient_id', $baraka['patient'])
        ->where('status', 'recorded')
        ->value('id');

    $this->actingAs($cashier)
        ->postJson("/api/v1/cashier/payments/{$barakaPaymentId}/reverse", [
            'reason' => 'Charged the wrong patient',
        ])
        ->assertOk();

    $takings -= 1500000;

    // Authorization is withdrawn — the charge owes again, and the reader that
    // every gate consults now says no.
    expect(DB::table('service_charges')->where('id', $baraka['charge']->id)->value('status'))
        ->toBe('pending_payment');
    expect(app(ServiceAuthorizationReaderInterface::class)
        ->isAuthorized(ChargeSourceKind::CONSULTATION, (string) $baraka['appointment']->id))
        ->toBeFalse();

    // The queue position is deliberately not revoked with it. The gate is
    // enforced when a patient enters the clinical queue, not continuously:
    // pulling someone back out of triage — or out of a consultation — over a
    // bookkeeping correction would be worse than the debt it chases. Reception
    // sees the unpaid chip and can act; the charge is what carries the truth.
    expect(AppointmentModel::query()->find($baraka['appointment']->id)->status)
        ->toBe(AppointmentStatus::WAITING_TRIAGE->value);

    // ---- Mid-shift banking ----------------------------------------------
    $this->actingAs($cashier)
        ->postJson("/api/v1/cashier/sessions/{$sessionId}/movements", [
            'reason' => 'banking_drop', 'amountMinor' => 2000000, 'note' => 'To the safe',
        ])
        ->assertCreated();

    // ---- Blind count -----------------------------------------------------
    // 50,000 float + 30,000 taken (three paid, one reversed) - 20,000 banked.
    $expected = 5000000 + $takings - 2000000;

    // Nothing has told the cashier that number: the session endpoint withholds
    // it while the drawer is open.
    $this->actingAs($cashier)
        ->getJson('/api/v1/cashier/session/current')
        ->assertJsonPath('data.expectedCash', null);

    $close = $this->actingAs($cashier)
        ->postJson("/api/v1/cashier/sessions/{$sessionId}/close", ['declaredCashMinor' => $expected])
        ->assertOk();

    expect($close->json('meta.requiresApproval'))->toBeFalse()
        ->and($close->json('data.variance'))->toBe('0.00')
        ->and($close->json('data.status'))->toBe('closed');

    // ---- The day agrees with the drawer ----------------------------------
    $day = $this->actingAs($supervisor)
        ->getJson('/api/v1/cashier/day/summary')
        ->assertOk();

    expect($day->json('data.grossTakings'))->toBe('30000.00')
        ->and($day->json('data.netTakings'))->toBe('30000.00')
        ->and($day->json('data.receiptsIssued'))->toBe(3)
        ->and($day->json('data.sessionsAwaitingApproval'))->toBe(0)
        ->and($day->json('data.sessions.0.expectedCash'))->toBe('60000.00');

    // ---- And the ledger agrees with itself -------------------------------
    $allocated = (int) DB::table('payment_allocations')->sum('amount_minor');
    $chargeAllocated = (int) DB::table('service_charges')->sum('allocated_amount_minor');

    expect($allocated)->toBe($chargeAllocated)
        ->and($allocated)->toBe($takings);
});

it('holds the day open while a drawer variance is unapproved', function (): void {
    $cashier = rehearsalRole('cashier');
    $supervisor = rehearsalRole('finance-manager');

    $sessionId = $this->actingAs($cashier)
        ->postJson('/api/v1/cashier/sessions', ['openingFloatMinor' => 5000000])
        ->json('data.id');

    // 3,000 short.
    $this->actingAs($cashier)
        ->postJson("/api/v1/cashier/sessions/{$sessionId}/close", ['declaredCashMinor' => 4700000])
        ->assertOk();

    $day = $this->actingAs($supervisor)->getJson('/api/v1/cashier/day/summary')->assertOk();

    expect($day->json('data.sessionsAwaitingApproval'))->toBe(1)
        ->and($day->json('data.sessions.0.variance'))->toBe('-3000.00');

    $this->actingAs($supervisor)
        ->postJson("/api/v1/cashier/sessions/{$sessionId}/approve-variance", [
            'reason' => 'Traced to an unrecorded petty cash withdrawal.',
        ])
        ->assertOk();

    expect(
        $this->actingAs($supervisor)->getJson('/api/v1/cashier/day/summary')
            ->json('data.sessionsAwaitingApproval'),
    )->toBe(0);
});

it('surfaces reprints on the day summary, because volume is a fraud signal', function (): void {
    $cashier = rehearsalRole('cashier');
    $supervisor = rehearsalRole('finance-manager');
    $patientId = rehearsalPatient('Neema');
    $item = RevenueTestSupport::pricedItem('CONSULT-REPRINT', '10000.00');

    $charge = app(RaiseServiceChargeUseCase::class)->execute(
        patientId: $patientId,
        sourceKind: ChargeSourceKind::MANUAL,
        sourceId: null,
        chargeableItemId: $item['chargeableItemId'],
        description: 'Consultation',
    );

    $this->actingAs($cashier)->postJson('/api/v1/cashier/sessions', ['openingFloatMinor' => 0]);

    $receiptId = $this->actingAs($cashier)
        ->postJson('/api/v1/cashier/payments', [
            'patientId' => $patientId,
            'serviceChargeIds' => [(string) $charge->id],
            'tenderedAmountMinor' => 1000000,
            'idempotencyKey' => (string) Str::uuid(),
        ])
        ->json('data.receipt.id');

    foreach (range(1, 3) as $ignored) {
        $this->actingAs($cashier)
            ->postJson("/api/v1/cashier/receipts/{$receiptId}/reprint", ['reason' => 'Printer jam'])
            ->assertOk();
    }

    expect(
        $this->actingAs($supervisor)->getJson('/api/v1/cashier/day/summary')->json('data.reprints'),
    )->toBe(3);
});
