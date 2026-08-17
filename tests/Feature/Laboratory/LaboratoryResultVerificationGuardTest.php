<?php

/**
 * Two-person rule on laboratory result verification.
 *
 * The rule used to be checked in the controller *after*
 * VerifyLaboratoryOrderResultUseCase had already committed the verification and
 * recorded the patient-flow transition. The caller got a 422 while the database
 * held a verified, released result — a refusal that silently succeeded.
 *
 * These tests pin both halves: the refusal must leave no trace, and a genuine
 * second scientist must still get through.
 *
 * Permissions here are the ones the routes actually declare in routes/api.php
 * (`lab.order`, `lab.sample.collect`, `lab.result.verify`), not the
 * `laboratory.orders.*` names used elsewhere in the suite.
 */

use App\Http\Middleware\EnsureMappedFacilitySubscriptionEntitlement;
use App\Models\Permission;
use App\Models\User;
use App\Modules\Laboratory\Infrastructure\Models\LaboratoryOrderAuditLogModel;
use App\Modules\Laboratory\Infrastructure\Models\LaboratoryOrderModel;
use App\Modules\Patient\Infrastructure\Models\PatientModel;
use App\Modules\Platform\Infrastructure\Models\ClinicalCatalogItemModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutMiddleware(EnsureMappedFacilitySubscriptionEntitlement::class);
    seedVerificationGuardCatalogItem();
});

// Fixtures are defined locally rather than reused from LaboratoryOrderApiTest,
// whose helpers are file-scoped and so unavailable when this file runs alone.

function seedVerificationGuardCatalogItem(): ClinicalCatalogItemModel
{
    return ClinicalCatalogItemModel::query()->firstOrCreate(
        [
            'tenant_id' => null,
            'facility_id' => null,
            'catalog_type' => 'lab_test',
            'code' => 'LOINC:57021-8',
        ],
        [
            'name' => 'Complete Blood Count',
            'department_id' => null,
            'category' => 'hematology',
            'unit' => null,
            'description' => 'Verification guard test fixture',
            'metadata' => null,
            'status' => 'active',
            'status_reason' => null,
        ],
    );
}

function makeVerificationGuardPatient(): PatientModel
{
    return PatientModel::query()->create([
        'patient_number' => 'PT'.now()->format('Ymd').strtoupper(Str::random(6)),
        'first_name' => 'Amina',
        'middle_name' => null,
        'last_name' => 'Moshi',
        'gender' => 'female',
        'date_of_birth' => '1996-04-21',
        'phone' => '+255700000001',
        'email' => null,
        'national_id' => null,
        'country_code' => 'TZ',
        'region' => null,
        'district' => null,
        'address_line' => null,
        'next_of_kin_name' => null,
        'next_of_kin_phone' => null,
        'status' => 'active',
        'status_reason' => null,
    ]);
}

/**
 * @return array<string, mixed>
 */
function verificationGuardOrderPayload(string $patientId): array
{
    return [
        'patientId' => $patientId,
        'orderedByUserId' => null,
        'orderedAt' => now()->toDateTimeString(),
        'testCode' => 'LOINC:57021-8',
        'testName' => 'Complete Blood Count',
        'priority' => 'routine',
        'specimenType' => 'Blood',
        'clinicalNotes' => 'Suspected infection',
    ];
}

/**
 * @param  array<int, string>  $abilities
 */
function makeLaboratoryUserWithAbilities(array $abilities): User
{
    $user = User::factory()->create();

    foreach ($abilities as $ability) {
        Permission::query()->firstOrCreate(['name' => $ability]);
        $user->givePermissionTo($ability);
    }

    return $user;
}

function walkVerificationGuardOrderToCompleted($test, User $user, string $orderId, string $resultSummary): void
{
    foreach (['collected', 'in_progress'] as $status) {
        $test->actingAs($user)
            ->patchJson('/api/v1/laboratory-orders/'.$orderId.'/status', [
                'status' => $status,
                'reason' => null,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', $status);
    }

    $test->actingAs($user)
        ->patchJson('/api/v1/laboratory-orders/'.$orderId.'/status', [
            'status' => 'completed',
            'reason' => null,
            'resultSummary' => $resultSummary,
        ])
        ->assertOk();
}

it('refuses self-verification before writing anything', function (): void {
    $orderer = makeLaboratoryUserWithAbilities([
        'lab.order',
        'laboratory.orders.read',
        'lab.sample.collect',
        'lab.result.verify',
    ]);
    $patient = makeVerificationGuardPatient();

    $created = $this->actingAs($orderer)
        ->postJson('/api/v1/laboratory-orders', verificationGuardOrderPayload($patient->id))
        ->assertCreated()
        ->json('data');

    walkVerificationGuardOrderToCompleted($this, $orderer, $created['id'], 'CBC within expected range');

    $this->actingAs($orderer)
        ->patchJson('/api/v1/laboratory-orders/'.$created['id'].'/verify', [
            'verificationNote' => 'Attempting to verify my own order.',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['verification']);

    // The point of the fix: a refused verification leaves no trace at all.
    $record = LaboratoryOrderModel::query()->find($created['id']);
    expect($record?->verified_at)->toBeNull();
    expect($record?->verified_by_user_id)->toBeNull();
    expect($record?->verification_note)->toBeNull();

    expect(
        LaboratoryOrderAuditLogModel::query()
            ->where('laboratory_order_id', $created['id'])
            ->where('action', 'laboratory-order.result.verified')
            ->count()
    )->toBe(0);
});

it('allows a second scientist to verify a completed result', function (): void {
    $orderer = makeLaboratoryUserWithAbilities([
        'lab.order',
        'laboratory.orders.read',
        'lab.sample.collect',
    ]);
    $verifier = makeLaboratoryUserWithAbilities([
        'laboratory.orders.read',
        'lab.result.verify',
    ]);
    $patient = makeVerificationGuardPatient();

    $created = $this->actingAs($orderer)
        ->postJson('/api/v1/laboratory-orders', verificationGuardOrderPayload($patient->id))
        ->assertCreated()
        ->json('data');

    walkVerificationGuardOrderToCompleted($this, $orderer, $created['id'], 'CBC within expected range');

    $this->actingAs($verifier)
        ->patchJson('/api/v1/laboratory-orders/'.$created['id'].'/verify', [
            'verificationNote' => 'Reviewed and released.',
        ])
        ->assertOk()
        ->assertJsonPath('data.verifiedByUserId', $verifier->id);

    $record = LaboratoryOrderModel::query()->find($created['id']);
    expect($record?->verified_at)->not->toBeNull();
    expect($record?->verified_by_user_id)->toBe($verifier->id);

    expect(
        LaboratoryOrderAuditLogModel::query()
            ->where('laboratory_order_id', $created['id'])
            ->where('action', 'laboratory-order.result.verified')
            ->count()
    )->toBe(1);
});

it('still refuses verification of a result that was never completed', function (): void {
    // The actor guard runs first, so make sure it did not shadow the state
    // preconditions for a legitimate second scientist.
    $orderer = makeLaboratoryUserWithAbilities(['lab.order', 'laboratory.orders.read']);
    $verifier = makeLaboratoryUserWithAbilities(['laboratory.orders.read', 'lab.result.verify']);
    $patient = makeVerificationGuardPatient();

    $created = $this->actingAs($orderer)
        ->postJson('/api/v1/laboratory-orders', verificationGuardOrderPayload($patient->id))
        ->assertCreated()
        ->json('data');

    $this->actingAs($verifier)
        ->patchJson('/api/v1/laboratory-orders/'.$created['id'].'/verify', [
            'verificationNote' => 'Should fail before completion.',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['verification']);

    expect(LaboratoryOrderModel::query()->find($created['id'])?->verified_at)->toBeNull();
});
