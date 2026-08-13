<?php

use App\Models\User;
use App\Modules\Platform\Infrastructure\Models\FacilityModel;
use App\Modules\Platform\Infrastructure\Models\MultiFacilityRolloutAuditLogModel;
use App\Modules\Platform\Infrastructure\Models\MultiFacilityRolloutCheckpointModel;
use App\Modules\Platform\Infrastructure\Models\MultiFacilityRolloutPlanModel;
use App\Modules\Platform\Infrastructure\Models\TenantModel;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutMiddleware(ValidateCsrfToken::class);
});

function makeMultiFacilityRolloutActor(array $permissions = []): User
{
    $user = User::factory()->create();

    foreach ($permissions as $permission) {
        $user->givePermissionTo($permission);
    }

    return $user;
}

/**
 * @return array{tenant: TenantModel, facility: FacilityModel}
 */
function makeRolloutFacilityContext(string $tenantCode = 'TEN-ROL', string $facilityCode = 'FAC-ROL'): array
{
    $tenant = TenantModel::query()->create([
        'code' => strtoupper($tenantCode),
        'name' => 'Rollout Tenant '.strtoupper($tenantCode),
        'country_code' => 'TZ',
        'status' => 'active',
    ]);

    $facility = FacilityModel::query()->create([
        'tenant_id' => $tenant->id,
        'code' => strtoupper($facilityCode),
        'name' => 'Rollout Facility '.strtoupper($facilityCode),
        'facility_type' => 'hospital',
        'timezone' => 'Africa/Dar_es_Salaam',
        'status' => 'active',
    ]);

    return [
        'tenant' => $tenant,
        'facility' => $facility,
    ];
}

function seedMultiFacilityRolloutPlan(FacilityModel $facility, array $overrides = []): MultiFacilityRolloutPlanModel
{
    return MultiFacilityRolloutPlanModel::query()->create(array_merge([
        'tenant_id' => (string) $facility->tenant_id,
        'facility_id' => (string) $facility->id,
        'rollout_code' => 'ROL-SEED-001',
        'status' => 'draft',
        'target_go_live_at' => now()->addDay(),
        'actual_go_live_at' => null,
        'owner_user_id' => null,
        'rollback_required' => false,
        'rollback_reason' => null,
        'metadata' => ['seeded' => true],
    ], $overrides));
}

it('requires authentication for multi-facility rollout endpoints', function (): void {
    $facilityContext = makeRolloutFacilityContext();

    $this->getJson('/api/v1/platform/admin/facility-rollouts')->assertUnauthorized();

    $this->postJson('/api/v1/platform/admin/facility-rollouts', [
        'facilityId' => $facilityContext['facility']->id,
        'rolloutCode' => 'ROL-AUTH-001',
    ])->assertUnauthorized();
});

it('forbids multi-facility rollout endpoints without required permissions', function (): void {
    $actor = makeMultiFacilityRolloutActor();
    $facilityContext = makeRolloutFacilityContext('TEN-NO-PERM', 'FAC-NO-PERM');

    $this->actingAs($actor)
        ->getJson('/api/v1/platform/admin/facility-rollouts')
        ->assertForbidden();

    $this->actingAs($actor)
        ->postJson('/api/v1/platform/admin/facility-rollouts', [
            'facilityId' => $facilityContext['facility']->id,
            'rolloutCode' => 'ROL-NO-PERM-001',
        ])
        ->assertForbidden();
});

it('creates a rollout plan and writes audit log when authorized', function (): void {
    $actor = makeMultiFacilityRolloutActor(['platform.multi-facility.manage-rollouts']);
    $facilityContext = makeRolloutFacilityContext('TEN-CREATE', 'FAC-CREATE');

    $response = $this->actingAs($actor)
        ->postJson('/api/v1/platform/admin/facility-rollouts', [
            'facilityId' => $facilityContext['facility']->id,
            'rolloutCode' => 'rol-create-001',
            'status' => 'ready',
            'targetGoLiveAt' => now()->addDay()->toIso8601String(),
            'ownerUserId' => $actor->id,
            'metadata' => ['wave' => 'pilot-a'],
        ])
        ->assertCreated()
        ->assertJsonPath('data.rolloutCode', 'ROL-CREATE-001')
        ->assertJsonPath('data.status', 'ready')
        ->assertJsonPath('data.facilityId', $facilityContext['facility']->id)
        ->assertJsonCount(8, 'data.checkpoints')
        ->assertJsonPath('data.checkpoints.0.status', 'not_started');

    $rolloutId = $response->json('data.id');

    expect(MultiFacilityRolloutPlanModel::query()->where('id', $rolloutId)->exists())->toBeTrue();
    expect(MultiFacilityRolloutCheckpointModel::query()->where('rollout_plan_id', $rolloutId)->count())->toBe(8);
    expect(
        MultiFacilityRolloutAuditLogModel::query()
            ->where('rollout_plan_id', $rolloutId)
            ->where('action', 'platform.multi-facility-rollout.plan.created')
            ->exists()
    )->toBeTrue();
});

it('lists and shows rollout plans when read permission is granted', function (): void {
    $actor = makeMultiFacilityRolloutActor(['platform.multi-facility.read']);
    $facilityContext = makeRolloutFacilityContext('TEN-READ', 'FAC-READ');
    $plan = seedMultiFacilityRolloutPlan($facilityContext['facility'], [
        'rollout_code' => 'ROL-READ-001',
        'status' => 'active',
    ]);
    MultiFacilityRolloutCheckpointModel::query()->create([
        'rollout_plan_id' => $plan->id,
        'checkpoint_code' => 'PATIENT_REGISTRATION_READY',
        'checkpoint_name' => 'Patient registration workflow ready',
        'status' => 'passed',
        'decision_notes' => 'Reception workflow validated.',
    ]);

    $this->actingAs($actor)
        ->getJson('/api/v1/platform/admin/facility-rollouts?q=ROL-READ-001')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.id', $plan->id)
        ->assertJsonPath('data.0.rolloutCode', 'ROL-READ-001')
        ->assertJsonPath('data.0.checkpoints.0.checkpointCode', 'PATIENT_REGISTRATION_READY');

    $this->actingAs($actor)
        ->getJson('/api/v1/platform/admin/facility-rollouts/'.$plan->id)
        ->assertOk()
        ->assertJsonPath('data.id', $plan->id)
        ->assertJsonPath('data.checkpoints.0.checkpointCode', 'PATIENT_REGISTRATION_READY')
        ->assertJsonPath('data.incidents', []);
});

it('updates rollout plan metadata and checkpoints when authorized', function (): void {
    $actor = makeMultiFacilityRolloutActor(['platform.multi-facility.manage-rollouts']);
    $facilityContext = makeRolloutFacilityContext('TEN-UPD', 'FAC-UPD');
    $plan = seedMultiFacilityRolloutPlan($facilityContext['facility'], [
        'rollout_code' => 'ROL-UPD-001',
        'status' => 'draft',
        'target_go_live_at' => null,
    ]);

    $this->actingAs($actor)
        ->patchJson('/api/v1/platform/admin/facility-rollouts/'.$plan->id, [
            'status' => 'ready',
            'targetGoLiveAt' => now()->addDays(2)->toIso8601String(),
            'ownerUserId' => $actor->id,
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'ready')
        ->assertJsonPath('data.ownerUserId', $actor->id);

    $statusLog = MultiFacilityRolloutAuditLogModel::query()
        ->where('rollout_plan_id', $plan->id)
        ->where('action', 'platform.multi-facility-rollout.plan.updated')
        ->latest('created_at')
        ->first();

    expect($statusLog)->not->toBeNull();
    expect($statusLog?->metadata['transition']['from'] ?? null)->toBe('draft');
    expect($statusLog?->metadata['transition']['to'] ?? null)->toBe('ready');
    expect($statusLog?->metadata['target_go_live_required'] ?? null)->toBeTrue();
    expect($statusLog?->metadata['target_go_live_provided'] ?? null)->toBeTrue();

    $this->actingAs($actor)
        ->patchJson('/api/v1/platform/admin/facility-rollouts/'.$plan->id.'/checkpoints', [
            'checkpoints' => [
                [
                    'checkpointCode' => 'INFRA_READY',
                    'checkpointName' => 'Infrastructure Ready',
                    'status' => 'passed',
                    'decisionNotes' => 'All systems validated.',
                ],
                [
                    'checkpointCode' => 'TRAINING_READY',
                    'checkpointName' => 'Training Ready',
                    'status' => 'in_progress',
                ],
            ],
        ])
        ->assertOk()
        ->assertJsonPath('data.checkpoints.0.checkpointCode', 'INFRA_READY')
        ->assertJsonPath('data.checkpoints.1.checkpointCode', 'TRAINING_READY');

    expect(
        MultiFacilityRolloutAuditLogModel::query()
            ->where('rollout_plan_id', $plan->id)
            ->where('action', 'platform.multi-facility-rollout.checkpoints.upserted')
            ->exists()
    )->toBeTrue();
});

it('rejects rollback and acceptance lifecycle fields on rollout detail update endpoint', function (): void {
    $actor = makeMultiFacilityRolloutActor(['platform.multi-facility.manage-rollouts']);
    $facilityContext = makeRolloutFacilityContext('TEN-UPD-GUARD', 'FAC-UPD-GUARD');
    $plan = seedMultiFacilityRolloutPlan($facilityContext['facility'], [
        'rollout_code' => 'ROL-UPD-GUARD-001',
        'status' => 'draft',
        'owner_user_id' => null,
        'rollback_required' => false,
        'rollback_reason' => null,
    ]);

    $this->actingAs($actor)
        ->patchJson('/api/v1/platform/admin/facility-rollouts/'.$plan->id, [
            'ownerUserId' => $actor->id,
            'rollbackRequired' => true,
            'acceptanceStatus' => 'accepted',
            'approvalCaseReference' => 'CASE-GUARD-001',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['rollbackRequired', 'acceptanceStatus', 'approvalCaseReference']);

    $plan->refresh();
    expect($plan->owner_user_id)->toBeNull();
    expect((bool) $plan->rollback_required)->toBeFalse();
    expect($plan->rollback_reason)->toBeNull();
});

it('creates and updates rollout incidents when authorized', function (): void {
    $actor = makeMultiFacilityRolloutActor(['platform.multi-facility.manage-incidents']);
    $facilityContext = makeRolloutFacilityContext('TEN-INC', 'FAC-INC');
    $plan = seedMultiFacilityRolloutPlan($facilityContext['facility'], [
        'rollout_code' => 'ROL-INC-001',
        'status' => 'active',
    ]);

    $createResponse = $this->actingAs($actor)
        ->postJson('/api/v1/platform/admin/facility-rollouts/'.$plan->id.'/incidents', [
            'incidentCode' => 'inc-001',
            'severity' => 'high',
            'status' => 'open',
            'summary' => 'Queue latency observed in pilot flow.',
            'details' => 'Observed during command center monitoring.',
        ])
        ->assertCreated()
        ->assertJsonPath('data.incidents.0.incidentCode', 'INC-001')
        ->assertJsonPath('data.incidents.0.status', 'open');

    $incidentId = $createResponse->json('data.incidents.0.id');

    $this->actingAs($actor)
        ->patchJson('/api/v1/platform/admin/facility-rollouts/'.$plan->id.'/incidents/'.$incidentId, [
            'status' => 'resolved',
            'severity' => 'medium',
            'summary' => 'Queue latency resolved after worker scaling.',
        ])
        ->assertOk()
        ->assertJsonPath('data.incidents.0.id', $incidentId)
        ->assertJsonPath('data.incidents.0.status', 'resolved')
        ->assertJsonPath('data.incidents.0.resolvedByUserId', $actor->id);

    expect(
        MultiFacilityRolloutAuditLogModel::query()
            ->where('rollout_plan_id', $plan->id)
            ->where('action', 'platform.multi-facility-rollout.incident.updated')
            ->exists()
    )->toBeTrue();
});

it('enforces rollback reason and executes rollback when authorized', function (): void {
    $actor = makeMultiFacilityRolloutActor(['platform.multi-facility.execute-rollback']);
    $facilityContext = makeRolloutFacilityContext('TEN-RBK', 'FAC-RBK');
    $plan = seedMultiFacilityRolloutPlan($facilityContext['facility'], [
        'rollout_code' => 'ROL-RBK-001',
        'status' => 'active',
    ]);

    $this->actingAs($actor)
        ->postJson('/api/v1/platform/admin/facility-rollouts/'.$plan->id.'/rollback', [
            'reason' => 'short',
            'approvalCaseReference' => 'CASE-RBK-001',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['reason']);

    $this->actingAs($actor)
        ->postJson('/api/v1/platform/admin/facility-rollouts/'.$plan->id.'/rollback', [
            'reason' => 'Critical outage in patient flow service window.',
            'approvalCaseReference' => 'CASE-RBK-002',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'rolled_back')
        ->assertJsonPath('data.rollbackRequired', true)
        ->assertJsonPath('data.rollbackReason', 'Critical outage in patient flow service window.');

    expect(
        MultiFacilityRolloutAuditLogModel::query()
            ->where('rollout_plan_id', $plan->id)
            ->where('action', 'platform.multi-facility-rollout.rollback.executed')
            ->exists()
    )->toBeTrue();
});

it('updates acceptance and exports rollout audit logs', function (): void {
    $actor = makeMultiFacilityRolloutActor([
        'platform.multi-facility.approve-acceptance',
        'platform.multi-facility.view-audit-logs',
    ]);
    $facilityContext = makeRolloutFacilityContext('TEN-ACC', 'FAC-ACC');
    $plan = seedMultiFacilityRolloutPlan($facilityContext['facility'], [
        'rollout_code' => 'ROL-ACC-001',
        'status' => 'active',
    ]);

    $this->actingAs($actor)
        ->patchJson('/api/v1/platform/admin/facility-rollouts/'.$plan->id.'/acceptance', [
            'acceptanceStatus' => 'accepted',
            'acceptanceCaseReference' => 'CASE-ACC-001',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['acceptanceStatus']);

    $this->actingAs($actor)
        ->patchJson('/api/v1/platform/admin/facility-rollouts/'.$plan->id.'/acceptance', [
            'acceptanceStatus' => 'accepted',
            'trainingCompletedAt' => now()->toIso8601String(),
            'acceptanceCaseReference' => 'CASE-ACC-002',
        ])
        ->assertOk()
        ->assertJsonPath('data.acceptance.acceptanceStatus', 'accepted')
        ->assertJsonPath('data.status', 'completed');

    $this->actingAs($actor)
        ->getJson('/api/v1/platform/admin/facility-rollouts/'.$plan->id.'/audit-logs')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.action', 'platform.multi-facility-rollout.acceptance.updated');

    $exportResponse = $this->actingAs($actor)
        ->get('/api/v1/platform/admin/facility-rollouts/'.$plan->id.'/audit-logs/export')
        ->assertOk()
        ->assertHeader('X-Audit-CSV-Schema-Version', 'audit-log-csv.v1');

    expect($exportResponse->streamedContent())->toContain('createdAt,action,actorType,actorId,changes,metadata');
});
