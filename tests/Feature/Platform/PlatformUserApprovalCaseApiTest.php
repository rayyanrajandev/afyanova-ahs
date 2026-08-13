<?php

use App\Models\User;
use App\Modules\Platform\Infrastructure\Models\FacilityModel;
use App\Modules\Platform\Infrastructure\Models\PlatformUserApprovalCaseAuditLogModel;
use App\Modules\Platform\Infrastructure\Models\PlatformUserApprovalCaseModel;
use App\Modules\Platform\Infrastructure\Models\TenantModel;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutMiddleware(ValidateCsrfToken::class);
});

function makePlatformUserApprovalCaseActor(array $permissions = []): User
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
function makeApprovalCaseContext(string $tenantCode = 'TEN-APP', string $facilityCode = 'FAC-APP'): array
{
    $tenant = TenantModel::query()->create([
        'code' => strtoupper($tenantCode),
        'name' => 'Approval Case Tenant '.strtoupper($tenantCode),
        'country_code' => 'TZ',
        'status' => 'active',
    ]);

    $facility = FacilityModel::query()->create([
        'tenant_id' => $tenant->id,
        'code' => strtoupper($facilityCode),
        'name' => 'Approval Case Facility '.strtoupper($facilityCode),
        'facility_type' => 'hospital',
        'timezone' => 'Africa/Dar_es_Salaam',
        'status' => 'active',
    ]);

    return [
        'tenant' => $tenant,
        'facility' => $facility,
    ];
}

function makeApprovalCaseTargetUser(TenantModel $tenant, array $overrides = []): User
{
    return User::factory()->create(array_merge([
        'tenant_id' => $tenant->id,
    ], $overrides));
}

function seedPlatformUserApprovalCase(FacilityModel $facility, User $targetUser, array $overrides = []): PlatformUserApprovalCaseModel
{
    return PlatformUserApprovalCaseModel::query()->create(array_merge([
        'tenant_id' => (string) $facility->tenant_id,
        'facility_id' => (string) $facility->id,
        'target_user_id' => (int) $targetUser->id,
        'requester_user_id' => null,
        'reviewer_user_id' => null,
        'case_reference' => 'CASE-APP-'.strtoupper(Str::random(8)),
        'action_type' => 'status_change',
        'action_payload' => ['status' => 'inactive'],
        'status' => 'draft',
        'decision_reason' => null,
        'submitted_at' => null,
        'decided_at' => null,
    ], $overrides));
}

it('requires authentication for approval case list and create', function (): void {
    $this->getJson('/api/v1/platform/admin/user-approval-cases')
        ->assertUnauthorized();

    $context = makeApprovalCaseContext('TEN-AUTH', 'FAC-AUTH');
    $target = makeApprovalCaseTargetUser($context['tenant']);

    $this->postJson('/api/v1/platform/admin/user-approval-cases', [
        'facilityId' => $context['facility']->id,
        'targetUserId' => $target->id,
        'caseReference' => 'CASE-AUTH-001',
        'actionType' => 'status_change',
    ])->assertUnauthorized();
});

it('forbids approval case endpoints without permission', function (): void {
    $actor = makePlatformUserApprovalCaseActor();
    $context = makeApprovalCaseContext('TEN-FORBID', 'FAC-FORBID');
    $target = makeApprovalCaseTargetUser($context['tenant']);

    $this->actingAs($actor)
        ->getJson('/api/v1/platform/admin/user-approval-cases')
        ->assertForbidden();

    $this->actingAs($actor)
        ->postJson('/api/v1/platform/admin/user-approval-cases', [
            'facilityId' => $context['facility']->id,
            'targetUserId' => $target->id,
            'caseReference' => 'CASE-FORBID-001',
            'actionType' => 'status_change',
        ])->assertForbidden();
});

it('creates lists and shows approval cases when authorized', function (): void {
    $actor = makePlatformUserApprovalCaseActor([
        'platform.users.approval-cases.create',
        'platform.users.approval-cases.read',
    ]);
    $context = makeApprovalCaseContext('TEN-CRUD', 'FAC-CRUD');
    $target = makeApprovalCaseTargetUser($context['tenant']);

    $response = $this->actingAs($actor)
        ->postJson('/api/v1/platform/admin/user-approval-cases', [
            'facilityId' => $context['facility']->id,
            'targetUserId' => $target->id,
            'caseReference' => 'CASE-CRUD-001',
            'actionType' => 'status_change',
            'actionPayload' => [
                'status' => 'inactive',
                'reason' => 'Manual review',
            ],
            'status' => 'draft',
        ])
        ->assertCreated()
        ->assertJsonPath('data.caseReference', 'CASE-CRUD-001')
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.targetUserId', $target->id);

    $approvalCaseId = $response->json('data.id');

    $this->actingAs($actor)
        ->getJson('/api/v1/platform/admin/user-approval-cases?q=CASE-CRUD-001')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.id', $approvalCaseId);

    $this->actingAs($actor)
        ->getJson('/api/v1/platform/admin/user-approval-cases/'.$approvalCaseId)
        ->assertOk()
        ->assertJsonPath('data.id', $approvalCaseId)
        ->assertJsonPath('data.comments', []);

    expect(
        PlatformUserApprovalCaseAuditLogModel::query()
            ->where('approval_case_id', $approvalCaseId)
            ->where('action', 'platform.user-approval-case.created')
            ->exists()
    )->toBeTrue();
});

it('enforces status transition and decision rules', function (): void {
    $actor = makePlatformUserApprovalCaseActor([
        'platform.users.approval-cases.manage',
        'platform.users.approval-cases.review',
    ]);
    $context = makeApprovalCaseContext('TEN-RULE', 'FAC-RULE');
    $target = makeApprovalCaseTargetUser($context['tenant']);
    $approvalCase = seedPlatformUserApprovalCase($context['facility'], $target, [
        'case_reference' => 'CASE-RULE-001',
        'status' => 'draft',
    ]);

    $this->actingAs($actor)
        ->patchJson('/api/v1/platform/admin/user-approval-cases/'.$approvalCase->id.'/decision', [
            'decision' => 'approved',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['decision']);

    $this->actingAs($actor)
        ->patchJson('/api/v1/platform/admin/user-approval-cases/'.$approvalCase->id.'/status', [
            'status' => 'submitted',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'submitted');

    $statusLog = PlatformUserApprovalCaseAuditLogModel::query()
        ->where('approval_case_id', $approvalCase->id)
        ->where('action', 'platform.user-approval-case.status.updated')
        ->latest('created_at')
        ->first();

    expect($statusLog)->not->toBeNull();
    expect($statusLog?->metadata['transition']['from'] ?? null)->toBe('draft');
    expect($statusLog?->metadata['transition']['to'] ?? null)->toBe('submitted');
    expect($statusLog?->metadata['reason_required'] ?? null)->toBeFalse();
    expect($statusLog?->metadata['reason_provided'] ?? null)->toBeFalse();

    $this->actingAs($actor)
        ->patchJson('/api/v1/platform/admin/user-approval-cases/'.$approvalCase->id.'/status', [
            'status' => 'cancelled',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['reason']);

    $this->actingAs($actor)
        ->patchJson('/api/v1/platform/admin/user-approval-cases/'.$approvalCase->id.'/decision', [
            'decision' => 'rejected',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['reason']);

    $this->actingAs($actor)
        ->patchJson('/api/v1/platform/admin/user-approval-cases/'.$approvalCase->id.'/decision', [
            'decision' => 'approved',
            'reason' => 'Validated and approved.',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'approved')
        ->assertJsonPath('data.reviewerUserId', $actor->id);

    $this->actingAs($actor)
        ->patchJson('/api/v1/platform/admin/user-approval-cases/'.$approvalCase->id.'/status', [
            'status' => 'cancelled',
            'reason' => 'Should fail after decision.',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['status']);
});

it('rejects status field on approval-case decision endpoint', function (): void {
    $actor = makePlatformUserApprovalCaseActor([
        'platform.users.approval-cases.review',
    ]);
    $context = makeApprovalCaseContext('TEN-DEC-GUARD', 'FAC-DEC-GUARD');
    $target = makeApprovalCaseTargetUser($context['tenant']);
    $approvalCase = seedPlatformUserApprovalCase($context['facility'], $target, [
        'case_reference' => 'CASE-DEC-GUARD-001',
        'status' => 'submitted',
        'submitted_at' => now(),
    ]);

    $this->actingAs($actor)
        ->patchJson('/api/v1/platform/admin/user-approval-cases/'.$approvalCase->id.'/decision', [
            'decision' => 'approved',
            'status' => 'cancelled',
            'reason' => 'Decision endpoint lifecycle guardrail check',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['status']);
});

it('adds and lists approval case comments when authorized', function (): void {
    $actor = makePlatformUserApprovalCaseActor([
        'platform.users.approval-cases.read',
        'platform.users.approval-cases.manage',
    ]);
    $context = makeApprovalCaseContext('TEN-CMT', 'FAC-CMT');
    $target = makeApprovalCaseTargetUser($context['tenant']);
    $approvalCase = seedPlatformUserApprovalCase($context['facility'], $target, [
        'case_reference' => 'CASE-CMT-001',
        'status' => 'submitted',
        'submitted_at' => now(),
    ]);

    $this->actingAs($actor)
        ->postJson('/api/v1/platform/admin/user-approval-cases/'.$approvalCase->id.'/comments', [
            'comment' => 'Reviewed the payload and attached evidence.',
        ])
        ->assertCreated()
        ->assertJsonPath('data.approvalCaseId', $approvalCase->id)
        ->assertJsonPath('data.authorUserId', $actor->id);

    $this->actingAs($actor)
        ->getJson('/api/v1/platform/admin/user-approval-cases/'.$approvalCase->id.'/comments')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.commentText', 'Reviewed the payload and attached evidence.');

    expect(
        PlatformUserApprovalCaseAuditLogModel::query()
            ->where('approval_case_id', $approvalCase->id)
            ->where('action', 'platform.user-approval-case.comment.added')
            ->exists()
    )->toBeTrue();
});

it('lists and exports approval case audit logs when authorized', function (): void {
    $actor = makePlatformUserApprovalCaseActor([
        'platform.users.approval-cases.view-audit-logs',
    ]);
    $context = makeApprovalCaseContext('TEN-AUD', 'FAC-AUD');
    $target = makeApprovalCaseTargetUser($context['tenant']);
    $approvalCase = seedPlatformUserApprovalCase($context['facility'], $target, [
        'case_reference' => 'CASE-AUD-001',
        'status' => 'submitted',
        'submitted_at' => now()->subHour(),
    ]);

    PlatformUserApprovalCaseAuditLogModel::query()->create([
        'approval_case_id' => $approvalCase->id,
        'actor_id' => $actor->id,
        'action' => 'platform.user-approval-case.status.updated',
        'changes' => ['status' => ['before' => 'draft', 'after' => 'submitted']],
        'metadata' => ['source' => 'feature-test'],
        'created_at' => now()->subMinute(),
    ]);

    PlatformUserApprovalCaseAuditLogModel::query()->create([
        'approval_case_id' => $approvalCase->id,
        'actor_id' => $actor->id,
        'action' => 'platform.user-approval-case.comment.added',
        'changes' => [],
        'metadata' => ['source' => 'feature-test'],
        'created_at' => now(),
    ]);

    $this->actingAs($actor)
        ->getJson('/api/v1/platform/admin/user-approval-cases/'.$approvalCase->id.'/audit-logs?action=platform.user-approval-case.comment.added')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.action', 'platform.user-approval-case.comment.added');

    $response = $this->actingAs($actor)
        ->get('/api/v1/platform/admin/user-approval-cases/'.$approvalCase->id.'/audit-logs/export?action=platform.user-approval-case.comment.added')
        ->assertOk()
        ->assertHeader('X-Audit-CSV-Schema-Version', 'audit-log-csv.v1');

    $csv = $response->streamedContent();
    expect($csv)->toContain('platform.user-approval-case.comment.added');
    expect($csv)->not->toContain('platform.user-approval-case.status.updated');
});
