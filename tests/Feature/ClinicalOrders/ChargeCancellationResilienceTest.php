<?php

/**
 * Cancelling a clinical order must cancel the charge the patient has not paid,
 * and a Revenue failure must neither undo the clinical decision nor disappear.
 *
 * Written against the 2026-08-19 workspace maturity audit
 * (reports/workspace-maturity/02-clinical-order-workspaces.md, goal G1). Before
 * that fix, three of these four modules swallowed the failure in an empty catch
 * block whose comment claimed it logged, and the fourth had no catch at all —
 * so a Revenue outage could block a clinician from cancelling an order.
 *
 * The four modules are exercised through one dataset on purpose: the defect was
 * four copies of the same routine drifting apart, and a shared test is what
 * stops them drifting again.
 */

use App\Modules\ClinicalProcedure\Application\UseCases\ApplyClinicalProcedureOrderLifecycleActionUseCase;
use App\Modules\ClinicalProcedure\Application\UseCases\CreateClinicalProcedureOrderUseCase;
use App\Modules\Laboratory\Application\UseCases\ApplyLaboratoryOrderLifecycleActionUseCase;
use App\Modules\Laboratory\Application\UseCases\CreateLaboratoryOrderUseCase;
use App\Modules\Pharmacy\Application\UseCases\ApplyPharmacyOrderLifecycleActionUseCase;
use App\Modules\Pharmacy\Application\UseCases\CreatePharmacyOrderUseCase;
use App\Modules\Platform\Infrastructure\Models\ClinicalCatalogItemModel;
use App\Modules\Radiology\Application\UseCases\ApplyRadiologyOrderLifecycleActionUseCase;
use App\Modules\Radiology\Application\UseCases\CreateRadiologyOrderUseCase;
use App\Modules\Revenue\Application\UseCases\CancelServiceChargeUseCase;
use App\Modules\Revenue\Domain\ValueObjects\ChargeSourceKind;
use App\Modules\Revenue\Domain\ValueObjects\RevenueTelemetryEvent;
use App\Modules\Revenue\Domain\ValueObjects\ServiceChargeStatus;
use App\Modules\Revenue\Infrastructure\Models\RevenueTelemetryEventModel;
use App\Modules\Revenue\Infrastructure\Models\ServiceChargeModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\Feature\Revenue\RevenueTestSupport;

dataset('clinical order modules', ['laboratory', 'radiology', 'pharmacy', 'clinical_procedure']);

function seedOrderablePatientAndItem(string $code, string $catalogType, string $name, string $price): array
{
    $patientId = (string) Str::uuid();

    DB::table('patients')->insert([
        'id' => $patientId,
        'patient_number' => 'PT-'.Str::upper(Str::random(8)),
        'first_name' => 'Asha',
        'last_name' => 'Mwinyi',
        'gender' => 'female',
        'date_of_birth' => '1992-03-08',
        'country_code' => 'TZ',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $catalogItem = ClinicalCatalogItemModel::query()->create([
        'id' => (string) Str::uuid(),
        'code' => $code,
        'name' => $name,
        'catalog_type' => $catalogType,
        'category' => 'Audit Fixture',
        'description' => $name,
        'status' => 'active',
    ]);

    $item = RevenueTestSupport::pricedItem($code, $price);

    DB::table('chargeable_items')
        ->where('id', $item['chargeableItemId'])
        ->update(['clinical_catalog_item_id' => (string) $catalogItem->id]);

    return [$patientId, (string) $catalogItem->id];
}

/**
 * @return array{0: string, 1: ChargeSourceKind, 2: class-string}
 */
function makeCancellableOrder(string $module): array
{
    $code = strtoupper(Str::random(8));

    if ($module === 'laboratory') {
        [$patientId, $catalogItemId] = seedOrderablePatientAndItem($code, 'lab_test', 'Full Blood Picture', '12000.00');
        $order = app(CreateLaboratoryOrderUseCase::class)->execute([
            'patient_id' => $patientId,
            'lab_test_catalog_item_id' => $catalogItemId,
            'test_code' => $code,
            'test_name' => 'Full Blood Picture',
            'priority' => 'routine',
        ]);

        return [(string) $order['id'], ChargeSourceKind::LABORATORY_ORDER, ApplyLaboratoryOrderLifecycleActionUseCase::class];
    }

    if ($module === 'radiology') {
        [$patientId, $catalogItemId] = seedOrderablePatientAndItem($code, 'radiology_procedure', 'Chest X-Ray PA View', '35000.00');
        $order = app(CreateRadiologyOrderUseCase::class)->execute([
            'patient_id' => $patientId,
            'radiology_procedure_catalog_item_id' => $catalogItemId,
            'procedure_code' => $code,
            'study_description' => 'Chest X-Ray PA View',
            'modality' => 'xray',
            'priority' => 'routine',
            'clinical_indication' => 'Persistent cough',
        ]);

        return [(string) $order['id'], ChargeSourceKind::RADIOLOGY_ORDER, ApplyRadiologyOrderLifecycleActionUseCase::class];
    }

    if ($module === 'pharmacy') {
        [$patientId, $catalogItemId] = seedOrderablePatientAndItem($code, 'formulary_item', 'Amoxicillin 500mg Capsule', '500.00');
        $order = app(CreatePharmacyOrderUseCase::class)->execute([
            'patient_id' => $patientId,
            'approved_medicine_catalog_item_id' => $catalogItemId,
            'medication_code' => $code,
            'medication_name' => 'Amoxicillin 500mg Capsule',
            'clinical_indication' => 'Bacterial upper respiratory tract infection',
            'dosage_instruction' => '500mg TID for 7 days',
            'dose_quantity' => '500',
            'dose_unit' => 'mg',
            'frequency' => 'tid',
            'duration_value' => 7,
            'duration_unit' => 'days',
            'quantity_prescribed' => 21,
            'prescribed_unit' => 'capsule',
        ]);

        return [(string) $order['id'], ChargeSourceKind::PHARMACY_ORDER, ApplyPharmacyOrderLifecycleActionUseCase::class];
    }

    [$patientId, $catalogItemId] = seedOrderablePatientAndItem($code, 'clinical_procedure', 'Minor Wound Suturing', '15000.00');
    $order = app(CreateClinicalProcedureOrderUseCase::class)->execute([
        'patient_id' => $patientId,
        'clinical_procedure_catalog_item_id' => $catalogItemId,
        'procedure_code' => $code,
        'procedure_description' => 'Minor Wound Suturing',
        'clinical_indication' => 'Laceration on right forearm',
        'procedure_setting' => 'outpatient',
    ]);

    return [(string) $order['id'], ChargeSourceKind::CLINICAL_PROCEDURE_ORDER, ApplyClinicalProcedureOrderLifecycleActionUseCase::class];
}

function liveChargeForOrder(ChargeSourceKind $kind, string $orderId): ?ServiceChargeModel
{
    return ServiceChargeModel::query()
        ->where('source_workflow_kind', $kind->value)
        ->where('source_workflow_id', $orderId)
        ->first();
}

it('cancels the pending charge when a clinical order is cancelled', function (string $module): void {
    $actor = makeUserWithRole();
    [$orderId, $kind, $applyClass] = makeCancellableOrder($module);

    expect(liveChargeForOrder($kind, $orderId)?->status)->toBe(ServiceChargeStatus::PENDING_PAYMENT);

    app($applyClass)->execute($orderId, 'cancel', 'Ordered in error', $actor->id);

    expect(liveChargeForOrder($kind, $orderId)?->status)->toBe(ServiceChargeStatus::CANCELLED);
})->with('clinical order modules');

it('still cancels the order, and says so, when the charge cancellation fails', function (string $module): void {
    $actor = makeUserWithRole();
    [$orderId, $kind, $applyClass] = makeCancellableOrder($module);

    Log::spy();

    $exploding = Mockery::mock(CancelServiceChargeUseCase::class);
    $exploding->shouldReceive('execute')->andThrow(new RuntimeException('Revenue ledger unavailable'));
    app()->instance(CancelServiceChargeUseCase::class, $exploding);

    $updated = app($applyClass)->execute($orderId, 'cancel', 'Ordered in error', $actor->id);

    // (a) the clinical decision stands — Revenue must never veto it
    expect($updated)->not->toBeNull()
        ->and($updated['status'])->toBe('cancelled');

    // (b) the surviving charge leaves a trace someone can reconcile
    Log::shouldHaveReceived('warning')->withArgs(
        fn (string $message, array $context): bool => str_contains($message, 'Unable to cancel the pending charge')
            && ($context['order_id'] ?? null) === $orderId
            && ($context['source_kind'] ?? null) === $kind->value
            && ($context['error'] ?? null) === 'Revenue ledger unavailable'
    );

    // (c) and a countable one. A log line is readable; it is not queryable, and
    // reconciling "which cancelled orders still carry a live charge" is the
    // whole point of noticing (01-revenue-cashier.md G2).
    $telemetry = RevenueTelemetryEventModel::query()
        ->where('event_type', RevenueTelemetryEvent::CHARGE_CANCEL_FAILED->value)
        ->where('source_workflow_id', $orderId)
        ->first();

    expect($telemetry)->not->toBeNull()
        ->and($telemetry->source_kind)->toBe($kind->value)
        ->and($telemetry->detail)->toBe('Revenue ledger unavailable');
})->with('clinical order modules');
