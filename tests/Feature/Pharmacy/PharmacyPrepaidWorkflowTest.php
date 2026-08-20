<?php

use App\Models\User;
use App\Modules\Pharmacy\Application\UseCases\CreatePharmacyOrderUseCase;
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

function clinicianUserForPharmacy(): User
{
    $roles = (array) config('roles');

    return makeUserWithRole((array) $roles['medical-officer']['permissions'], 'CLINICAL.PHYSICIAN');
}

function pharmacistUser(): User
{
    $roles = (array) config('roles');

    return makeUserWithRole((array) $roles['pharmacist']['permissions'], 'PHARMACY.SUPERVISOR');
}

function cashierUserForPharmacy(): User
{
    $roles = (array) config('roles');

    return makeUserWithRole((array) $roles['cashier']['permissions'], 'FINANCE.CASHIER');
}

function seedPatientAndMedicationItem(string $medCode = 'MED-AMOX-500CAP', string $unitPrice = '500.00'): array
{
    $patientId = (string) Str::uuid();

    DB::table('patients')->insert([
        'id' => $patientId,
        'patient_number' => 'PT-'.Str::upper(Str::random(8)),
        'first_name' => 'Neema',
        'last_name' => 'Massawe',
        'gender' => 'female',
        'date_of_birth' => '1995-04-12',
        'country_code' => 'TZ',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $catalogItem = ClinicalCatalogItemModel::query()->create([
        'id' => (string) Str::uuid(),
        'code' => $medCode,
        'name' => 'Amoxicillin 500mg Capsule',
        'catalog_type' => 'formulary_item',
        'category' => 'Antibiotics',
        'description' => 'Oral broad spectrum penicillin antibiotic',
        'status' => 'active',
    ]);

    $item = RevenueTestSupport::pricedItem(
        code: $medCode,
        unitPrice: $unitPrice,
        chargeModel: 'per_unit',
    );

    // Link chargeable item to catalog item
    DB::table('chargeable_items')
        ->where('id', $item['chargeableItemId'])
        ->update([
            'clinical_catalog_item_id' => (string) $catalogItem->id,
            'default_unit' => 'unit',
        ]);

    return [$patientId, (string) $catalogItem->id, $item['chargeableItemId']];
}

it('raises a pending service charge when clinician prescribes a medication, multiplying price by quantity', function (): void {
    [$patientId, $catalogItemId] = seedPatientAndMedicationItem('MED-AMOX-1', '500.00');

    $order = app(CreatePharmacyOrderUseCase::class)->execute([
        'patient_id' => $patientId,
        'approved_medicine_catalog_item_id' => $catalogItemId,
        'medication_code' => 'MED-AMOX-1',
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

    $charge = ServiceChargeModel::query()
        ->where('source_workflow_kind', ChargeSourceKind::PHARMACY_ORDER->value)
        ->where('source_workflow_id', $order['id'])
        ->first();

    expect($charge)->not->toBeNull()
        ->and($charge->status)->toBe(ServiceChargeStatus::PENDING_PAYMENT)
        ->and($charge->quantity)->toEqual(21.0)
        ->and($charge->netAmount()->toDecimalString())->toBe('10500.00'); // 500 * 21 = 10,500 TZS

    $authorization = app(ServiceAuthorizationReaderInterface::class)
        ->describe(ChargeSourceKind::PHARMACY_ORDER, $order['id']);

    expect($authorization->authorized)->toBeFalse()
        ->and($authorization->status)->toBe(ServiceChargeStatus::PENDING_PAYMENT->value)
        ->and($authorization->amountDue->toDecimalString())->toBe('10500.00');
});

it('exposes price, quantity, and pending payment status to clinician view over HTTP', function (): void {
    $doctor = clinicianUserForPharmacy();
    [$patientId, $catalogItemId] = seedPatientAndMedicationItem('MED-PARA-1', '200.00');

    $postRes = $this->actingAs($doctor)->postJson('/api/v1/clinician/orders/medication', [
        'patientId' => $patientId,
        'approvedMedicineCatalogItemId' => $catalogItemId,
        'medicationCode' => 'MED-PARA-1',
        'medicationName' => 'Paracetamol 500mg Tablet',
        'clinicalIndication' => 'Fever and headache',
        'dosageInstruction' => '1000mg TID for 5 days',
        'quantityPrescribed' => 30,
        'route' => 'oral',
        'frequency' => 'tid',
    ])->assertCreated();

    expect($postRes->json('data.paymentStatus'))->toBe('pending_payment')
        ->and($postRes->json('data.isAuthorized'))->toBeFalse()
        ->and($postRes->json('data.price'))->toEqual(6000) // 200 * 30 = 6,000
        ->and($postRes->json('data.unitPrice'))->toEqual(200)
        ->and($postRes->json('data.amountDue'))->toBe('6000.00');

    $listRes = $this->actingAs($doctor)->getJson("/api/v1/clinician/orders/medication?patientId={$patientId}")->assertOk();
    expect($listRes->json('data.0.paymentStatus'))->toBe('pending_payment')
        ->and($listRes->json('data.0.isAuthorized'))->toBeFalse()
        ->and($listRes->json('data.0.price'))->toEqual(6000);
});

it('lists prescribed medications in cashier queue and charge basket with itemized prices and correct total due', function (): void {
    $cashier = cashierUserForPharmacy();
    [$patientId, $catalogItemId] = seedPatientAndMedicationItem('MED-CIPRO-1', '1200.00');

    $order = app(CreatePharmacyOrderUseCase::class)->execute([
        'patient_id' => $patientId,
        'approved_medicine_catalog_item_id' => $catalogItemId,
        'medication_code' => 'MED-CIPRO-1',
        'medication_name' => 'Ciprofloxacin 500mg Tablet',
        'clinical_indication' => 'Acute urinary tract infection',
        'dosage_instruction' => '500mg BID for 5 days',
        'quantity_prescribed' => 10,
        'prescribed_unit' => 'tablet',
    ]);

    // Cashier queue
    $queue = $this->actingAs($cashier)->getJson('/api/v1/cashier/queue')->assertOk();
    $patientInQueue = collect($queue->json('data'))->firstWhere('patientId', $patientId);
    expect($patientInQueue)->not->toBeNull()
        ->and($patientInQueue['amountDue'])->toBe('12000.00'); // 1,200 * 10 = 12,000 TZS

    // Cashier charges basket
    $basket = $this->actingAs($cashier)->getJson("/api/v1/cashier/patients/{$patientId}/charges")->assertOk();
    expect($basket->json('meta.amountDue'))->toBe('12000.00')
        ->and($basket->json('data.0.sourceKind'))->toBe('pharmacy_order')
        ->and($basket->json('data.0.quantity'))->toEqual(10.0)
        ->and($basket->json('data.0.unitPrice'))->toBe('1200.00')
        ->and($basket->json('data.0.netAmount'))->toBe('12000.00')
        ->and($basket->json('data.0.isPayable'))->toBeTrue();
});

it('hides unpaid prescriptions from pharmacy workspace worklist and blocks preparation or dispensing', function (): void {
    $pharmacist = pharmacistUser();
    [$patientId, $catalogItemId] = seedPatientAndMedicationItem('MED-OMEP-1', '800.00');

    $order = app(CreatePharmacyOrderUseCase::class)->execute([
        'patient_id' => $patientId,
        'approved_medicine_catalog_item_id' => $catalogItemId,
        'medication_code' => 'MED-OMEP-1',
        'medication_name' => 'Omeprazole 20mg Capsule',
        'clinical_indication' => 'Gastroesophageal reflux disease',
        'dosage_instruction' => '20mg OD for 14 days',
        'quantity_prescribed' => 14,
        'prescribed_unit' => 'capsule',
    ]);

    // Pharmacy workspace worklist hides unpaid prescription
    $worklist = $this->actingAs($pharmacist)->getJson('/api/v1/pharmacy/orders')->assertOk();
    $found = collect($worklist->json('data'))->firstWhere('id', $order['id']);
    expect($found)->toBeNull();

    // Pharmacy status counts excludes unpaid order from active worklist
    $counts = $this->actingAs($pharmacist)->getJson('/api/v1/pharmacy/orders/status-counts')->assertOk();
    expect($counts->json('data.pending'))->toBe(0);

    // Attempt to prepare or dispense prescription directly fails validation
    $updateRes = $this->actingAs($pharmacist)->patchJson("/api/v1/pharmacy/orders/{$order['id']}/status", [
        'status' => 'in_preparation',
    ]);
    $updateRes->assertStatus(422);
    expect($updateRes->json('errors.status.0'))->toContain('payment has been verified');
});

it('authorizes prescription upon cashier payment settlement and opens pharmacy preparation and dispensation', function (): void {
    $cashier = cashierUserForPharmacy();
    $pharmacist = pharmacistUser();
    $doctor = clinicianUserForPharmacy();
    [$patientId, $catalogItemId] = seedPatientAndMedicationItem('MED-AZITH-1', '3500.00');

    $order = app(CreatePharmacyOrderUseCase::class)->execute([
        'patient_id' => $patientId,
        'approved_medicine_catalog_item_id' => $catalogItemId,
        'medication_code' => 'MED-AZITH-1',
        'medication_name' => 'Azithromycin 500mg Tablet',
        'clinical_indication' => 'Community acquired pneumonia',
        'dosage_instruction' => '500mg OD for 3 days',
        'quantity_prescribed' => 3,
        'prescribed_unit' => 'tablet',
    ]);

    $charge = ServiceChargeModel::query()
        ->where('source_workflow_kind', ChargeSourceKind::PHARMACY_ORDER->value)
        ->where('source_workflow_id', $order['id'])
        ->firstOrFail();

    // Cashier opens session and records cash payment (3 * 3,500 = 10,500 TZS -> 1,050,000 minor units)
    app(OpenCashierSessionUseCase::class)->execute($cashier->id, 5000000);
    app(RecordCashPaymentUseCase::class)->execute(
        patientId: $patientId,
        serviceChargeIds: [(string) $charge->id],
        tenderedAmountMinor: 1050000,
        idempotencyKey: (string) Str::uuid(),
        cashierUserId: $cashier->id,
    );

    // Reader reports authorized
    $reader = app(ServiceAuthorizationReaderInterface::class);
    expect($reader->isAuthorized(ChargeSourceKind::PHARMACY_ORDER, $order['id']))->toBeTrue();

    $auth = $reader->describe(ChargeSourceKind::PHARMACY_ORDER, $order['id']);
    expect($auth->authorized)->toBeTrue()
        ->and($auth->status)->toBe('authorized')
        ->and($auth->basis)->toBe(AuthorizationBasis::PAYMENT);

    // Pharmacy workspace now sees the authorized order
    $worklist = $this->actingAs($pharmacist)->getJson('/api/v1/pharmacy/orders')->assertOk();
    $found = collect($worklist->json('data'))->firstWhere('id', $order['id']);
    expect($found)->not->toBeNull()
        ->and($found['isAuthorized'])->toBeTrue()
        ->and($found['paymentStatus'])->toBe('authorized');

    // Pharmacist can now transition to preparation
    $this->actingAs($pharmacist)->patchJson("/api/v1/pharmacy/orders/{$order['id']}/status", [
        'status' => 'in_preparation',
    ])->assertOk();

    // Clinician view updates to show authorized payment status
    $clinicianView = $this->actingAs($doctor)->getJson("/api/v1/clinician/orders/medication?patientId={$patientId}")->assertOk();
    expect($clinicianView->json('data.0.paymentStatus'))->toBe('authorized')
        ->and($clinicianView->json('data.0.isAuthorized'))->toBeTrue()
        ->and($clinicianView->json('data.0.amountDue'))->toBe('0.00');
});

it('cancels pending service charge when prescription is cancelled', function (): void {
    $doctor = clinicianUserForPharmacy();
    [$patientId, $catalogItemId] = seedPatientAndMedicationItem('MED-CANCEL-1', '1500.00');

    $order = app(CreatePharmacyOrderUseCase::class)->execute([
        'patient_id' => $patientId,
        'approved_medicine_catalog_item_id' => $catalogItemId,
        'medication_code' => 'MED-CANCEL-1',
        'medication_name' => 'Metronidazole 400mg Tablet',
        'clinical_indication' => 'Amoebiasis infection',
        'dosage_instruction' => '400mg TID for 5 days',
        'quantity_prescribed' => 15,
        'prescribed_unit' => 'tablet',
    ]);

    $charge = ServiceChargeModel::query()
        ->where('source_workflow_kind', ChargeSourceKind::PHARMACY_ORDER->value)
        ->where('source_workflow_id', $order['id'])
        ->firstOrFail();

    expect($charge->status)->toBe(ServiceChargeStatus::PENDING_PAYMENT);

    // Clinician cancels order
    $this->actingAs($doctor)->postJson("/api/v1/clinician/orders/medication/{$order['id']}/cancel", [
        'action' => 'cancel',
        'reason' => 'Patient had adverse drug allergy',
    ])->assertOk();

    $charge->refresh();
    expect($charge->status)->toBe(ServiceChargeStatus::CANCELLED);
});
