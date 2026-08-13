<?php

use App\Models\User;
use App\Http\Middleware\EnsureFacilitySubscriptionEntitlement;
use App\Http\Middleware\EnsureMappedFacilitySubscriptionEntitlement;
use App\Modules\Billing\Infrastructure\Models\BillingPayerContractModel;
use App\Modules\Billing\Infrastructure\Models\PatientInsuranceAuditEventModel;
use App\Modules\Billing\Infrastructure\Models\PatientInsuranceModel;
use App\Modules\Patient\Infrastructure\Models\PatientModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutMiddleware(EnsureFacilitySubscriptionEntitlement::class);
    $this->withoutMiddleware(EnsureMappedFacilitySubscriptionEntitlement::class);
});

function makePatientInsuranceUser(array $permissions = []): User
{
    $user = User::factory()->create();
    foreach ($permissions as $permission) {
        $user->givePermissionTo($permission);
    }

    return $user;
}

function createPatientForInsurance(): PatientModel
{
    return PatientModel::query()->create([
        'patient_number' => 'PT-INS-'.strtoupper(Str::random(6)),
        'first_name' => 'Insurance',
        'last_name' => 'Patient',
        'gender' => 'female',
        'date_of_birth' => '1990-05-10',
        'country_code' => 'TZ',
        'status' => 'active',
    ]);
}

function createInsurancePayerContract(): BillingPayerContractModel
{
    return BillingPayerContractModel::query()->create([
        'contract_code' => 'NHIF-STD-'.strtoupper(Str::random(4)),
        'contract_name' => 'NHIF Standard',
        'payer_type' => 'insurance',
        'payer_name' => 'NHIF',
        'payer_plan_code' => 'STD',
        'payer_plan_name' => 'Standard',
        'currency_code' => 'TZS',
        'default_coverage_percent' => 80,
        'default_copay_type' => 'percentage',
        'default_copay_value' => 20,
        'requires_pre_authorization' => false,
        'effective_from' => now()->subDay(),
        'status' => 'active',
    ]);
}

function createGovernmentPayerContract(): BillingPayerContractModel
{
    return BillingPayerContractModel::query()->create([
        'contract_code' => 'NHIF-GOV-'.strtoupper(Str::random(4)),
        'contract_name' => 'NHIF Government Scheme',
        'payer_type' => 'government',
        'payer_name' => 'National Health Insurance Fund (NHIF)',
        'payer_plan_code' => 'NHIF-GOV',
        'payer_plan_name' => 'NHIF Benefit Package',
        'currency_code' => 'TZS',
        'default_coverage_percent' => 100,
        'default_copay_type' => 'none',
        'requires_pre_authorization' => true,
        'effective_from' => now()->subDay(),
        'status' => 'active',
    ]);
}

it('stores patient insurance records with payer contract mapping and audit event', function (): void {
    $user = makePatientInsuranceUser(['patients.insurance.manage', 'patients.insurance.read']);
    $patient = createPatientForInsurance();
    $contract = createInsurancePayerContract();

    $this->actingAs($user)
        ->postJson('/api/v1/patients/'.$patient->id.'/insurance', [
            'billingPayerContractId' => $contract->id,
            'insuranceType' => 'insurance',
            'insuranceProvider' => 'NHIF',
            'providerCode' => 'nhif',
            'planName' => 'Standard',
            'memberId' => 'NHIF-12345',
            'policyNumber' => 'POL-2026-1',
            'cardNumber' => 'NIDA-19900510-12345',
            'effectiveDate' => now()->subDay()->toDateString(),
            'verificationStatus' => 'unverified',
        ])
        ->assertCreated()
        ->assertJsonPath('data.patientId', $patient->id)
        ->assertJsonPath('data.billingPayerContractId', $contract->id)
        ->assertJsonPath('data.memberId', 'NHIF-12345')
        ->assertJsonPath('data.cardNumber', 'NIDA-19900510-12345')
        ->assertJsonPath('data.verificationStatus', 'unverified');

    expect(PatientInsuranceModel::query()->where('patient_id', $patient->id)->count())->toBe(1)
        ->and(PatientInsuranceAuditEventModel::query()->where('patient_id', $patient->id)->count())->toBe(1);
});

it('stores identifier-only coverage without manual benefit fields', function (): void {
    $user = makePatientInsuranceUser(['patients.insurance.manage', 'patients.insurance.read']);
    $patient = createPatientForInsurance();

    $this->actingAs($user)
        ->postJson('/api/v1/patients/'.$patient->id.'/insurance', [
            'cardNumber' => 'NIDA-19900510-12345',
        ])
        ->assertCreated()
        ->assertJsonPath('data.patientId', $patient->id)
        ->assertJsonPath('data.insuranceType', 'insurance')
        ->assertJsonPath('data.insuranceProvider', null)
        ->assertJsonPath('data.memberId', null)
        ->assertJsonPath('data.cardNumber', 'NIDA-19900510-12345')
        ->assertJsonPath('data.verificationStatus', 'unverified');

    $record = PatientInsuranceModel::query()->where('patient_id', $patient->id)->firstOrFail();

    expect($record->insurance_provider)->toBeNull()
        ->and($record->coverage_level)->toBeNull()
        ->and($record->copay_percent)->toBeNull();
});

it('stores insurance member number without requiring NIDA', function (): void {
    $user = makePatientInsuranceUser(['patients.insurance.manage', 'patients.insurance.read']);
    $patient = createPatientForInsurance();

    $this->actingAs($user)
        ->postJson('/api/v1/patients/'.$patient->id.'/insurance', [
            'insuranceProvider' => 'NHIF',
            'memberId' => 'NHIF-ONLY-12345',
        ])
        ->assertCreated()
        ->assertJsonPath('data.patientId', $patient->id)
        ->assertJsonPath('data.insuranceProvider', 'NHIF')
        ->assertJsonPath('data.memberId', 'NHIF-ONLY-12345')
        ->assertJsonPath('data.cardNumber', null);
});

it('hard blocks duplicate insurance member id when configured unique', function (): void {
    config(['patient_insurance.unique_member_id' => true]);

    $user = makePatientInsuranceUser(['patients.insurance.manage', 'patients.insurance.read']);
    $firstPatient = createPatientForInsurance();
    $secondPatient = createPatientForInsurance();

    $this->actingAs($user)
        ->postJson('/api/v1/patients/'.$firstPatient->id.'/insurance', [
            'insuranceProvider' => 'NHIF',
            'memberId' => 'NHIF-UNIQUE-12345',
        ])
        ->assertCreated();

    $this->actingAs($user)
        ->postJson('/api/v1/patients/'.$secondPatient->id.'/insurance', [
            'insuranceProvider' => 'NHIF',
            'memberId' => 'nhif unique 12345',
        ])
        ->assertConflict()
        ->assertJsonPath('duplicates.0.patientId', $firstPatient->id);
});

it('lists lean Tanzania-ready coverage options including government payer contracts', function (): void {
    $user = makePatientInsuranceUser(['patients.insurance.read']);
    createGovernmentPayerContract();

    $response = $this->actingAs($user)
        ->getJson('/api/v1/patients/insurance-options')
        ->assertOk();

    $response->assertJsonFragment([
        'code' => 'nhif',
        'insuranceType' => 'government',
    ]);
    $response->assertJsonFragment([
        'payerType' => 'government',
        'payerPlanName' => 'NHIF Benefit Package',
    ]);
});

it('verifies a patient insurance record', function (): void {
    $user = makePatientInsuranceUser(['patients.insurance.verify', 'patients.insurance.read']);
    $patient = createPatientForInsurance();
    $record = PatientInsuranceModel::query()->create([
        'patient_id' => $patient->id,
        'insurance_type' => 'insurance',
        'insurance_provider' => 'NHIF',
        'member_id' => 'NHIF-555',
        'effective_date' => now()->subDay(),
        'status' => 'active',
        'verification_status' => 'unverified',
    ]);

    $this->actingAs($user)
        ->patchJson('/api/v1/patients/'.$patient->id.'/insurance/'.$record->id.'/verify', [
            'verificationStatus' => 'verified',
            'verificationSource' => 'manual',
            'verificationReference' => 'VRF-001',
        ])
        ->assertOk()
        ->assertJsonPath('data.verificationStatus', 'verified')
        ->assertJsonPath('data.verificationReference', 'VRF-001');

    expect($record->fresh()->verification_status)->toBe('verified');
});
