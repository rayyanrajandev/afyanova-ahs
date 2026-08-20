<?php

use App\Models\User;
use App\Modules\Platform\Infrastructure\Models\ClinicalCatalogItemModel;
use App\Modules\Radiology\Application\UseCases\CreateRadiologyOrderUseCase;
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

function clinicianUserForRadiology(): User
{
    $roles = (array) config('roles');

    return makeUserWithRole((array) $roles['medical-officer']['permissions'], 'CLINICAL.PHYSICIAN');
}

function radiographerUser(): User
{
    $roles = (array) config('roles');

    return makeUserWithRole((array) $roles['radiographer']['permissions'], 'RADIOLOGY.STAFF');
}

function cashierUserForRadiology(): User
{
    $roles = (array) config('roles');

    return makeUserWithRole((array) $roles['cashier']['permissions'], 'FINANCE.CASHIER');
}

function seedPatientAndRadiologyItem(string $procedureCode = 'RAD-XRAY-CHEST', string $price = '35000.00'): array
{
    $patientId = (string) Str::uuid();

    DB::table('patients')->insert([
        'id' => $patientId,
        'patient_number' => 'PT-'.Str::upper(Str::random(8)),
        'first_name' => 'Fatma',
        'last_name' => 'Salum',
        'gender' => 'female',
        'date_of_birth' => '1990-11-20',
        'country_code' => 'TZ',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $catalogItem = ClinicalCatalogItemModel::query()->create([
        'id' => (string) Str::uuid(),
        'code' => $procedureCode,
        'name' => 'Chest X-Ray PA View',
        'catalog_type' => 'radiology_procedure',
        'category' => 'General Radiology',
        'description' => 'Plain film radiograph of the chest in posteroanterior view',
        'status' => 'active',
    ]);

    $item = RevenueTestSupport::pricedItem($procedureCode, $price);

    // Link chargeable item to catalog item
    DB::table('chargeable_items')
        ->where('id', $item['chargeableItemId'])
        ->update(['clinical_catalog_item_id' => (string) $catalogItem->id]);

    return [$patientId, (string) $catalogItem->id, $item['chargeableItemId']];
}

it('raises a pending service charge when clinician creates a radiology order', function (): void {
    [$patientId, $catalogItemId] = seedPatientAndRadiologyItem('RAD-CXR-1', '35000.00');

    $order = app(CreateRadiologyOrderUseCase::class)->execute([
        'patient_id' => $patientId,
        'radiology_procedure_catalog_item_id' => $catalogItemId,
        'procedure_code' => 'RAD-CXR-1',
        'study_description' => 'Chest X-Ray PA View',
        'modality' => 'xray',
        'priority' => 'routine',
        'clinical_indication' => 'Persistent productive cough',
    ]);

    $charge = ServiceChargeModel::query()
        ->where('source_workflow_kind', ChargeSourceKind::RADIOLOGY_ORDER->value)
        ->where('source_workflow_id', $order['id'])
        ->first();

    expect($charge)->not->toBeNull()
        ->and($charge->status)->toBe(ServiceChargeStatus::PENDING_PAYMENT)
        ->and($charge->netAmount()->toDecimalString())->toBe('35000.00');

    $authorization = app(ServiceAuthorizationReaderInterface::class)
        ->describe(ChargeSourceKind::RADIOLOGY_ORDER, $order['id']);

    expect($authorization->authorized)->toBeFalse()
        ->and($authorization->status)->toBe(ServiceChargeStatus::PENDING_PAYMENT->value)
        ->and($authorization->amountDue->toDecimalString())->toBe('35000.00');
});

it('exposes price, total cost, and pending payment status to clinician view over HTTP', function (): void {
    $doctor = clinicianUserForRadiology();
    [$patientId, $catalogItemId] = seedPatientAndRadiologyItem('RAD-US-ABD', '45000.00');

    $postRes = $this->actingAs($doctor)->postJson('/api/v1/clinician/orders/imaging', [
        'patientId' => $patientId,
        'radiologyProcedureCatalogItemId' => $catalogItemId,
        'procedureCode' => 'RAD-US-ABD',
        'studyDescription' => 'Abdominal Ultrasound',
        'modality' => 'ultrasound',
        'priority' => 'urgent',
    ])->assertCreated();

    expect($postRes->json('data.paymentStatus'))->toBe('pending_payment')
        ->and($postRes->json('data.isAuthorized'))->toBeFalse()
        ->and($postRes->json('data.price'))->toEqual(45000)
        ->and($postRes->json('data.amountDue'))->toBe('45000.00');

    $listRes = $this->actingAs($doctor)->getJson("/api/v1/clinician/orders/imaging?patientId={$patientId}")->assertOk();
    expect($listRes->json('data.0.paymentStatus'))->toBe('pending_payment')
        ->and($listRes->json('data.0.isAuthorized'))->toBeFalse()
        ->and($listRes->json('data.0.price'))->toEqual(45000);
});

it('lists ordered imaging procedures in cashier queue and charge basket with itemized prices', function (): void {
    $cashier = cashierUserForRadiology();
    [$patientId, $catalogItemId] = seedPatientAndRadiologyItem('RAD-CT-HEAD', '180000.00');

    $order = app(CreateRadiologyOrderUseCase::class)->execute([
        'patient_id' => $patientId,
        'radiology_procedure_catalog_item_id' => $catalogItemId,
        'procedure_code' => 'RAD-CT-HEAD',
        'study_description' => 'CT Brain Non-Contrast',
        'modality' => 'ct',
        'priority' => 'urgent',
    ]);

    // Cashier queue
    $queue = $this->actingAs($cashier)->getJson('/api/v1/cashier/queue')->assertOk();
    $patientInQueue = collect($queue->json('data'))->firstWhere('patientId', $patientId);
    expect($patientInQueue)->not->toBeNull()
        ->and($patientInQueue['amountDue'])->toBe('180000.00');

    // Cashier charges basket
    $basket = $this->actingAs($cashier)->getJson("/api/v1/cashier/patients/{$patientId}/charges")->assertOk();
    expect($basket->json('meta.amountDue'))->toBe('180000.00')
        ->and($basket->json('data.0.sourceKind'))->toBe('radiology_order')
        ->and($basket->json('data.0.netAmount'))->toBe('180000.00')
        ->and($basket->json('data.0.isPayable'))->toBeTrue();
});

it('hides unpaid radiology orders from radiology workspace worklist and blocks status update', function (): void {
    $radiographer = radiographerUser();
    [$patientId, $catalogItemId] = seedPatientAndRadiologyItem('RAD-XRAY-KNEE', '30000.00');

    $order = app(CreateRadiologyOrderUseCase::class)->execute([
        'patient_id' => $patientId,
        'radiology_procedure_catalog_item_id' => $catalogItemId,
        'procedure_code' => 'RAD-XRAY-KNEE',
        'study_description' => 'Knee X-Ray AP/Lateral',
        'modality' => 'xray',
        'priority' => 'routine',
    ]);

    // Radiology workspace worklist hides unpaid order
    $worklist = $this->actingAs($radiographer)->getJson('/api/v1/radiology/orders')->assertOk();
    $found = collect($worklist->json('data'))->firstWhere('id', $order['id']);
    expect($found)->toBeNull();

    // Radiology status counts excludes unpaid order from active worklist
    $counts = $this->actingAs($radiographer)->getJson('/api/v1/radiology/orders/status-counts')->assertOk();
    expect($counts->json('data.ordered'))->toBe(0);

    // Attempt to schedule study directly fails validation
    $updateRes = $this->actingAs($radiographer)->patchJson("/api/v1/radiology/orders/{$order['id']}/status", [
        'status' => 'scheduled',
    ]);
    $updateRes->assertStatus(422);
    expect($updateRes->json('errors.status.0'))->toContain('payment has been verified');
});

it('authorizes radiology order upon cashier payment settlement and opens radiology scheduling and imaging', function (): void {
    $cashier = cashierUserForRadiology();
    $radiographer = radiographerUser();
    $doctor = clinicianUserForRadiology();
    [$patientId, $catalogItemId] = seedPatientAndRadiologyItem('RAD-MRI-SPINE', '250000.00');

    $order = app(CreateRadiologyOrderUseCase::class)->execute([
        'patient_id' => $patientId,
        'radiology_procedure_catalog_item_id' => $catalogItemId,
        'procedure_code' => 'RAD-MRI-SPINE',
        'study_description' => 'MRI Lumbar Spine',
        'modality' => 'mri',
        'priority' => 'routine',
    ]);

    $charge = ServiceChargeModel::query()
        ->where('source_workflow_kind', ChargeSourceKind::RADIOLOGY_ORDER->value)
        ->where('source_workflow_id', $order['id'])
        ->firstOrFail();

    // Cashier opens session and records cash payment
    app(OpenCashierSessionUseCase::class)->execute($cashier->id, 5000000);
    app(RecordCashPaymentUseCase::class)->execute(
        patientId: $patientId,
        serviceChargeIds: [(string) $charge->id],
        tenderedAmountMinor: 25000000,
        idempotencyKey: (string) Str::uuid(),
        cashierUserId: $cashier->id,
    );

    // Reader reports authorized
    $reader = app(ServiceAuthorizationReaderInterface::class);
    expect($reader->isAuthorized(ChargeSourceKind::RADIOLOGY_ORDER, $order['id']))->toBeTrue();

    $auth = $reader->describe(ChargeSourceKind::RADIOLOGY_ORDER, $order['id']);
    expect($auth->authorized)->toBeTrue()
        ->and($auth->status)->toBe('authorized')
        ->and($auth->basis)->toBe(AuthorizationBasis::PAYMENT);

    // Radiology workspace now sees the authorized order
    $worklist = $this->actingAs($radiographer)->getJson('/api/v1/radiology/orders')->assertOk();
    $found = collect($worklist->json('data'))->firstWhere('id', $order['id']);
    expect($found)->not->toBeNull()
        ->and($found['isAuthorized'])->toBeTrue()
        ->and($found['paymentStatus'])->toBe('authorized');

    // Radiographer can now schedule and perform study
    $this->actingAs($radiographer)->patchJson("/api/v1/radiology/orders/{$order['id']}/status", [
        'status' => 'scheduled',
    ])->assertOk();

    $this->actingAs($radiographer)->patchJson("/api/v1/radiology/orders/{$order['id']}/status", [
        'status' => 'in_progress',
    ])->assertOk();

    // Clinician view updates to show authorized payment status
    $clinicianView = $this->actingAs($doctor)->getJson("/api/v1/clinician/orders/imaging?patientId={$patientId}")->assertOk();
    expect($clinicianView->json('data.0.paymentStatus'))->toBe('authorized')
        ->and($clinicianView->json('data.0.isAuthorized'))->toBeTrue()
        ->and($clinicianView->json('data.0.amountDue'))->toBe('0.00');
});

it('cancels pending service charge when radiology order is cancelled', function (): void {
    $doctor = clinicianUserForRadiology();
    [$patientId, $catalogItemId] = seedPatientAndRadiologyItem('RAD-CANCEL-1', '50000.00');

    $order = app(CreateRadiologyOrderUseCase::class)->execute([
        'patient_id' => $patientId,
        'radiology_procedure_catalog_item_id' => $catalogItemId,
        'procedure_code' => 'RAD-CANCEL-1',
        'study_description' => 'X-Ray Pelvis AP',
        'modality' => 'xray',
        'priority' => 'routine',
    ]);

    $charge = ServiceChargeModel::query()
        ->where('source_workflow_kind', ChargeSourceKind::RADIOLOGY_ORDER->value)
        ->where('source_workflow_id', $order['id'])
        ->firstOrFail();

    expect($charge->status)->toBe(ServiceChargeStatus::PENDING_PAYMENT);

    // Clinician cancels order
    $this->actingAs($doctor)->postJson("/api/v1/clinician/orders/imaging/{$order['id']}/cancel", [
        'action' => 'cancel',
        'reason' => 'Patient declined procedure',
    ])->assertOk();

    $charge->refresh();
    expect($charge->status)->toBe(ServiceChargeStatus::CANCELLED);
});
