<?php

use App\Models\User;
use App\Modules\Patient\Infrastructure\Models\PatientModel;
use App\Modules\Revenue\Application\UseCases\GetActiveSessionTransactionsUseCase;
use App\Modules\Revenue\Application\UseCases\OpenCashierSessionUseCase;
use App\Modules\Revenue\Application\UseCases\RaiseServiceChargeUseCase;
use App\Modules\Revenue\Application\UseCases\RecordCashPaymentUseCase;
use App\Modules\Revenue\Domain\ValueObjects\ChargeSourceKind;
use App\Modules\Revenue\Domain\ValueObjects\PaymentMethod;
use Illuminate\Support\Str;
use Tests\Feature\Revenue\RevenueTestSupport;

function createTestPatient(string $firstName = 'Amani', string $lastName = 'Mollel'): PatientModel
{
    return PatientModel::query()->create([
        'patient_number' => 'PT-'.Str::upper(Str::random(6)),
        'first_name' => $firstName,
        'last_name' => $lastName,
        'gender' => 'female',
        'date_of_birth' => '1995-06-15',
        'phone' => '+255754000111',
        'country_code' => 'TZ',
        'status' => 'active',
    ]);
}

function createChargeForPatient(string $patientId, string $price = '20000.00'): string
{
    $item = RevenueTestSupport::pricedItem('CONS-'.Str::upper(Str::random(5)), $price);

    $charge = app(RaiseServiceChargeUseCase::class)->execute(
        patientId: $patientId,
        sourceKind: ChargeSourceKind::MANUAL,
        sourceId: null,
        chargeableItemId: $item['chargeableItemId'],
        description: 'Consultation Fee',
    );

    return (string) $charge->id;
}

it('returns full patient data and differentiates payment methods in shift summary', function (): void {
    $cashierUser = User::factory()->create(['name' => 'Fatma Cashier']);
    $cashierId = (int) $cashierUser->id;

    $session = app(OpenCashierSessionUseCase::class)->execute(
        cashierUserId: $cashierId,
        openingFloatMinor: 5000000,
    );

    $patient1 = createTestPatient('Baraka', 'Juma');
    $patient2 = createTestPatient('Zuhura', 'Said');

    $charge1 = createChargeForPatient($patient1->id, '15000.00');
    $charge2 = createChargeForPatient($patient2->id, '30000.00');

    // Payment 1: Cash payment for Patient 1 (20,000 tendered for 15,000 charge -> 5,000 change)
    app(RecordCashPaymentUseCase::class)->execute(
        patientId: $patient1->id,
        serviceChargeIds: [$charge1],
        tenderedAmountMinor: 2000000,
        idempotencyKey: (string) Str::uuid(),
        cashierUserId: $cashierId,
        method: PaymentMethod::CASH->value,
    );

    // Payment 2: Mobile money split tender for Patient 2
    app(RecordCashPaymentUseCase::class)->execute(
        patientId: $patient2->id,
        serviceChargeIds: [$charge2],
        tenderedAmountMinor: 3000000,
        idempotencyKey: (string) Str::uuid(),
        cashierUserId: $cashierId,
        method: PaymentMethod::MOBILE_MONEY->value,
        paymentReference: 'MPESA-9988',
        phoneNumber: '+255712345678',
        tenderLines: [
            ['method' => 'cash', 'amountMinor' => 1000000, 'reference' => null],
            ['method' => 'mobile_money', 'amountMinor' => 2000000, 'reference' => 'MPESA-9988'],
        ],
    );

    $useCase = app(GetActiveSessionTransactionsUseCase::class);
    $result = $useCase->execute($cashierId);

    // Verify session
    expect($result['session'])->not()->toBeNull()
        ->and($result['session']['id'])->toBe((string) $session->id)
        ->and($result['session']['cashierName'])->toBe('Fatma Cashier');

    // Verify summary KPIs
    expect($result['summary']['totalGross'])->toBe('45000.00')
        ->and($result['summary']['totalCash'])->toBe('25000.00')
        ->and($result['summary']['totalDigital'])->toBe('20000.00')
        ->and($result['summary']['totalTransactions'])->toBe(2)
        ->and($result['summary']['uniquePatientsCount'])->toBe(2)
        ->and($result['summary']['receiptsCount'])->toBe(2);

    // Verify transactions have patient details
    expect($result['transactions'])->toHaveCount(2);

    $txnForPatient2 = collect($result['transactions'])->firstWhere('patientId', $patient2->id);
    expect($txnForPatient2)->not()->toBeNull()
        ->and($txnForPatient2['patientName'])->toBe('Zuhura Said')
        ->and($txnForPatient2['patientNumber'])->toBe((string) $patient2->patient_number)
        ->and($txnForPatient2['isSplit'])->toBeTrue()
        ->and($txnForPatient2['methods'])->toHaveCount(2);

    $txnForPatient1 = collect($result['transactions'])->firstWhere('patientId', $patient1->id);
    expect($txnForPatient1)->not()->toBeNull()
        ->and($txnForPatient1['patientName'])->toBe('Baraka Juma')
        ->and($txnForPatient1['patientNumber'])->toBe((string) $patient1->patient_number)
        ->and($txnForPatient1['amount'])->toBe('15000.00')
        ->and($txnForPatient1['changeAmount'])->toBe('5000.00');

    // Verify method breakdown
    expect($result['totalsByMethodBreakdown'])->toHaveCount(2);
    $cashBreakdown = collect($result['totalsByMethodBreakdown'])->firstWhere('method', 'cash');
    expect($cashBreakdown)->not()->toBeNull()
        ->and($cashBreakdown['category'])->toBe('cash')
        ->and($cashBreakdown['amount'])->toBe('25000.00');

    $mobileBreakdown = collect($result['totalsByMethodBreakdown'])->firstWhere('method', 'mobile_money');
    expect($mobileBreakdown)->not()->toBeNull()
        ->and($mobileBreakdown['category'])->toBe('digital')
        ->and($mobileBreakdown['amount'])->toBe('20000.00');
});
