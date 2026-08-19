<?php

use App\Modules\Revenue\Application\UseCases\OpenCashierSessionUseCase;
use App\Modules\Revenue\Application\UseCases\RaiseServiceChargeUseCase;
use App\Modules\Revenue\Application\UseCases\RecordCashPaymentUseCase;
use App\Modules\Revenue\Domain\ValueObjects\ChargeSourceKind;
use App\Modules\Revenue\Domain\ValueObjects\ServiceChargeStatus;
use App\Modules\Revenue\Infrastructure\Models\PaymentAllocationModel;
use App\Modules\Revenue\Infrastructure\Models\ServiceChargeModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Two cashiers, one charge, same instant.
 *
 * The patient is standing at whichever counter is free, and a colleague has
 * already started ringing them up at the other. Exactly one payment must
 * succeed. Getting this wrong takes the money twice, which is the single
 * failure a patient will always notice and never forgive.
 *
 * Real processes, real connections — the reason the harness runs on PostgreSQL.
 */
afterEach(function (): void {
    DB::table('payment_allocations')->delete();
    DB::table('receipts')->delete();
    DB::table('payments')->delete();
    DB::table('service_charges')->delete();
    DB::table('cashier_sessions')->delete();
    DB::table('revenue_audit_events')->delete();
    DB::table('price_book_entries')->whereIn(
        'chargeable_item_id',
        DB::table('chargeable_items')->where('code', 'like', 'RACE-%')->pluck('id'),
    )->delete();
    DB::table('chargeable_items')->where('code', 'like', 'RACE-%')->delete();
    DB::table('financial_document_sequences')->delete();
});

it('lets exactly one of two simultaneous cashiers settle the same charge', function (): void {
    $code = 'RACE-'.Str::upper(Str::random(6));
    $itemId = (string) Str::uuid();

    DB::table('chargeable_items')->insert([
        'id' => $itemId, 'catalog_type' => 'consultation', 'charge_model' => 'flat',
        'code' => $code, 'name' => 'Race consultation', 'default_unit' => 'visit',
        'status' => 'active', 'is_taxable' => false,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('price_book_entries')->insert([
        'id' => (string) Str::uuid(), 'chargeable_item_id' => $itemId,
        'currency_code' => 'TZS', 'unit_price' => '15000.00', 'tax_rate_percent' => 0,
        'is_taxable' => false, 'tariff_version' => 1, 'status' => 'active',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $patientId = (string) Str::uuid();

    $charge = app(RaiseServiceChargeUseCase::class)->execute(
        patientId: $patientId,
        sourceKind: ChargeSourceKind::MANUAL,
        sourceId: null,
        chargeableItemId: $itemId,
        description: 'Consultation',
    );

    // Two cashiers, two drawers.
    app(OpenCashierSessionUseCase::class)->execute(801, 5000000);
    app(OpenCashierSessionUseCase::class)->execute(802, 5000000);

    $chargeId = (string) $charge->id;
    $workers = [801, 802];
    $socketPairs = [];
    $pids = [];

    DB::disconnect();

    foreach ($workers as $cashierUserId) {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $pid = pcntl_fork();

        if ($pid === -1) {
            $this->fail('Unable to fork a cashier.');
        }

        if ($pid === 0) {
            fclose($pair[0]);
            DB::purge();
            DB::reconnect();

            try {
                app(RecordCashPaymentUseCase::class)->execute(
                    patientId: $patientId,
                    serviceChargeIds: [$chargeId],
                    tenderedAmountMinor: 1500000,
                    idempotencyKey: (string) Str::uuid(),
                    cashierUserId: $cashierUserId,
                );
                fwrite($pair[1], json_encode(['settled' => true]));
            } catch (Throwable $e) {
                fwrite($pair[1], json_encode(['settled' => false, 'error' => $e->getMessage()]));
            }

            fclose($pair[1]);
            exit(0);
        }

        fclose($pair[1]);
        $socketPairs[] = $pair[0];
        $pids[] = $pid;
    }

    $outcomes = [];
    foreach ($socketPairs as $index => $socket) {
        $outcomes[] = json_decode(stream_get_contents($socket), true);
        fclose($socket);
        pcntl_waitpid($pids[$index], $status);
    }

    $settled = array_filter($outcomes, static fn (array $o): bool => $o['settled'] === true);

    // Exactly one. Not zero — the patient must be able to pay; not two — the
    // money must not be taken twice.
    expect($settled)->toHaveCount(1);

    $charge = ServiceChargeModel::query()->find($chargeId);

    expect($charge->status)->toBe(ServiceChargeStatus::AUTHORIZED)
        ->and($charge->allocated_amount_minor)->toBe(1500000)
        ->and(PaymentAllocationModel::query()->where('service_charge_id', $chargeId)->count())->toBe(1)
        ->and(DB::table('payments')->where('status', 'recorded')->count())->toBe(1)
        ->and(DB::table('receipts')->count())->toBe(1);
});
