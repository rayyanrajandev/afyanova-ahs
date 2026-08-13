<?php

use App\Models\User;
use App\Modules\Patient\Infrastructure\Models\PatientModel;
use App\Modules\Platform\Infrastructure\Models\ClinicalCatalogItemModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * RBAC_Remediation_Plan.md Task 4.3: ClinicalProcedureOrderController::updateStatus()
 * used to call Gate::authorize('perform', $order) AFTER the status use case had
 * already executed and committed — but no Gate::policy()/Gate::define() existed
 * for that ability, so it always threw, meaning every legitimate call succeeded
 * server-side and still returned a 403 to the client. The real, working check is
 * UpdateClinicalProcedureOrderStatusRequest::authorize() (clinical-procedure.perform),
 * which already runs before the use case — the post-write Gate call was purely
 * redundant dead code and has been removed. This test proves a legitimately
 * authorized call now succeeds end-to-end instead of 403ing after the fact.
 */
function clinicalProcedureOrderPatient(): PatientModel
{
    return PatientModel::query()->create([
        'patient_number' => 'PTCPO'.now()->format('Ymd').strtoupper(Str::random(6)),
        'first_name' => 'Zawadi',
        'last_name' => 'Mwakalinga',
        'gender' => 'female',
        'date_of_birth' => '1990-03-10',
        'phone' => '+255700000099',
        'country_code' => 'TZ',
        'status' => 'active',
    ]);
}

function clinicalProcedureOrderCatalogItem(string $code): ClinicalCatalogItemModel
{
    return ClinicalCatalogItemModel::query()->create([
        'catalog_type' => 'clinical_procedure',
        'code' => $code,
        'name' => 'Test Clinical Procedure',
        'unit' => 'procedure',
        'status' => 'active',
    ]);
}

it('updates clinical procedure order status without a spurious 403 for an authorized user', function (): void {
    $user = User::factory()->create();
    $user->givePermissionTo('clinical-procedure.order');
    $user->givePermissionTo('clinical-procedure.perform');
    $patient = clinicalProcedureOrderPatient();
    clinicalProcedureOrderCatalogItem('CP-TEST-001');

    $created = $this->actingAs($user)
        ->postJson('/api/v1/clinical-procedure-orders', [
            'patientId' => $patient->id,
            'procedureSetting' => 'outpatient',
            'procedureCode' => 'CP-TEST-001',
        ])
        ->assertCreated()
        ->json('data');

    expect($created['status'])->toBe('ordered');

    $this->actingAs($user)
        ->patchJson('/api/v1/clinical-procedure-orders/'.$created['id'].'/status', [
            'status' => 'scheduled',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'scheduled');
});

it('forbids updating clinical procedure order status without clinical-procedure.perform permission', function (): void {
    $creator = User::factory()->create();
    $creator->givePermissionTo('clinical-procedure.order');
    $creator->givePermissionTo('clinical-procedure.perform');
    $patient = clinicalProcedureOrderPatient();
    clinicalProcedureOrderCatalogItem('CP-TEST-002');

    $created = $this->actingAs($creator)
        ->postJson('/api/v1/clinical-procedure-orders', [
            'patientId' => $patient->id,
            'procedureSetting' => 'outpatient',
            'procedureCode' => 'CP-TEST-002',
        ])
        ->assertCreated()
        ->json('data');

    $noPermissionUser = User::factory()->create();

    $this->actingAs($noPermissionUser)
        ->patchJson('/api/v1/clinical-procedure-orders/'.$created['id'].'/status', [
            'status' => 'scheduled',
        ])
        ->assertForbidden();
});
