<?php

use App\Http\Middleware\EnsureFacilitySubscriptionEntitlement;
use App\Http\Middleware\EnsureMappedFacilitySubscriptionEntitlement;
use App\Models\User;
use App\Modules\Encounter\Infrastructure\Models\EncounterAuditLogModel;
use App\Modules\Encounter\Infrastructure\Models\EncounterModel;
use App\Modules\Laboratory\Infrastructure\Models\LaboratoryOrderModel;
use App\Modules\Patient\Infrastructure\Models\PatientModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutMiddleware([
        EnsureMappedFacilitySubscriptionEntitlement::class,
        EnsureFacilitySubscriptionEntitlement::class,
    ]);
    config()->set('feature_flags.flags.platform.multi_tenant_isolation.enabled', false);
});

/**
 * Regression coverage for the C-5 acknowledgement-quality fix (decided
 * 2026-07-08, no policy change — see
 * reports/clinical-note-audit/15-critical-system-integrity-review.md and
 * 16-remediation-options-c8-c9-c10-c12.md): pending_orders/unbilled_services
 * remain warn-only and non-blocking, but the acknowledgement itself is no
 * longer a rubber stamp — a meaningful reason is required, the specific
 * outstanding items are surfaced (not just a count), and the audit trail
 * preserves which items were outstanding at close time.
 */
function closeAckPatient(): PatientModel
{
    return PatientModel::query()->create([
        'patient_number' => 'PTACK'.now()->format('Ymd').strtoupper(Str::random(6)),
        'first_name' => 'Close', 'last_name' => 'Acknowledgement', 'gender' => 'female',
        'date_of_birth' => '1990-09-09', 'phone' => '+255700000022', 'country_code' => 'TZ',
        'status' => 'active',
    ]);
}

function closeAckUser(): User
{
    $user = User::factory()->create();
    foreach (['medical.records.read', 'medical.records.create', 'medical.records.finalize'] as $permission) {
        $user->givePermissionTo($permission);
    }

    return $user;
}

function closeAckEncounter(string $patientId): EncounterModel
{
    return EncounterModel::query()->create([
        'encounter_number' => 'ENCACK'.strtoupper(Str::random(8)),
        'patient_id' => $patientId,
        'status' => 'opened',
        'type' => 'outpatient',
        'opened_at' => now(),
    ]);
}

function closeAckSignedNote(User $user, string $patientId, string $encounterId): void
{
    $created = test()->actingAs($user)
        ->postJson('/api/v1/medical-records', [
            'patientId' => $patientId,
            'encounterId' => $encounterId,
            'encounterAt' => now()->toDateTimeString(),
            'recordType' => 'consultation_note',
            'subjective' => 'Fixture note.',
        ])
        ->assertCreated()
        ->json('data');

    test()->actingAs($user)
        ->patchJson('/api/v1/medical-records/'.$created['id'].'/status', ['status' => 'finalized'])
        ->assertOk();
}

function closeAckLabOrder(string $patientId, string $encounterId, string $testName): LaboratoryOrderModel
{
    return LaboratoryOrderModel::query()->create([
        'order_number' => 'LABACK'.strtoupper(Str::random(8)),
        'patient_id' => $patientId,
        'encounter_id' => $encounterId,
        'ordered_at' => now()->subMinutes(30),
        'test_code' => 'LOINC:57021-8',
        'test_name' => $testName,
        'priority' => 'routine',
        'specimen_type' => 'Blood',
        'status' => 'ordered',
        'entry_state' => 'active',
    ]);
}

it('rejects a trivial close-out reason when acknowledging warnings', function (): void {
    $user = closeAckUser();
    $patient = closeAckPatient();
    $encounter = closeAckEncounter($patient->id);
    closeAckSignedNote($user, $patient->id, $encounter->id);
    closeAckLabOrder($patient->id, $encounter->id, 'Complete Blood Count');

    $response = $this->actingAs($user)
        ->patchJson('/api/v1/encounters/'.$encounter->id.'/status', [
            'status' => 'closed',
            'disposition' => 'discharged',
            'acknowledgeCloseGaps' => true,
            'reason' => 'n/a',
        ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'ENCOUNTER_CLOSE_BLOCKED');

    expect($response->json('message'))->toContain('specific close-out reason');
    expect(EncounterModel::query()->find($encounter->id)->status)->not->toBe('closed');
});

it('rejects a close-out reason under the minimum length even if not on the placeholder list', function (): void {
    $user = closeAckUser();
    $patient = closeAckPatient();
    $encounter = closeAckEncounter($patient->id);
    closeAckSignedNote($user, $patient->id, $encounter->id);
    closeAckLabOrder($patient->id, $encounter->id, 'Complete Blood Count');

    $this->actingAs($user)
        ->patchJson('/api/v1/encounters/'.$encounter->id.'/status', [
            'status' => 'closed',
            'disposition' => 'discharged',
            'acknowledgeCloseGaps' => true,
            'reason' => 'too busy',
        ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'ENCOUNTER_CLOSE_BLOCKED');
});

it('accepts a meaningful close-out reason and closes with warnings acknowledged', function (): void {
    $user = closeAckUser();
    $patient = closeAckPatient();
    $encounter = closeAckEncounter($patient->id);
    closeAckSignedNote($user, $patient->id, $encounter->id);
    closeAckLabOrder($patient->id, $encounter->id, 'Complete Blood Count');

    $this->actingAs($user)
        ->patchJson('/api/v1/encounters/'.$encounter->id.'/status', [
            'status' => 'closed',
            'disposition' => 'discharged',
            'acknowledgeCloseGaps' => true,
            'reason' => 'Culture pending, will follow up by phone once resulted.',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'closed');
});

it('includes itemized pending-order details in the close-readiness response, not just a count', function (): void {
    $user = closeAckUser();
    $patient = closeAckPatient();
    $encounter = closeAckEncounter($patient->id);
    closeAckSignedNote($user, $patient->id, $encounter->id);
    $labOrder = closeAckLabOrder($patient->id, $encounter->id, 'Complete Blood Count');

    $workspace = $this->actingAs($user)
        ->getJson('/api/v1/encounters/'.$encounter->id.'?view=workspace')
        ->assertOk()
        ->json('data');

    $pendingItem = collect($workspace['closeReadiness']['items'])->firstWhere('id', 'pending_orders');

    expect($pendingItem['count'])->toBe(1);
    expect($pendingItem['details'])->toHaveCount(1);
    expect($pendingItem['details'][0]['id'])->toBe($labOrder->id);
    expect($pendingItem['details'][0]['label'])->toBe('Complete Blood Count');
});

it('records itemized outstanding order ids in the close audit log, not just counts', function (): void {
    $user = closeAckUser();
    $patient = closeAckPatient();
    $encounter = closeAckEncounter($patient->id);
    closeAckSignedNote($user, $patient->id, $encounter->id);
    $labOrder = closeAckLabOrder($patient->id, $encounter->id, 'Complete Blood Count');

    $this->actingAs($user)
        ->patchJson('/api/v1/encounters/'.$encounter->id.'/status', [
            'status' => 'closed',
            'disposition' => 'discharged',
            'acknowledgeCloseGaps' => true,
            'reason' => 'Culture pending, will follow up by phone once resulted.',
        ])
        ->assertOk();

    $log = EncounterAuditLogModel::query()
        ->where('encounter_id', $encounter->id)
        ->where('action', 'encounter.closed')
        ->first();

    expect($log)->not->toBeNull();
    $outstanding = $log->metadata['close_readiness']['outstanding_items']['pending_orders'] ?? null;
    expect($outstanding)->toBe([$labOrder->id]);
});
