<?php

use App\Models\User;
use App\Modules\Revenue\Application\UseCases\RaiseServiceChargeUseCase;
use App\Modules\Revenue\Domain\ValueObjects\ChargeSourceKind;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Revenue\RevenueTestSupport;

/**
 * Money going back out, over HTTP.
 *
 * Requesting and ruling on a refund are separate acts by separate people, both
 * ways: a supervisor who could only approve would be rubber-stamping, and a
 * cashier who could approve their own request would be no control at all.
 */
function refundRole(string $key): User
{
    $roles = (array) config('roles');

    return makeUserWithRole((array) $roles[$key]['permissions'], (string) $roles[$key]['code']);
}

function paidPatient(string $price = '15000.00'): array
{
    $patientId = (string) Str::uuid();

    DB::table('patients')->insert([
        'id' => $patientId,
        'patient_number' => 'PT-'.Str::upper(Str::random(8)),
        'first_name' => 'Halima', 'last_name' => 'Refund',
        'gender' => 'female', 'date_of_birth' => '1985-02-11',
        'country_code' => 'TZ', 'status' => 'active',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $item = RevenueTestSupport::pricedItem('CONSULT-REF-'.Str::upper(Str::random(5)), $price);

    $charge = app(RaiseServiceChargeUseCase::class)->execute(
        patientId: $patientId,
        sourceKind: ChargeSourceKind::MANUAL,
        sourceId: null,
        chargeableItemId: $item['chargeableItemId'],
        description: 'Consultation',
    );

    return [$patientId, (string) $charge->id];
}

it('lists what a patient has paid, with the refundable amount', function (): void {
    $cashier = refundRole('cashier');
    [$patientId, $chargeId] = paidPatient();

    test()->actingAs($cashier)->postJson('/api/v1/cashier/sessions', ['openingFloatMinor' => 0]);
    test()->actingAs($cashier)->postJson('/api/v1/cashier/payments', [
        'patientId' => $patientId,
        'serviceChargeIds' => [$chargeId],
        'tenderedAmountMinor' => 1500000,
        'idempotencyKey' => (string) Str::uuid(),
    ]);

    $payments = test()->actingAs($cashier)
        ->getJson("/api/v1/cashier/patients/{$patientId}/payments")
        ->assertOk();

    expect($payments->json('data.0.amount'))->toBe('15000.00')
        ->and($payments->json('data.0.alreadyRefunded'))->toBe('0.00')
        ->and($payments->json('data.0.refundable'))->toBe('15000.00')
        ->and($payments->json('data.0.isRefundable'))->toBeTrue()
        ->and($payments->json('data.0.receiptNumber'))->toStartWith('RCP-');
});

it('walks a refund from request to paid', function (): void {
    $cashier = refundRole('cashier');
    $supervisor = refundRole('finance-manager');
    [$patientId, $chargeId] = paidPatient();

    $sessionId = test()->actingAs($cashier)
        ->postJson('/api/v1/cashier/sessions', ['openingFloatMinor' => 5000000])
        ->json('data.id');

    $paymentId = test()->actingAs($cashier)
        ->postJson('/api/v1/cashier/payments', [
            'patientId' => $patientId,
            'serviceChargeIds' => [$chargeId],
            'tenderedAmountMinor' => 1500000,
            'idempotencyKey' => (string) Str::uuid(),
        ])
        ->json('data.id');

    $refund = test()->actingAs($cashier)
        ->postJson('/api/v1/cashier/refunds', [
            'paymentId' => $paymentId,
            'amountMinor' => 1500000,
            'reason' => 'Patient left before being seen',
        ])
        ->assertCreated();

    expect($refund->json('data.status'))->toBe('requested')
        ->and($refund->json('data.refundNumber'))->toStartWith('REF-');

    // It shows on the supervisor's queue.
    expect(test()->actingAs($supervisor)->getJson('/api/v1/cashier/refunds')->json('data'))
        ->toHaveCount(1);

    $paid = test()->actingAs($supervisor)
        ->postJson("/api/v1/cashier/refunds/{$refund->json('data.id')}/approve", [
            'paidFromSessionId' => $sessionId,
            'note' => 'Approved at the counter',
        ])
        ->assertOk();

    expect($paid->json('data.status'))->toBe('paid');

    // And it comes back out of the drawer it was paid from.
    $close = test()->actingAs($cashier)
        ->postJson("/api/v1/cashier/sessions/{$sessionId}/close", ['declaredCashMinor' => 5000000])
        ->assertOk();

    expect($close->json('data.variance'))->toBe('0.00');
});

it('declines a refund, with a reason, and leaves the money alone', function (): void {
    $cashier = refundRole('cashier');
    $supervisor = refundRole('finance-manager');
    [$patientId, $chargeId] = paidPatient();

    test()->actingAs($cashier)->postJson('/api/v1/cashier/sessions', ['openingFloatMinor' => 0]);
    $paymentId = test()->actingAs($cashier)->postJson('/api/v1/cashier/payments', [
        'patientId' => $patientId,
        'serviceChargeIds' => [$chargeId],
        'tenderedAmountMinor' => 1500000,
        'idempotencyKey' => (string) Str::uuid(),
    ])->json('data.id');

    $refundId = test()->actingAs($cashier)
        ->postJson('/api/v1/cashier/refunds', [
            'paymentId' => $paymentId, 'amountMinor' => 1500000, 'reason' => 'Changed their mind',
        ])
        ->json('data.id');

    $rejected = test()->actingAs($supervisor)
        ->postJson("/api/v1/cashier/refunds/{$refundId}/reject", [
            'reason' => 'The consultation was delivered; no refund is due.',
        ])
        ->assertOk();

    expect($rejected->json('data.status'))->toBe('rejected');

    // A declined request frees the money to be refunded later if that changes.
    expect(test()->actingAs($cashier)
        ->getJson("/api/v1/cashier/patients/{$patientId}/payments")
        ->json('data.0.refundable'))->toBe('15000.00');

    expect(DB::table('revenue_audit_events')->where('action', 'rejected')->exists())->toBeTrue();
});

it('will not let one person both request and rule on a refund', function (string $action): void {
    $cashier = refundRole('cashier');
    [$patientId, $chargeId] = paidPatient();

    test()->actingAs($cashier)->postJson('/api/v1/cashier/sessions', ['openingFloatMinor' => 0]);
    $paymentId = test()->actingAs($cashier)->postJson('/api/v1/cashier/payments', [
        'patientId' => $patientId,
        'serviceChargeIds' => [$chargeId],
        'tenderedAmountMinor' => 1500000,
        'idempotencyKey' => (string) Str::uuid(),
    ])->json('data.id');

    $refundId = test()->actingAs($cashier)
        ->postJson('/api/v1/cashier/refunds', [
            'paymentId' => $paymentId, 'amountMinor' => 1500000, 'reason' => 'Mistake',
        ])
        ->json('data.id');

    // Even if the same person somehow held both permissions, the use case
    // refuses — the split is not left to RBAC alone.
    $selfRuling = makeUserWithRole([
        'cashier.access', 'cashier.refunds.request', 'cashier.refunds.approve',
    ], 'SELF.RULING');

    DB::table('refunds')->where('id', $refundId)->update(['requested_by_user_id' => $selfRuling->id]);

    test()->actingAs($selfRuling)
        ->postJson("/api/v1/cashier/refunds/{$refundId}/{$action}", [
            'paidFromSessionId' => (string) Str::uuid(),
            'reason' => 'Approving my own request',
        ])
        ->assertStatus(409);
})->with(['approve', 'reject']);

it('refuses to refund more than was paid', function (): void {
    $cashier = refundRole('cashier');
    [$patientId, $chargeId] = paidPatient();

    test()->actingAs($cashier)->postJson('/api/v1/cashier/sessions', ['openingFloatMinor' => 0]);
    $paymentId = test()->actingAs($cashier)->postJson('/api/v1/cashier/payments', [
        'patientId' => $patientId,
        'serviceChargeIds' => [$chargeId],
        'tenderedAmountMinor' => 1500000,
        'idempotencyKey' => (string) Str::uuid(),
    ])->json('data.id');

    test()->actingAs($cashier)
        ->postJson('/api/v1/cashier/refunds', [
            'paymentId' => $paymentId, 'amountMinor' => 2000000, 'reason' => 'Too much',
        ])
        ->assertStatus(409)
        ->assertJsonPath('code', 'REVENUE_ACTION_NOT_ALLOWED');
});
