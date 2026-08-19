<?php

use App\Modules\Revenue\Application\UseCases\RaiseServiceChargeUseCase;
use App\Modules\Revenue\Domain\Exceptions\PayerClassNotImplementedException;
use App\Modules\Revenue\Domain\ValueObjects\ChargeSourceKind;
use App\Modules\Revenue\Domain\ValueObjects\PayerClass;
use App\Modules\Revenue\Domain\ValueObjects\ServiceChargeStatus;
use App\Modules\Revenue\Infrastructure\Models\RevenueAuditEventModel;
use App\Modules\Revenue\Infrastructure\Models\ServiceChargeModel;
use Illuminate\Support\Str;
use Tests\Feature\Revenue\RevenueTestSupport;

function raiseCharge(): RaiseServiceChargeUseCase
{
    return app(RaiseServiceChargeUseCase::class);
}

it('prices a consultation charge from the cash tariff', function (): void {
    $item = RevenueTestSupport::pricedItem('CONSULT-TEST', '15000.00');
    $appointmentId = (string) Str::uuid();

    $charge = raiseCharge()->execute(
        patientId: RevenueTestSupport::patientId(),
        sourceKind: ChargeSourceKind::CONSULTATION,
        sourceId: $appointmentId,
        chargeableItemId: $item['chargeableItemId'],
        description: 'General outpatient consultation',
        appointmentId: $appointmentId,
    );

    expect($charge->status)->toBe(ServiceChargeStatus::PENDING_PAYMENT)
        ->and($charge->pricing_status)->toBe('priced')
        ->and($charge->currency_code)->toBe('TZS')
        ->and($charge->unit_price_minor)->toBe(1500000)
        ->and($charge->gross_amount_minor)->toBe(1500000)
        ->and($charge->net_amount_minor)->toBe(1500000)
        // Self-pay: the patient owes all of it, the payer owes none.
        ->and($charge->patient_responsibility_minor)->toBe(1500000)
        ->and($charge->payer_responsibility_minor)->toBe(0)
        ->and($charge->allocated_amount_minor)->toBe(0)
        // The exact tariff row is snapshotted so the price stays explainable.
        ->and($charge->price_book_entry_id)->toBe($item['priceBookEntryId']);
});

it('gives the charge a sequential number', function (): void {
    $item = RevenueTestSupport::pricedItem('CONSULT-SEQ', '15000.00');

    $numbers = collect(range(1, 3))->map(fn (): string => (string) raiseCharge()->execute(
        patientId: RevenueTestSupport::patientId(),
        sourceKind: ChargeSourceKind::MANUAL,
        sourceId: null,
        chargeableItemId: $item['chargeableItemId'],
        description: 'Ad-hoc charge',
    )->charge_number);

    $year = now()->format('Y');

    expect($numbers->all())->toBe([
        "CHG-{$year}-000001",
        "CHG-{$year}-000002",
        "CHG-{$year}-000003",
    ]);
});

it('applies a review discount to the gross before tax', function (): void {
    $item = RevenueTestSupport::pricedItem('CONSULT-REVIEW', '15000.00');

    $charge = raiseCharge()->execute(
        patientId: RevenueTestSupport::patientId(),
        sourceKind: ChargeSourceKind::MANUAL,
        sourceId: null,
        chargeableItemId: $item['chargeableItemId'],
        description: 'Review consultation',
        discountPercent: 50.0,
        discountReason: 'Review visit within follow-up window',
    );

    expect($charge->gross_amount_minor)->toBe(1500000)
        ->and($charge->discount_amount_minor)->toBe(750000)
        ->and($charge->net_amount_minor)->toBe(750000)
        ->and($charge->discount_reason)->toBe('Review visit within follow-up window')
        ->and($charge->patient_responsibility_minor)->toBe(750000);
});

it('taxes the discounted amount, not the gross', function (): void {
    // 15,000 less 50% is 7,500; 18% VAT on that is 1,350; total 8,850.
    // Taxing the gross first would overcharge by 1,350.
    $item = RevenueTestSupport::pricedItem(
        'CONSULT-VAT', '15000.00', taxable: true, taxRatePercent: 18.0,
    );

    $charge = raiseCharge()->execute(
        patientId: RevenueTestSupport::patientId(),
        sourceKind: ChargeSourceKind::MANUAL,
        sourceId: null,
        chargeableItemId: $item['chargeableItemId'],
        description: 'Taxable consultation',
        discountPercent: 50.0,
        discountReason: 'Review visit',
    );

    expect($charge->discount_amount_minor)->toBe(750000)
        ->and($charge->tax_amount_minor)->toBe(135000)
        ->and($charge->net_amount_minor)->toBe(885000);
});

it('multiplies by quantity for a per-unit item', function (): void {
    $item = RevenueTestSupport::pricedItem(
        'DRUG-TEST', '250.00', chargeModel: 'per_unit',
    );

    $charge = raiseCharge()->execute(
        patientId: RevenueTestSupport::patientId(),
        sourceKind: ChargeSourceKind::MANUAL,
        sourceId: null,
        chargeableItemId: $item['chargeableItemId'],
        description: 'Amoxicillin 500mg x 21',
        quantity: 21.0,
    );

    expect($charge->unit_price_minor)->toBe(25000)
        ->and($charge->net_amount_minor)->toBe(525000);
});

it('raises an unpriced charge as a draft rather than letting the service through free', function (): void {
    // A facility that forgot to price a service must not be able to register
    // patients for it and silently deliver it for nothing — nor should
    // registration fail. The charge exists, flagged, for someone to price.
    $itemId = RevenueTestSupport::unpricedItem('CONSULT-NOPRICE');

    $charge = raiseCharge()->execute(
        patientId: RevenueTestSupport::patientId(),
        sourceKind: ChargeSourceKind::MANUAL,
        sourceId: null,
        chargeableItemId: $itemId,
        description: 'Unpriced consultation',
    );

    expect($charge->status)->toBe(ServiceChargeStatus::DRAFT)
        ->and($charge->pricing_status)->toBe('missing_price_book_entry')
        ->and($charge->net_amount_minor)->toBe(0)
        ->and($charge->status->permitsFulfilment())->toBeFalse();
});

it('does not raise a second charge for the same clinical order', function (): void {
    $item = RevenueTestSupport::pricedItem('CONSULT-IDEMP', '15000.00');
    $appointmentId = (string) Str::uuid();
    $patientId = RevenueTestSupport::patientId();

    $first = raiseCharge()->execute(
        patientId: $patientId,
        sourceKind: ChargeSourceKind::CONSULTATION,
        sourceId: $appointmentId,
        chargeableItemId: $item['chargeableItemId'],
        description: 'Consultation',
    );

    $second = raiseCharge()->execute(
        patientId: $patientId,
        sourceKind: ChargeSourceKind::CONSULTATION,
        sourceId: $appointmentId,
        chargeableItemId: $item['chargeableItemId'],
        description: 'Consultation',
    );

    expect($second->id)->toBe($first->id)
        ->and(ServiceChargeModel::query()->where('source_workflow_id', $appointmentId)->count())->toBe(1);
});

it('lets a cancelled charge be replaced but blocks a duplicate live one', function (): void {
    $item = RevenueTestSupport::pricedItem('CONSULT-RECHARGE', '15000.00');
    $appointmentId = (string) Str::uuid();
    $patientId = RevenueTestSupport::patientId();

    $first = raiseCharge()->execute(
        patientId: $patientId,
        sourceKind: ChargeSourceKind::CONSULTATION,
        sourceId: $appointmentId,
        chargeableItemId: $item['chargeableItemId'],
        description: 'Consultation',
    );

    $first->update(['status' => ServiceChargeStatus::CANCELLED->value, 'cancelled_at' => now()]);

    $replacement = raiseCharge()->execute(
        patientId: $patientId,
        sourceKind: ChargeSourceKind::CONSULTATION,
        sourceId: $appointmentId,
        chargeableItemId: $item['chargeableItemId'],
        description: 'Consultation (re-raised)',
    );

    expect($replacement->id)->not->toBe($first->id);
});

it('refuses to raise a charge for a payer it cannot settle', function (): void {
    $item = RevenueTestSupport::pricedItem('CONSULT-INSURED', '15000.00');

    raiseCharge()->execute(
        patientId: RevenueTestSupport::patientId(),
        sourceKind: ChargeSourceKind::MANUAL,
        sourceId: null,
        chargeableItemId: $item['chargeableItemId'],
        description: 'Insured consultation',
        payerClass: PayerClass::INSURANCE,
    );
})->throws(PayerClassNotImplementedException::class, 'cash is the only settled payer');

it('requires a clinical reference for an order-backed charge', function (): void {
    $item = RevenueTestSupport::pricedItem('CONSULT-NOREF', '15000.00');

    raiseCharge()->execute(
        patientId: RevenueTestSupport::patientId(),
        sourceKind: ChargeSourceKind::CONSULTATION,
        sourceId: null,
        chargeableItemId: $item['chargeableItemId'],
        description: 'Consultation with no appointment',
    );
})->throws(InvalidArgumentException::class, 'must reference the clinical record');

it('writes an audit event for every charge raised', function (): void {
    $item = RevenueTestSupport::pricedItem('CONSULT-AUDIT', '15000.00');

    $charge = raiseCharge()->execute(
        patientId: RevenueTestSupport::patientId(),
        sourceKind: ChargeSourceKind::MANUAL,
        sourceId: null,
        chargeableItemId: $item['chargeableItemId'],
        description: 'Audited consultation',
        actorUserId: 42,
    );

    $event = RevenueAuditEventModel::query()
        ->where('entity_type', 'service_charge')
        ->where('entity_id', $charge->id)
        ->sole();

    expect($event->action)->toBe('raised')
        ->and($event->actor_user_id)->toBe(42)
        ->and($event->amount_minor)->toBe(1500000)
        ->and($event->currency_code)->toBe('TZS')
        ->and($event->after['chargeNumber'])->toBe($charge->charge_number);
});
