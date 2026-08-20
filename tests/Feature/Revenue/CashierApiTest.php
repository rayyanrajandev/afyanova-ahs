<?php

use App\Models\User;
use App\Modules\Revenue\Application\UseCases\RaiseServiceChargeUseCase;
use App\Modules\Revenue\Domain\ValueObjects\ChargeSourceKind;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Revenue\RevenueTestSupport;

/**
 * The counter over HTTP, as the workspace will drive it.
 */
function cashierUser(): User
{
    $roles = (array) config('roles');

    return makeUserWithRole((array) $roles['cashier']['permissions'], 'FINANCE.CASHIER');
}

function supervisorUser(): User
{
    $roles = (array) config('roles');

    return makeUserWithRole((array) $roles['finance-manager']['permissions'], 'FINANCE.CONTROLLER');
}

function seedPayablePatient(string $price = '15000.00'): array
{
    $patientId = (string) Str::uuid();

    DB::table('patients')->insert([
        'id' => $patientId,
        'patient_number' => 'PT-'.Str::upper(Str::random(8)),
        'first_name' => 'Asha', 'last_name' => 'Mwinyi',
        'gender' => 'female', 'date_of_birth' => '1988-04-02',
        'country_code' => 'TZ', 'status' => 'active',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $item = RevenueTestSupport::pricedItem('CONSULT-API-'.Str::upper(Str::random(5)), $price);

    $charge = app(RaiseServiceChargeUseCase::class)->execute(
        patientId: $patientId,
        sourceKind: ChargeSourceKind::MANUAL,
        sourceId: null,
        chargeableItemId: $item['chargeableItemId'],
        description: 'General outpatient consultation',
    );

    return [$patientId, (string) $charge->id];
}

it('serves the queue, takes payment and issues a receipt over HTTP', function (): void {
    $cashier = cashierUser();
    [$patientId, $chargeId] = seedPayablePatient();

    // The patient shows up on the queue, owing the full amount.
    $queue = $this->actingAs($cashier)->getJson('/api/v1/cashier/queue')->assertOk();
    expect($queue->json('data.0.patientId'))->toBe($patientId)
        ->and($queue->json('data.0.amountDue'))->toBe('15000.00')
        ->and($queue->json('data.0.chargeCount'))->toBe(1);

    // Their basket lists the charge as payable.
    $basket = $this->actingAs($cashier)
        ->getJson("/api/v1/cashier/patients/{$patientId}/charges")
        ->assertOk();
    expect($basket->json('meta.amountDue'))->toBe('15000.00')
        ->and($basket->json('data.0.isPayable'))->toBeTrue()
        // Money crosses the wire as a decimal string, never a JSON number.
        ->and($basket->json('data.0.netAmount'))->toBeString();

    // No drawer yet.
    $this->actingAs($cashier)
        ->postJson('/api/v1/cashier/payments', [
            'patientId' => $patientId,
            'serviceChargeIds' => [$chargeId],
            'tenderedAmountMinor' => 1500000,
            'idempotencyKey' => (string) Str::uuid(),
        ])
        ->assertStatus(409)
        ->assertJsonPath('code', 'CASHIER_SESSION_REQUIRED');

    $this->actingAs($cashier)
        ->postJson('/api/v1/cashier/sessions', ['openingFloatMinor' => 5000000])
        ->assertCreated();

    // Underpaying is refused, and the response says by how much.
    $this->actingAs($cashier)
        ->postJson('/api/v1/cashier/payments', [
            'patientId' => $patientId,
            'serviceChargeIds' => [$chargeId],
            'tenderedAmountMinor' => 1000000,
            'idempotencyKey' => (string) Str::uuid(),
        ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'INSUFFICIENT_TENDER')
        ->assertJsonPath('amountDue', '15000.00');

    $key = (string) Str::uuid();
    $payment = $this->actingAs($cashier)
        ->postJson('/api/v1/cashier/payments', [
            'patientId' => $patientId,
            'serviceChargeIds' => [$chargeId],
            'tenderedAmountMinor' => 2000000,
            'idempotencyKey' => $key,
        ])
        ->assertCreated();

    expect($payment->json('data.amount'))->toBe('15000.00')
        ->and($payment->json('data.change'))->toBe('5000.00')
        ->and($payment->json('data.receipt.receiptNumber'))->toStartWith('RCP-')
        ->and($payment->json('data.receipt.fiscalStatus'))->toBe('not_required');

    // The same submission again returns the same receipt, not a second one.
    $replay = $this->actingAs($cashier)
        ->postJson('/api/v1/cashier/payments', [
            'patientId' => $patientId,
            'serviceChargeIds' => [$chargeId],
            'tenderedAmountMinor' => 2000000,
            'idempotencyKey' => $key,
        ])
        ->assertCreated();

    expect($replay->json('data.id'))->toBe($payment->json('data.id'))
        ->and(DB::table('receipts')->count())->toBe(1);

    // Queue is clear.
    expect($this->actingAs($cashier)->getJson('/api/v1/cashier/queue')->json('data'))->toBe([]);
});

it('withholds the expected cash until the drawer has been counted', function (): void {
    // The blind count as an API property. If this figure were readable while
    // the session is open, hiding it in the UI would be decoration.
    $cashier = cashierUser();

    $session = $this->actingAs($cashier)
        ->postJson('/api/v1/cashier/sessions', ['openingFloatMinor' => 5000000])
        ->assertCreated();

    $sessionId = $session->json('data.id');

    expect($session->json('data.expectedCash'))->toBeNull()
        ->and($session->json('data.variance'))->toBeNull();

    $this->actingAs($cashier)
        ->getJson('/api/v1/cashier/session/current')
        ->assertOk()
        ->assertJsonPath('data.expectedCash', null);

    // And the Z-report refuses until it has been counted.
    $this->actingAs($cashier)
        ->getJson("/api/v1/cashier/sessions/{$sessionId}/summary")
        ->assertStatus(409)
        ->assertJsonPath('code', 'CASHIER_SESSION_NOT_COUNTED');

    $closed = $this->actingAs($cashier)
        ->postJson("/api/v1/cashier/sessions/{$sessionId}/close", ['declaredCashMinor' => 5000000])
        ->assertOk();

    expect($closed->json('data.expectedCash'))->toBe('50000.00')
        ->and($closed->json('data.variance'))->toBe('0.00')
        ->and($closed->json('meta.requiresApproval'))->toBeFalse();
});

it('escalates a short drawer to a supervisor', function (): void {
    $cashier = cashierUser();

    $sessionId = $this->actingAs($cashier)
        ->postJson('/api/v1/cashier/sessions', ['openingFloatMinor' => 5000000])
        ->json('data.id');

    $closed = $this->actingAs($cashier)
        ->postJson("/api/v1/cashier/sessions/{$sessionId}/close", ['declaredCashMinor' => 4800000])
        ->assertOk();

    expect($closed->json('meta.requiresApproval'))->toBeTrue()
        ->and($closed->json('data.status'))->toBe('pending_approval')
        ->and($closed->json('data.variance'))->toBe('-2000.00');

    // The cashier cannot clear it themselves — no permission at all.
    $this->actingAs($cashier)
        ->postJson("/api/v1/cashier/sessions/{$sessionId}/approve-variance", ['reason' => 'Counted twice'])
        ->assertForbidden();

    $this->actingAs(supervisorUser())
        ->postJson("/api/v1/cashier/sessions/{$sessionId}/approve-variance", [
            'reason' => 'Note traced to the safe; logged with security.',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'closed');
});

it('records a reprint so the count can be reviewed', function (): void {
    $cashier = cashierUser();
    [$patientId, $chargeId] = seedPayablePatient();

    $this->actingAs($cashier)->postJson('/api/v1/cashier/sessions', ['openingFloatMinor' => 0]);

    $receiptId = $this->actingAs($cashier)
        ->postJson('/api/v1/cashier/payments', [
            'patientId' => $patientId,
            'serviceChargeIds' => [$chargeId],
            'tenderedAmountMinor' => 1500000,
            'idempotencyKey' => (string) Str::uuid(),
        ])
        ->json('data.receipt.id');

    $reprinted = $this->actingAs($cashier)
        ->postJson("/api/v1/cashier/receipts/{$receiptId}/reprint", ['reason' => 'Patient lost the first copy'])
        ->assertOk();

    expect($reprinted->json('data.reprintCount'))->toBe(1);

    expect(DB::table('revenue_audit_events')
        ->where('entity_type', 'receipt')
        ->where('action', 'reprinted')
        ->exists())->toBeTrue();
});

it('reports the facility day from the ledger', function (): void {
    $cashier = cashierUser();
    [$patientId, $chargeId] = seedPayablePatient('8000.00');

    $this->actingAs($cashier)->postJson('/api/v1/cashier/sessions', ['openingFloatMinor' => 1000000]);
    $this->actingAs($cashier)->postJson('/api/v1/cashier/payments', [
        'patientId' => $patientId,
        'serviceChargeIds' => [$chargeId],
        'tenderedAmountMinor' => 800000,
        'idempotencyKey' => (string) Str::uuid(),
    ]);

    $day = $this->actingAs(supervisorUser())
        ->getJson('/api/v1/cashier/day/summary')
        ->assertOk();

    expect($day->json('data.grossTakings'))->toBe('8000.00')
        ->and($day->json('data.netTakings'))->toBe('8000.00')
        ->and($day->json('data.receiptsIssued'))->toBe(1)
        ->and($day->json('data.sessions'))->toHaveCount(1);
});

it('shows how the expected cash figure was arrived at', function (): void {
    // A variance the cashier cannot check is a number they are asked to sign
    // for. The close must show its own arithmetic.
    $cashier = cashierUser();
    [$patientId, $chargeId] = seedPayablePatient('15000.00');

    $sessionId = $this->actingAs($cashier)
        ->postJson('/api/v1/cashier/sessions', ['openingFloatMinor' => 5000000])
        ->json('data.id');

    $this->actingAs($cashier)->postJson('/api/v1/cashier/payments', [
        'patientId' => $patientId,
        'serviceChargeIds' => [$chargeId],
        'tenderedAmountMinor' => 1500000,
        'idempotencyKey' => (string) Str::uuid(),
    ]);

    $this->actingAs($cashier)->postJson("/api/v1/cashier/sessions/{$sessionId}/movements", [
        'reason' => 'banking_drop', 'amountMinor' => 2000000, 'note' => 'To the safe',
    ]);

    // 50,000 float + 15,000 taken − 20,000 banked = 45,000, counted 10,000.
    $close = $this->actingAs($cashier)
        ->postJson("/api/v1/cashier/sessions/{$sessionId}/close", ['declaredCashMinor' => 1000000])
        ->assertOk();

    expect($close->json('data.expectedCash'))->toBe('45000.00')
        ->and($close->json('data.variance'))->toBe('-35000.00')
        ->and($close->json('meta.requiresApproval'))->toBeTrue();

    // Every term of that sum is returned, so the figure can be checked.
    expect($close->json('meta.breakdown'))->toBe([
        'openingFloat' => '50000.00',
        'cashTaken' => '15000.00',
        'cashIn' => '0.00',
        'cashOut' => '20000.00',
        'refundsPaid' => '0.00',
        'reversals' => '0.00',
        'paymentCount' => 1,
    ]);
});

/*
|--------------------------------------------------------------------------
| After the money is taken (2026-08-19)
|--------------------------------------------------------------------------
|
| Reported from a live counter: a payment recorded cleanly, then the "Paid
| today" tab showed the patient at 0 TZS, opening them showed an empty pane,
| and the day summary showed nothing at all. Three separate faults, all on the
| read side — the ledger was correct throughout.
|
*/

it('reports what was taken on the paid tab, not what is still owed', function (): void {
    // amountDue is outstanding, and outstanding is zero the moment a charge is
    // settled — so every row on this tab read 0.00.
    $cashier = cashierUser();
    [$patientId, $chargeId] = seedPayablePatient();

    $this->actingAs($cashier)
        ->postJson('/api/v1/cashier/sessions', ['openingFloatMinor' => 5000000])
        ->assertCreated();

    $this->actingAs($cashier)->postJson('/api/v1/cashier/payments', [
        'patientId' => $patientId,
        'serviceChargeIds' => [$chargeId],
        'tenderedAmountMinor' => 1500000,
        'idempotencyKey' => (string) Str::uuid(),
    ])->assertCreated();

    $paid = $this->actingAs($cashier)
        ->getJson('/api/v1/cashier/queue?status=paid_today')
        ->assertOk();

    expect($paid->json('data.0.patientId'))->toBe($patientId)
        ->and($paid->json('data.0.amountPaid'))->toBe('15000.00')
        // Still reported, and still zero — the two figures mean different
        // things and the tab picks the one it needs.
        ->and($paid->json('data.0.amountDue'))->toBe('0.00');
});

it('shows a settled charge when the basket is asked to include one', function (): void {
    // Opening a patient from the paid tab showed nothing: the basket lists what
    // is owed, and they owe nothing. The endpoint has always accepted this
    // flag; the workspace never sent it.
    $cashier = cashierUser();
    [$patientId, $chargeId] = seedPayablePatient();

    $this->actingAs($cashier)
        ->postJson('/api/v1/cashier/sessions', ['openingFloatMinor' => 5000000])
        ->assertCreated();
    $this->actingAs($cashier)->postJson('/api/v1/cashier/payments', [
        'patientId' => $patientId,
        'serviceChargeIds' => [$chargeId],
        'tenderedAmountMinor' => 1500000,
        'idempotencyKey' => (string) Str::uuid(),
    ])->assertCreated();

    $unpaidOnly = $this->actingAs($cashier)
        ->getJson("/api/v1/cashier/patients/{$patientId}/charges")
        ->assertOk();
    expect($unpaidOnly->json('data'))->toBe([]);

    $withSettled = $this->actingAs($cashier)
        ->getJson("/api/v1/cashier/patients/{$patientId}/charges?includeSettled=1")
        ->assertOk();
    expect($withSettled->json('data.0.id'))->toBe($chargeId)
        ->and($withSettled->json('data.0.status'))->toBe('authorized');
});

it('refuses the day summary to a cashier, which is why the button is not offered', function (): void {
    // The workspace showed this button to everyone. A cashier does not hold
    // cashier.reports.read, so opening it could only ever produce an error.
    $this->actingAs(cashierUser())
        ->getJson('/api/v1/cashier/day/summary')
        ->assertForbidden();

    $this->actingAs(supervisorUser())
        ->getJson('/api/v1/cashier/day/summary')
        ->assertOk();
});
