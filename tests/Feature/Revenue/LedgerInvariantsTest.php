<?php

use App\Modules\Revenue\Application\UseCases\RaiseServiceChargeUseCase;
use App\Modules\Revenue\Domain\ValueObjects\ChargeSourceKind;
use App\Modules\Revenue\Infrastructure\Models\ServiceChargeModel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Revenue\RevenueTestSupport;

/**
 * These invariants are enforced by the database, not by a use case.
 *
 * A rule that lives only in application code holds until the next caller
 * forgets it — a console command, a data fix, a future workspace. Money rules
 * are the ones where that is least acceptable, so the important ones are
 * constraints, and these tests assert the constraint bites rather than that
 * some method happens to check.
 */
it('refuses two live charges for the same clinical order at the database level', function (): void {
    $item = RevenueTestSupport::pricedItem('CONSULT-DBDUP', '15000.00');
    $appointmentId = (string) Str::uuid();

    app(RaiseServiceChargeUseCase::class)->execute(
        patientId: RevenueTestSupport::patientId(),
        sourceKind: ChargeSourceKind::CONSULTATION,
        sourceId: $appointmentId,
        chargeableItemId: $item['chargeableItemId'],
        description: 'Consultation',
    );

    // Bypass the use case entirely — this is what a careless direct insert,
    // a seeder, or a second process racing past the read check would do.
    ServiceChargeModel::query()->create([
        'charge_number' => 'CHG-MANUAL-1',
        'patient_id' => RevenueTestSupport::patientId(),
        'source_workflow_kind' => ChargeSourceKind::CONSULTATION->value,
        'source_workflow_id' => $appointmentId,
        'description' => 'Duplicate consultation',
        'currency_code' => 'TZS',
        'status' => 'pending_payment',
    ]);
})->throws(QueryException::class);

it('refuses to allocate more than the patient owes', function (): void {
    $item = RevenueTestSupport::pricedItem('CONSULT-OVERALLOC', '15000.00');

    $charge = app(RaiseServiceChargeUseCase::class)->execute(
        patientId: RevenueTestSupport::patientId(),
        sourceKind: ChargeSourceKind::MANUAL,
        sourceId: null,
        chargeableItemId: $item['chargeableItemId'],
        description: 'Consultation',
    );

    // Overpayment at the counter becomes change, never an over-allocated
    // charge. Without this constraint a day's takings stop reconciling.
    DB::table('service_charges')
        ->where('id', $charge->id)
        ->update(['allocated_amount_minor' => 1500001]);
})->throws(QueryException::class);

it('refuses negative money on a charge', function (): void {
    $item = RevenueTestSupport::pricedItem('CONSULT-NEG', '15000.00');

    $charge = app(RaiseServiceChargeUseCase::class)->execute(
        patientId: RevenueTestSupport::patientId(),
        sourceKind: ChargeSourceKind::MANUAL,
        sourceId: null,
        chargeableItemId: $item['chargeableItemId'],
        description: 'Consultation',
    );

    // A correction is a refund row, not a negative charge.
    DB::table('service_charges')
        ->where('id', $charge->id)
        ->update(['net_amount_minor' => -1]);
})->throws(QueryException::class);

it('allows allocation exactly up to the amount owed', function (): void {
    $item = RevenueTestSupport::pricedItem('CONSULT-EXACT', '15000.00');

    $charge = app(RaiseServiceChargeUseCase::class)->execute(
        patientId: RevenueTestSupport::patientId(),
        sourceKind: ChargeSourceKind::MANUAL,
        sourceId: null,
        chargeableItemId: $item['chargeableItemId'],
        description: 'Consultation',
    );

    DB::table('service_charges')
        ->where('id', $charge->id)
        ->update(['allocated_amount_minor' => 1500000]);

    $charge->refresh();

    expect($charge->allocatedAmount()->minorUnits)->toBe(1500000)
        ->and($charge->outstandingAmount()->isZero())->toBeTrue();
});
