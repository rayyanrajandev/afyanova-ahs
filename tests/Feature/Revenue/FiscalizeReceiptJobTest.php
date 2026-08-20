<?php

use App\Modules\Revenue\Application\Jobs\FiscalizeReceiptJob;
use App\Modules\Revenue\Application\UseCases\OpenCashierSessionUseCase;
use App\Modules\Revenue\Application\UseCases\RecordCashPaymentUseCase;
use App\Modules\Revenue\Infrastructure\Models\ReceiptModel;
use Illuminate\Support\Str;

it('fiscalizes a receipt asynchronously and writes fiscal signature', function (): void {
    $cashier = cashierUser();
    [$patientId, $chargeId] = seedPayablePatient('15000.00');

    app(OpenCashierSessionUseCase::class)->execute(
        cashierUserId: (int) $cashier->id,
        openingFloatMinor: 5000000,
    );

    $payment = app(RecordCashPaymentUseCase::class)->execute(
        patientId: $patientId,
        serviceChargeIds: [$chargeId],
        tenderedAmountMinor: 1500000,
        idempotencyKey: (string) Str::uuid(),
        cashierUserId: (int) $cashier->id,
    );

    $receipt = $payment->receipt;
    expect($receipt)->not()->toBeNull();

    $job = new FiscalizeReceiptJob((string) $receipt->id);
    $job->handle();

    $freshReceipt = ReceiptModel::query()->find($receipt->id);
    expect($freshReceipt->fiscal_status)->toBe('submitted')
        ->and($freshReceipt->fiscal_reference)->toStartWith('VFD-');
});
