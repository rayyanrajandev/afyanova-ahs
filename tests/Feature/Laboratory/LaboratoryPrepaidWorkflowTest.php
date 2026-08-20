<?php

use App\Models\User;
use App\Modules\Laboratory\Application\UseCases\CreateLaboratoryOrderUseCase;
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

function clinicianUser(): User
{
    $roles = (array) config('roles');

    return makeUserWithRole((array) $roles['medical-officer']['permissions'], 'CLINICAL.PHYSICIAN');
}

function labTechUser(): User
{
    $roles = (array) config('roles');

    return makeUserWithRole((array) $roles['lab-technologist']['permissions'], 'LAB.STAFF');
}

function cashierUserForLab(): User
{
    $roles = (array) config('roles');

    return makeUserWithRole((array) $roles['cashier']['permissions'], 'FINANCE.CASHIER');
}

function seedPatientAndLabItem(string $testCode = 'LAB-TEST-1', string $price = '8500.00'): array
{
    $patientId = (string) Str::uuid();

    DB::table('patients')->insert([
        'id' => $patientId,
        'patient_number' => 'PT-'.Str::upper(Str::random(8)),
        'first_name' => 'Juma',
        'last_name' => 'Kassim',
        'gender' => 'male',
        'date_of_birth' => '1995-06-15',
        'country_code' => 'TZ',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $catalogItem = ClinicalCatalogItemModel::query()->create([
        'id' => (string) Str::uuid(),
        'code' => $testCode,
        'name' => 'Full Blood Picture (FBP)',
        'catalog_type' => 'lab_test',
        'category' => 'Hematology',
        'description' => 'Complete hemogram with automated differentials',
        'specimen_type' => 'Whole Blood (EDTA)',
        'status' => 'active',
    ]);

    $item = RevenueTestSupport::pricedItem($testCode, $price);

    // Link chargeable item to catalog item
    DB::table('chargeable_items')
        ->where('id', $item['chargeableItemId'])
        ->update(['clinical_catalog_item_id' => (string) $catalogItem->id]);

    return [$patientId, (string) $catalogItem->id, $item['chargeableItemId']];
}

it('raises a pending service charge when clinician creates a lab order', function (): void {
    [$patientId, $catalogItemId] = seedPatientAndLabItem('LAB-FBP-1', '12000.00');

    $order = app(CreateLaboratoryOrderUseCase::class)->execute([
        'patient_id' => $patientId,
        'lab_test_catalog_item_id' => $catalogItemId,
        'test_code' => 'LAB-FBP-1',
        'test_name' => 'Full Blood Picture (FBP)',
        'priority' => 'routine',
        'clinical_notes' => 'Suspected anemia',
    ]);

    $charge = ServiceChargeModel::query()
        ->where('source_workflow_kind', ChargeSourceKind::LABORATORY_ORDER->value)
        ->where('source_workflow_id', $order['id'])
        ->first();

    expect($charge)->not->toBeNull()
        ->and($charge->status)->toBe(ServiceChargeStatus::PENDING_PAYMENT)
        ->and($charge->netAmount()->toDecimalString())->toBe('12000.00');

    $authorization = app(ServiceAuthorizationReaderInterface::class)
        ->describe(ChargeSourceKind::LABORATORY_ORDER, $order['id']);

    expect($authorization->authorized)->toBeFalse()
        ->and($authorization->status)->toBe(ServiceChargeStatus::PENDING_PAYMENT->value)
        ->and($authorization->amountDue->toDecimalString())->toBe('12000.00');
});

it('exposes price, total cost, and pending payment status to clinician view over HTTP', function (): void {
    $doctor = clinicianUser();
    [$patientId, $catalogItemId] = seedPatientAndLabItem('LAB-MRDT-1', '5000.00');

    $postRes = $this->actingAs($doctor)->postJson('/api/v1/clinician/orders/lab', [
        'patientId' => $patientId,
        'labTestCatalogItemId' => $catalogItemId,
        'testCode' => 'LAB-MRDT-1',
        'testName' => 'Malaria Rapid Diagnostic Test',
        'priority' => 'urgent',
    ])->assertCreated();

    expect($postRes->json('data.paymentStatus'))->toBe('pending_payment')
        ->and($postRes->json('data.isAuthorized'))->toBeFalse()
        ->and($postRes->json('data.price'))->toEqual(5000)
        ->and($postRes->json('data.amountDue'))->toBe('5000.00');

    $listRes = $this->actingAs($doctor)->getJson("/api/v1/clinician/orders/lab?patientId={$patientId}")->assertOk();
    expect($listRes->json('data.0.paymentStatus'))->toBe('pending_payment')
        ->and($listRes->json('data.0.isAuthorized'))->toBeFalse()
        ->and($listRes->json('data.0.price'))->toEqual(5000);
});

it('lists ordered lab tests in cashier queue and charge basket with itemized prices', function (): void {
    $cashier = cashierUserForLab();
    [$patientId, $catalogItemId] = seedPatientAndLabItem('LAB-LIPID-1', '25000.00');

    $order = app(CreateLaboratoryOrderUseCase::class)->execute([
        'patient_id' => $patientId,
        'lab_test_catalog_item_id' => $catalogItemId,
        'test_code' => 'LAB-LIPID-1',
        'test_name' => 'Lipid Profile',
        'priority' => 'routine',
    ]);

    // Cashier queue
    $queue = $this->actingAs($cashier)->getJson('/api/v1/cashier/queue')->assertOk();
    $patientInQueue = collect($queue->json('data'))->firstWhere('patientId', $patientId);
    expect($patientInQueue)->not->toBeNull()
        ->and($patientInQueue['amountDue'])->toBe('25000.00');

    // Cashier charges basket
    $basket = $this->actingAs($cashier)->getJson("/api/v1/cashier/patients/{$patientId}/charges")->assertOk();
    expect($basket->json('meta.amountDue'))->toBe('25000.00')
        ->and($basket->json('data.0.sourceKind'))->toBe('laboratory_order')
        ->and($basket->json('data.0.netAmount'))->toBe('25000.00')
        ->and($basket->json('data.0.isPayable'))->toBeTrue();
});

it('hides unpaid lab orders from laboratory workspace bench and blocks bench processing', function (): void {
    $labTech = labTechUser();
    [$patientId, $catalogItemId] = seedPatientAndLabItem('LAB-URINE-1', '6000.00');

    $order = app(CreateLaboratoryOrderUseCase::class)->execute([
        'patient_id' => $patientId,
        'lab_test_catalog_item_id' => $catalogItemId,
        'test_code' => 'LAB-URINE-1',
        'test_name' => 'Urinalysis Dipstick',
        'priority' => 'routine',
    ]);

    // Laboratory workspace worklist hides unpaid order
    $worklist = $this->actingAs($labTech)->getJson('/api/v1/laboratory/orders')->assertOk();
    $found = collect($worklist->json('data'))->firstWhere('id', $order['id']);
    expect($found)->toBeNull();

    // Laboratory status counts excludes unpaid order from active bench
    $counts = $this->actingAs($labTech)->getJson('/api/v1/laboratory/orders/status-counts')->assertOk();
    expect($counts->json('data.ordered'))->toBe(0);

    // Attempt to collect sample directly fails validation
    $updateRes = $this->actingAs($labTech)->patchJson("/api/v1/laboratory/orders/{$order['id']}/status", [
        'status' => 'collected',
    ]);
    $updateRes->assertStatus(422);
    expect($updateRes->json('errors.status.0'))->toContain('payment has been verified');
});

it('authorizes lab order upon cashier payment settlement and opens laboratory processing bench', function (): void {
    $cashier = cashierUserForLab();
    $labTech = labTechUser();
    $doctor = clinicianUser();
    [$patientId, $catalogItemId] = seedPatientAndLabItem('LAB-CBC-1', '10000.00');

    $order = app(CreateLaboratoryOrderUseCase::class)->execute([
        'patient_id' => $patientId,
        'lab_test_catalog_item_id' => $catalogItemId,
        'test_code' => 'LAB-CBC-1',
        'test_name' => 'Complete Blood Count',
        'priority' => 'routine',
    ]);

    $charge = ServiceChargeModel::query()
        ->where('source_workflow_kind', ChargeSourceKind::LABORATORY_ORDER->value)
        ->where('source_workflow_id', $order['id'])
        ->firstOrFail();

    // Cashier opens session and records cash payment
    app(OpenCashierSessionUseCase::class)->execute($cashier->id, 5000000);
    app(RecordCashPaymentUseCase::class)->execute(
        patientId: $patientId,
        serviceChargeIds: [(string) $charge->id],
        tenderedAmountMinor: 1000000,
        idempotencyKey: (string) Str::uuid(),
        cashierUserId: $cashier->id,
    );

    // Reader reports authorized
    $reader = app(ServiceAuthorizationReaderInterface::class);
    expect($reader->isAuthorized(ChargeSourceKind::LABORATORY_ORDER, $order['id']))->toBeTrue();

    $auth = $reader->describe(ChargeSourceKind::LABORATORY_ORDER, $order['id']);
    expect($auth->authorized)->toBeTrue()
        ->and($auth->status)->toBe('authorized')
        ->and($auth->basis)->toBe(AuthorizationBasis::PAYMENT);

    // Laboratory workspace now sees the authorized order
    $worklist = $this->actingAs($labTech)->getJson('/api/v1/laboratory/orders')->assertOk();
    $found = collect($worklist->json('data'))->firstWhere('id', $order['id']);
    expect($found)->not->toBeNull()
        ->and($found['isAuthorized'])->toBeTrue()
        ->and($found['paymentStatus'])->toBe('authorized');

    // Lab tech can now collect specimen and start testing
    $this->actingAs($labTech)->patchJson("/api/v1/laboratory/orders/{$order['id']}/status", [
        'status' => 'collected',
    ])->assertOk();

    $this->actingAs($labTech)->patchJson("/api/v1/laboratory/orders/{$order['id']}/status", [
        'status' => 'in_progress',
    ])->assertOk();

    // Clinician view updates to show authorized payment status
    $clinicianView = $this->actingAs($doctor)->getJson("/api/v1/clinician/orders/lab?patientId={$patientId}")->assertOk();
    expect($clinicianView->json('data.0.paymentStatus'))->toBe('authorized')
        ->and($clinicianView->json('data.0.isAuthorized'))->toBeTrue()
        ->and($clinicianView->json('data.0.amountDue'))->toBe('0.00');
});

it('cancels pending service charge when lab order is cancelled', function (): void {
    $doctor = clinicianUser();
    [$patientId, $catalogItemId] = seedPatientAndLabItem('LAB-CANCEL-1', '14000.00');

    $order = app(CreateLaboratoryOrderUseCase::class)->execute([
        'patient_id' => $patientId,
        'lab_test_catalog_item_id' => $catalogItemId,
        'test_code' => 'LAB-CANCEL-1',
        'test_name' => 'Thyroid Stimulating Hormone (TSH)',
        'priority' => 'routine',
    ]);

    $charge = ServiceChargeModel::query()
        ->where('source_workflow_kind', ChargeSourceKind::LABORATORY_ORDER->value)
        ->where('source_workflow_id', $order['id'])
        ->firstOrFail();

    expect($charge->status)->toBe(ServiceChargeStatus::PENDING_PAYMENT);

    // Clinician cancels order
    $this->actingAs($doctor)->postJson("/api/v1/clinician/orders/lab/{$order['id']}/cancel", [
        'action' => 'cancel',
        'reason' => 'Ordered in duplicate by mistake',
    ])->assertOk();

    $charge->refresh();
    expect($charge->status)->toBe(ServiceChargeStatus::CANCELLED);
});
