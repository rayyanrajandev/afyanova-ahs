<?php

use App\Modules\Revenue\Application\UseCases\OpenCashierSessionUseCase;
use App\Modules\Revenue\Domain\ValueObjects\PaymentMethod;
use App\Modules\Revenue\Infrastructure\Models\PaymentModel;
use Illuminate\Support\Str;

it('settles a charge using mobile money tender (Lipa Namba)', function (): void {
    $cashier = cashierUser();
    [$patientId, $chargeId] = seedPayablePatient('25000.00');

    app(OpenCashierSessionUseCase::class)->execute(
        cashierUserId: (int) $cashier->id,
        openingFloatMinor: 5000000,
    );

    $idempotency = (string) Str::uuid();

    $response = $this->actingAs($cashier)->postJson('/api/v1/cashier/payments', [
        'patientId' => $patientId,
        'serviceChargeIds' => [$chargeId],
        'tenderedAmountMinor' => 2500000,
        'method' => PaymentMethod::MOBILE_MONEY->value,
        'paymentReference' => '9K28QXYZ7',
        'phoneNumber' => '+255712345678',
        'idempotencyKey' => $idempotency,
    ])->assertCreated();

    expect($response->json('data.method'))->toBe('mobile_money')
        ->and($response->json('data.amount'))->toBe('25000.00')
        ->and($response->json('data.receipt.receiptNumber'))->not()->toBeEmpty();

    $payment = PaymentModel::query()->where('idempotency_key', $idempotency)->first();
    expect($payment)->not()->toBeNull()
        ->and($payment->metadata['paymentReference'])->toBe('9K28QXYZ7')
        ->and($payment->metadata['phoneNumber'])->toBe('+255712345678');
});

it('settles a charge using SimBanking transfer', function (): void {
    $cashier = cashierUser();
    [$patientId, $chargeId] = seedPayablePatient('35000.00');

    app(OpenCashierSessionUseCase::class)->execute(
        cashierUserId: (int) $cashier->id,
        openingFloatMinor: 5000000,
    );

    $idempotency = (string) Str::uuid();

    $response = $this->actingAs($cashier)->postJson('/api/v1/cashier/payments', [
        'patientId' => $patientId,
        'serviceChargeIds' => [$chargeId],
        'tenderedAmountMinor' => 3500000,
        'method' => PaymentMethod::BANK_TRANSFER->value,
        'paymentReference' => 'CRDB-TXN-998811',
        'idempotencyKey' => $idempotency,
    ])->assertCreated();

    expect($response->json('data.method'))->toBe('bank_transfer')
        ->and($response->json('data.amount'))->toBe('35000.00')
        ->and($response->json('data.receipt.receiptNumber'))->not()->toBeEmpty();
});

it('settles a charge using GePG Control Number', function (): void {
    $cashier = cashierUser();
    [$patientId, $chargeId] = seedPayablePatient('50000.00');

    app(OpenCashierSessionUseCase::class)->execute(
        cashierUserId: (int) $cashier->id,
        openingFloatMinor: 5000000,
    );

    $idempotency = (string) Str::uuid();

    $response = $this->actingAs($cashier)->postJson('/api/v1/cashier/payments', [
        'patientId' => $patientId,
        'serviceChargeIds' => [$chargeId],
        'tenderedAmountMinor' => 5000000,
        'method' => PaymentMethod::GEPG->value,
        'paymentReference' => '991234567890',
        'idempotencyKey' => $idempotency,
    ])->assertCreated();

    expect($response->json('data.method'))->toBe('gepg')
        ->and($response->json('data.amount'))->toBe('50000.00')
        ->and($response->json('data.receipt.receiptNumber'))->not()->toBeEmpty();
});
