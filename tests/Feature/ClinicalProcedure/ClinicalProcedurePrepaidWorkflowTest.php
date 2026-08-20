<?php

use App\Models\User;
use App\Modules\ClinicalProcedure\Application\UseCases\CreateClinicalProcedureOrderUseCase;
use App\Modules\Platform\Infrastructure\Models\ClinicalCatalogItemModel;
use App\Modules\Revenue\Application\UseCases\OpenCashierSessionUseCase;
use App\Modules\Revenue\Application\UseCases\RecordCashPaymentUseCase;
use App\Modules\Revenue\Domain\Services\ServiceAuthorizationReaderInterface;
use App\Modules\Revenue\Domain\ValueObjects\AuthorizationBasis;
use App\Modules\Revenue\Domain\ValueObjects\ChargeSourceKind;
use App\Modules\Revenue\Domain\ValueObjects\ServiceChargeStatus;
use App\Modules\Revenue\Infrastructure\Models\ServiceChargeModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Revenue\RevenueTestSupport;

function clinicianUserForProcedure(): User
{
    $roles = (array) config('roles');

    return makeUserWithRole((array) $roles['medical-officer']['permissions'], 'CLINICAL.PHYSICIAN');
}

function procedureStaffUser(): User
{
    $roles = (array) config('roles');

    return makeUserWithRole((array) $roles['nurse-officer']['permissions'], 'CLINICAL.NURSE');
}

function cashierUserForProcedure(): User
{
    $roles = (array) config('roles');

    return makeUserWithRole((array) $roles['cashier']['permissions'], 'FINANCE.CASHIER');
}

function seedPatientAndProcedureItem(string $procCode = 'PROC-SUT-MINOR', string $unitPrice = '15000.00'): array
{
    $patientId = (string) Str::uuid();

    DB::table('patients')->insert([
        'id' => $patientId,
        'patient_number' => 'PT-'.Str::upper(Str::random(8)),
        'first_name' => 'Rashid',
        'last_name' => 'Bakari',
        'gender' => 'male',
        'date_of_birth' => '1990-06-15',
        'country_code' => 'TZ',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $catalogItem = ClinicalCatalogItemModel::query()->create([
        'id' => (string) Str::uuid(),
        'code' => $procCode,
        'name' => 'Minor Wound Suturing',
        'catalog_type' => 'clinical_procedure',
        'category' => 'Minor Procedures',
        'description' => 'Simple skin and subcutaneous wound suturing under local anaesthesia',
        'status' => 'active',
    ]);

    $item = RevenueTestSupport::pricedItem(
        code: $procCode,
        unitPrice: $unitPrice,
        chargeModel: 'flat',
    );

    // Link chargeable item to catalog item
    DB::table('chargeable_items')
        ->where('id', $item['chargeableItemId'])
        ->update([
            'clinical_catalog_item_id' => (string) $catalogItem->id,
            'default_unit' => 'procedure',
        ]);

    return [$patientId, (string) $catalogItem->id, $item['chargeableItemId']];
}

it('raises a pending service charge when clinician orders a clinical procedure', function (): void {
    [$patientId, $catalogItemId] = seedPatientAndProcedureItem('PROC-SUT-1', '15000.00');

    $order = app(CreateClinicalProcedureOrderUseCase::class)->execute([
        'patient_id' => $patientId,
        'clinical_procedure_catalog_item_id' => $catalogItemId,
        'procedure_code' => 'PROC-SUT-1',
        'procedure_description' => 'Minor Wound Suturing',
        'clinical_indication' => 'Laceration on right forearm',
        'procedure_setting' => 'outpatient',
    ]);

    $charge = ServiceChargeModel::query()
        ->where('source_workflow_kind', ChargeSourceKind::CLINICAL_PROCEDURE_ORDER->value)
        ->where('source_workflow_id', $order['id'])
        ->first();

    expect($charge)->not->toBeNull()
        ->and($charge->status)->toBe(ServiceChargeStatus::PENDING_PAYMENT)
        ->and($charge->netAmount()->toDecimalString())->toBe('15000.00');

    $authorization = app(ServiceAuthorizationReaderInterface::class)
        ->describe(ChargeSourceKind::CLINICAL_PROCEDURE_ORDER, $order['id']);

    expect($authorization->authorized)->toBeFalse()
        ->and($authorization->status)->toBe(ServiceChargeStatus::PENDING_PAYMENT->value)
        ->and($authorization->amountDue->toDecimalString())->toBe('15000.00');
});

it('exposes price and pending payment status to clinician view over HTTP', function (): void {
    $doctor = clinicianUserForProcedure();
    [$patientId, $catalogItemId] = seedPatientAndProcedureItem('PROC-DRAIN-1', '25000.00');

    $postRes = $this->actingAs($doctor)->postJson('/api/v1/clinician/orders/procedure', [
        'patientId' => $patientId,
        'clinicalProcedureCatalogItemId' => $catalogItemId,
        'procedureCode' => 'PROC-DRAIN-1',
        'procedureDescription' => 'Abscess Incision and Drainage',
        'clinicalIndication' => 'Subcutaneous abscess on left leg',
        'procedureSetting' => 'outpatient',
    ])->assertCreated();

    expect($postRes->json('data.paymentStatus'))->toBe('pending_payment')
        ->and($postRes->json('data.isAuthorized'))->toBeFalse()
        ->and($postRes->json('data.price'))->toEqual(25000)
        ->and($postRes->json('data.amountDue'))->toBe('25000.00');

    $listRes = $this->actingAs($doctor)->getJson("/api/v1/clinician/orders/procedure?patientId={$patientId}")->assertOk();
    expect($listRes->json('data.0.paymentStatus'))->toBe('pending_payment')
        ->and($listRes->json('data.0.isAuthorized'))->toBeFalse()
        ->and($listRes->json('data.0.price'))->toEqual(25000);
});

it('lists ordered procedures in cashier queue and charge basket with itemized prices and correct total due', function (): void {
    $cashier = cashierUserForProcedure();
    [$patientId, $catalogItemId] = seedPatientAndProcedureItem('PROC-BIOPSY-1', '35000.00');

    $order = app(CreateClinicalProcedureOrderUseCase::class)->execute([
        'patient_id' => $patientId,
        'clinical_procedure_catalog_item_id' => $catalogItemId,
        'procedure_code' => 'PROC-BIOPSY-1',
        'procedure_description' => 'Skin Punch Biopsy',
        'clinical_indication' => 'Suspected dermatitis lesion',
        'procedure_setting' => 'outpatient',
    ]);

    // Cashier queue
    $queue = $this->actingAs($cashier)->getJson('/api/v1/cashier/queue')->assertOk();
    $patientInQueue = collect($queue->json('data'))->firstWhere('patientId', $patientId);
    expect($patientInQueue)->not->toBeNull()
        ->and($patientInQueue['amountDue'])->toBe('35000.00');

    // Cashier charges basket
    $basket = $this->actingAs($cashier)->getJson("/api/v1/cashier/patients/{$patientId}/charges")->assertOk();
    expect($basket->json('meta.amountDue'))->toBe('35000.00')
        ->and($basket->json('data.0.sourceKind'))->toBe('clinical_procedure_order')
        ->and($basket->json('data.0.netAmount'))->toBe('35000.00')
        ->and($basket->json('data.0.isPayable'))->toBeTrue();
});

it('hides unpaid procedures from procedure workspace worklist and blocks status update', function (): void {
    $nurse = procedureStaffUser();
    [$patientId, $catalogItemId] = seedPatientAndProcedureItem('PROC-DEBRIDE-1', '20000.00');

    $order = app(CreateClinicalProcedureOrderUseCase::class)->execute([
        'patient_id' => $patientId,
        'clinical_procedure_catalog_item_id' => $catalogItemId,
        'procedure_code' => 'PROC-DEBRIDE-1',
        'procedure_description' => 'Wound Debridement',
        'clinical_indication' => 'Chronic ulcer on right foot',
        'procedure_setting' => 'outpatient',
    ]);

    // Procedure workspace worklist hides unpaid order
    $worklist = $this->actingAs($nurse)->getJson('/api/v1/procedure/orders')->assertOk();
    $found = collect($worklist->json('data'))->firstWhere('id', $order['id']);
    expect($found)->toBeNull();

    // Procedure status counts excludes unpaid order from active worklist
    $counts = $this->actingAs($nurse)->getJson('/api/v1/procedure/orders/status-counts')->assertOk();
    expect($counts->json('data.ordered'))->toBe(0);

    // Attempt to schedule unpaid order fails validation
    $updateRes = $this->actingAs($nurse)->patchJson("/api/v1/procedure/orders/{$order['id']}/status", [
        'status' => 'scheduled',
    ]);
    $updateRes->assertStatus(422);
    expect($updateRes->json('errors.status.0'))->toContain('payment has been verified');
});

it('authorizes procedure upon cashier payment settlement and opens procedure execution', function (): void {
    $cashier = cashierUserForProcedure();
    $nurse = procedureStaffUser();
    $doctor = clinicianUserForProcedure();
    [$patientId, $catalogItemId] = seedPatientAndProcedureItem('PROC-CATH-1', '18000.00');

    $order = app(CreateClinicalProcedureOrderUseCase::class)->execute([
        'patient_id' => $patientId,
        'clinical_procedure_catalog_item_id' => $catalogItemId,
        'procedure_code' => 'PROC-CATH-1',
        'procedure_description' => 'Urinary Catheterization',
        'clinical_indication' => 'Acute urinary retention',
        'procedure_setting' => 'emergency',
    ]);

    $charge = ServiceChargeModel::query()
        ->where('source_workflow_kind', ChargeSourceKind::CLINICAL_PROCEDURE_ORDER->value)
        ->where('source_workflow_id', $order['id'])
        ->firstOrFail();

    // Cashier opens session and records cash payment (18,000 TZS -> 1,800,000 minor units)
    app(OpenCashierSessionUseCase::class)->execute($cashier->id, 5000000);
    app(RecordCashPaymentUseCase::class)->execute(
        patientId: $patientId,
        serviceChargeIds: [(string) $charge->id],
        tenderedAmountMinor: 1800000,
        idempotencyKey: (string) Str::uuid(),
        cashierUserId: $cashier->id,
    );

    // Reader reports authorized
    $reader = app(ServiceAuthorizationReaderInterface::class);
    expect($reader->isAuthorized(ChargeSourceKind::CLINICAL_PROCEDURE_ORDER, $order['id']))->toBeTrue();

    $auth = $reader->describe(ChargeSourceKind::CLINICAL_PROCEDURE_ORDER, $order['id']);
    expect($auth->authorized)->toBeTrue()
        ->and($auth->status)->toBe('authorized')
        ->and($auth->basis)->toBe(AuthorizationBasis::PAYMENT);

    // Procedure workspace now sees the authorized order
    $worklist = $this->actingAs($nurse)->getJson('/api/v1/procedure/orders')->assertOk();
    $found = collect($worklist->json('data'))->firstWhere('id', $order['id']);
    expect($found)->not->toBeNull()
        ->and($found['isAuthorized'])->toBeTrue()
        ->and($found['paymentStatus'])->toBe('authorized');

    // Nurse can now transition procedure through scheduled -> in_progress -> completed
    $this->actingAs($nurse)->patchJson("/api/v1/procedure/orders/{$order['id']}/status", [
        'status' => 'scheduled',
    ])->assertOk();

    $this->actingAs($nurse)->patchJson("/api/v1/procedure/orders/{$order['id']}/status", [
        'status' => 'in_progress',
    ])->assertOk();

    $this->actingAs($nurse)->patchJson("/api/v1/procedure/orders/{$order['id']}/status", [
        'status' => 'completed',
        'reportSummary' => 'Foley catheter 16Fr placed uneventfully with 600ml clear urine drained.',
    ])->assertOk();

    // Clinician view updates to show authorized payment status
    $clinicianView = $this->actingAs($doctor)->getJson("/api/v1/clinician/orders/procedure?patientId={$patientId}")->assertOk();
    expect($clinicianView->json('data.0.paymentStatus'))->toBe('authorized')
        ->and($clinicianView->json('data.0.isAuthorized'))->toBeTrue()
        ->and($clinicianView->json('data.0.amountDue'))->toBe('0.00');
});

it('cancels pending service charge when procedure order is cancelled', function (): void {
    $doctor = clinicianUserForProcedure();
    [$patientId, $catalogItemId] = seedPatientAndProcedureItem('PROC-CANCEL-1', '12000.00');

    $order = app(CreateClinicalProcedureOrderUseCase::class)->execute([
        'patient_id' => $patientId,
        'clinical_procedure_catalog_item_id' => $catalogItemId,
        'procedure_code' => 'PROC-CANCEL-1',
        'procedure_description' => 'Foreign Body Removal',
        'clinical_indication' => 'Foreign body in left ear canal',
        'procedure_setting' => 'outpatient',
    ]);

    $charge = ServiceChargeModel::query()
        ->where('source_workflow_kind', ChargeSourceKind::CLINICAL_PROCEDURE_ORDER->value)
        ->where('source_workflow_id', $order['id'])
        ->firstOrFail();

    expect($charge->status)->toBe(ServiceChargeStatus::PENDING_PAYMENT);

    // Clinician cancels order
    $this->actingAs($doctor)->postJson("/api/v1/clinician/orders/procedure/{$order['id']}/cancel", [
        'action' => 'cancel',
        'reason' => 'Foreign body dislodged spontaneously',
    ])->assertOk();

    $charge->refresh();
    expect($charge->status)->toBe(ServiceChargeStatus::CANCELLED);
});
