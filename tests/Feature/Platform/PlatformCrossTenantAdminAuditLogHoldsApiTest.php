<?php

use App\Models\User;
use App\Modules\Platform\Infrastructure\Models\CrossTenantAdminAuditLogHoldModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('requires authentication for cross tenant audit log hold endpoints', function (): void {
    $this->getJson('/api/v1/platform/admin/cross-tenant-audit-log-holds')->assertUnauthorized();
    $this->postJson('/api/v1/platform/admin/cross-tenant-audit-log-holds', [])->assertUnauthorized();
    $this->patchJson('/api/v1/platform/admin/cross-tenant-audit-log-holds/'.(string) Str::uuid().'/release', [])->assertUnauthorized();
});

it('forbids cross tenant audit log hold list without hold-view permission', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/api/v1/platform/admin/cross-tenant-audit-log-holds')
        ->assertForbidden();
});

it('forbids cross tenant audit log hold create and release without hold-manage permission', function (): void {
    $user = User::factory()->create();
    grantPlatformCrossTenantAuditHoldsViewPermission($user);

    $this->actingAs($user)
        ->postJson('/api/v1/platform/admin/cross-tenant-audit-log-holds', [
            'holdCode' => 'HOLD-001',
            'reason' => 'Legal review',
            'approvalCaseReference' => 'CASE-001',
            'approvedByUserId' => $user->id,
            'reviewDueAt' => now()->addDays(30)->toIso8601String(),
        ])
        ->assertForbidden();

    $this->actingAs($user)
        ->patchJson('/api/v1/platform/admin/cross-tenant-audit-log-holds/'.(string) Str::uuid().'/release', [
            'releaseReason' => 'Closed',
            'releaseCaseReference' => 'CASE-REL-001',
            'releaseApprovedByUserId' => $user->id,
        ])
        ->assertForbidden();
});

it('creates cross tenant audit log hold and writes audit log when authorized', function (): void {
    $user = User::factory()->create();
    grantPlatformCrossTenantAuditHoldsManagePermission($user);
    seedPlatformAdminTenantForHolds('EAH', 'East Africa Health Group', 'KE');

    $response = $this->actingAs($user)
        ->postJson('/api/v1/platform/admin/cross-tenant-audit-log-holds', [
            'holdCode' => 'hold-eah-001',
            'reason' => 'Legal complaint preservation',
            'approvalCaseReference' => 'CASE-EAH-2026-001',
            'approvedByUserId' => $user->id,
            'reviewDueAt' => now()->addDays(90)->toIso8601String(),
            'targetTenantCode' => 'eah',
            'action' => 'platform-admin.patients.search',
            'startsAt' => now()->subDays(800)->toIso8601String(),
            'endsAt' => now()->subDays(100)->toIso8601String(),
        ])
        ->assertCreated()
        ->assertJsonPath('data.holdCode', 'HOLD-EAH-001')
        ->assertJsonPath('data.approvalCaseReference', 'CASE-EAH-2026-001')
        ->assertJsonPath('data.approvedByUserId', $user->id)
        ->assertJsonPath('data.targetTenantCode', 'EAH')
        ->assertJsonPath('data.action', 'platform-admin.patients.search')
        ->assertJsonPath('data.isActive', true);

    $holdId = $response->json('data.id');

    $hold = CrossTenantAdminAuditLogHoldModel::query()->find($holdId);
    expect($hold)->not->toBeNull();
    expect($hold?->hold_code)->toBe('HOLD-EAH-001');
    expect($hold?->created_by_user_id)->toBe($user->id);
    expect($hold?->approval_case_reference)->toBe('CASE-EAH-2026-001');
    expect($hold?->approved_by_user_id)->toBe($user->id);
    expect($hold?->review_due_at)->not->toBeNull();

    $audit = DB::table('platform_cross_tenant_admin_audit_logs')->orderByDesc('created_at')->first();
    expect($audit)->not->toBeNull();
    expect($audit->action)->toBe('platform-admin.audit-log-holds.create');
    expect($audit->operation_type)->toBe('write');
    expect($audit->target_resource_type)->toBe('cross_tenant_audit_log_hold');
    expect($audit->target_resource_id)->toBe($holdId);
    expect($audit->target_tenant_code)->toBe('EAH');
    expect($audit->outcome)->toBe('success');
});

it('lists cross tenant audit log holds with filters and writes audit log when authorized', function (): void {
    $user = User::factory()->create();
    grantPlatformCrossTenantAuditHoldsViewPermission($user);

    CrossTenantAdminAuditLogHoldModel::query()->create([
        'hold_code' => 'HOLD-EAH-LIST-1',
        'reason' => 'EAH hold',
        'target_tenant_code' => 'EAH',
        'action' => 'platform-admin.patients.search',
        'is_active' => true,
    ]);

    CrossTenantAdminAuditLogHoldModel::query()->create([
        'hold_code' => 'HOLD-TZH-LIST-1',
        'reason' => 'TZH hold',
        'target_tenant_code' => 'TZH',
        'action' => 'platform-admin.billing-invoices.search',
        'is_active' => false,
        'released_at' => now(),
        'release_reason' => 'Released',
    ]);

    $this->actingAs($user)
        ->getJson('/api/v1/platform/admin/cross-tenant-audit-log-holds?targetTenantCode=eah&isActive=1')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('meta.filters.targetTenantCode', 'EAH')
        ->assertJsonPath('meta.filters.isActive', true)
        ->assertJsonPath('data.0.holdCode', 'HOLD-EAH-LIST-1')
        ->assertJsonPath('data.0.isActive', true);

    $audit = DB::table('platform_cross_tenant_admin_audit_logs')->orderByDesc('created_at')->first();
    expect($audit)->not->toBeNull();
    expect($audit->action)->toBe('platform-admin.audit-log-holds.list');
    expect($audit->operation_type)->toBe('read');
    expect($audit->target_resource_type)->toBe('cross_tenant_audit_log_hold');
    expect($audit->target_resource_id)->toBeNull();
});

it('lists cross tenant audit log holds with approval and release metadata filters', function (): void {
    $user = User::factory()->create();
    $approver = User::factory()->create();
    $releaseApprover = User::factory()->create();
    grantPlatformCrossTenantAuditHoldsViewPermission($user);

    CrossTenantAdminAuditLogHoldModel::query()->create([
        'hold_code' => 'HOLD-META-FILTER-1',
        'reason' => 'Filtered hold',
        'approval_case_reference' => 'CASE-META-001',
        'approved_by_user_id' => $approver->id,
        'review_due_at' => now()->addDays(14),
        'target_tenant_code' => 'EAH',
        'action' => 'platform-admin.patients.search',
        'is_active' => false,
        'released_at' => now(),
        'release_reason' => 'Closed',
        'release_case_reference' => 'CASE-META-REL-001',
        'release_approved_by_user_id' => $releaseApprover->id,
    ]);

    CrossTenantAdminAuditLogHoldModel::query()->create([
        'hold_code' => 'HOLD-META-FILTER-2',
        'reason' => 'Different approval metadata',
        'approval_case_reference' => 'CASE-META-002',
        'approved_by_user_id' => $approver->id,
        'review_due_at' => now()->addDays(30),
        'target_tenant_code' => 'EAH',
        'action' => 'platform-admin.patients.search',
        'is_active' => false,
        'released_at' => now(),
        'release_reason' => 'Closed',
        'release_case_reference' => 'CASE-META-REL-002',
        'release_approved_by_user_id' => $releaseApprover->id,
    ]);

    $this->actingAs($user)
        ->getJson('/api/v1/platform/admin/cross-tenant-audit-log-holds?approvalCaseReference=CASE-META-001&approvedByUserId='.$approver->id.'&releaseCaseReference=CASE-META-REL-001&releaseApprovedByUserId='.$releaseApprover->id.'&isActive=0')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('meta.filters.approvalCaseReference', 'CASE-META-001')
        ->assertJsonPath('meta.filters.approvedByUserId', $approver->id)
        ->assertJsonPath('meta.filters.releaseCaseReference', 'CASE-META-REL-001')
        ->assertJsonPath('meta.filters.releaseApprovedByUserId', $releaseApprover->id)
        ->assertJsonPath('meta.filters.isActive', false)
        ->assertJsonPath('data.0.holdCode', 'HOLD-META-FILTER-1')
        ->assertJsonPath('data.0.approvalCaseReference', 'CASE-META-001')
        ->assertJsonPath('data.0.releaseCaseReference', 'CASE-META-REL-001');
});

it('lists cross tenant audit log holds with created and released date window filters', function (): void {
    Carbon::setTestNow('2026-02-26 12:00:00');

    $user = User::factory()->create();
    grantPlatformCrossTenantAuditHoldsViewPermission($user);

    $hold1 = CrossTenantAdminAuditLogHoldModel::query()->create([
        'hold_code' => 'HOLD-DATE-FILTER-1',
        'reason' => 'Within window',
        'approval_case_reference' => 'CASE-DATE-001',
        'approved_by_user_id' => $user->id,
        'review_due_at' => now()->addDays(30),
        'target_tenant_code' => 'EAH',
        'action' => 'platform-admin.patients.search',
        'is_active' => false,
        'released_at' => now()->subDays(2),
        'release_reason' => 'Closed',
        'release_case_reference' => 'CASE-DATE-REL-001',
        'release_approved_by_user_id' => $user->id,
    ]);

    $hold2 = CrossTenantAdminAuditLogHoldModel::query()->create([
        'hold_code' => 'HOLD-DATE-FILTER-2',
        'reason' => 'Outside created window',
        'approval_case_reference' => 'CASE-DATE-002',
        'approved_by_user_id' => $user->id,
        'review_due_at' => now()->addDays(30),
        'target_tenant_code' => 'EAH',
        'action' => 'platform-admin.patients.search',
        'is_active' => false,
        'released_at' => now()->subDays(2),
        'release_reason' => 'Closed',
        'release_case_reference' => 'CASE-DATE-REL-002',
        'release_approved_by_user_id' => $user->id,
    ]);

    $hold3 = CrossTenantAdminAuditLogHoldModel::query()->create([
        'hold_code' => 'HOLD-DATE-FILTER-3',
        'reason' => 'Outside released window',
        'approval_case_reference' => 'CASE-DATE-003',
        'approved_by_user_id' => $user->id,
        'review_due_at' => now()->addDays(30),
        'target_tenant_code' => 'EAH',
        'action' => 'platform-admin.patients.search',
        'is_active' => false,
        'released_at' => now()->subDays(20),
        'release_reason' => 'Closed',
        'release_case_reference' => 'CASE-DATE-REL-003',
        'release_approved_by_user_id' => $user->id,
    ]);

    DB::table('platform_cross_tenant_admin_audit_log_holds')->where('id', $hold1->id)->update(['created_at' => now()->subDays(8)]);
    DB::table('platform_cross_tenant_admin_audit_log_holds')->where('id', $hold2->id)->update(['created_at' => now()->subDays(40)]);
    DB::table('platform_cross_tenant_admin_audit_log_holds')->where('id', $hold3->id)->update(['created_at' => now()->subDays(7)]);

    $createdFrom = now()->subDays(10)->toIso8601String();
    $createdTo = now()->subDays(5)->toIso8601String();
    $releasedFrom = now()->subDays(3)->toIso8601String();
    $releasedTo = now()->subDay()->toIso8601String();

    $this->actingAs($user)
        ->getJson('/api/v1/platform/admin/cross-tenant-audit-log-holds?createdFrom='.urlencode($createdFrom).'&createdTo='.urlencode($createdTo).'&releasedFrom='.urlencode($releasedFrom).'&releasedTo='.urlencode($releasedTo).'&isActive=0')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('meta.filters.createdFrom', Carbon::parse($createdFrom)->toDateTimeString())
        ->assertJsonPath('meta.filters.createdTo', Carbon::parse($createdTo)->toDateTimeString())
        ->assertJsonPath('meta.filters.releasedFrom', Carbon::parse($releasedFrom)->toDateTimeString())
        ->assertJsonPath('meta.filters.releasedTo', Carbon::parse($releasedTo)->toDateTimeString())
        ->assertJsonPath('meta.filters.isActive', false)
        ->assertJsonPath('data.0.holdCode', 'HOLD-DATE-FILTER-1');

    Carbon::setTestNow();
});

it('lists cross tenant audit log holds with sortable created and released timestamps', function (): void {
    Carbon::setTestNow('2026-02-26 12:00:00');

    $user = User::factory()->create();
    grantPlatformCrossTenantAuditHoldsViewPermission($user);

    $holdA = CrossTenantAdminAuditLogHoldModel::query()->create([
        'hold_code' => 'HOLD-SORT-A',
        'reason' => 'Sort A',
        'approval_case_reference' => 'CASE-SORT-A',
        'approved_by_user_id' => $user->id,
        'review_due_at' => now()->addDays(20),
        'target_tenant_code' => 'EAH',
        'action' => 'platform-admin.patients.search',
        'is_active' => false,
        'released_at' => now()->subDays(1),
        'release_reason' => 'Closed',
        'release_case_reference' => 'CASE-SORT-REL-A',
        'release_approved_by_user_id' => $user->id,
    ]);

    $holdB = CrossTenantAdminAuditLogHoldModel::query()->create([
        'hold_code' => 'HOLD-SORT-B',
        'reason' => 'Sort B',
        'approval_case_reference' => 'CASE-SORT-B',
        'approved_by_user_id' => $user->id,
        'review_due_at' => now()->addDays(20),
        'target_tenant_code' => 'EAH',
        'action' => 'platform-admin.patients.search',
        'is_active' => false,
        'released_at' => now()->subDays(5),
        'release_reason' => 'Closed',
        'release_case_reference' => 'CASE-SORT-REL-B',
        'release_approved_by_user_id' => $user->id,
    ]);

    DB::table('platform_cross_tenant_admin_audit_log_holds')->where('id', $holdA->id)->update(['created_at' => now()->subDays(2)]);
    DB::table('platform_cross_tenant_admin_audit_log_holds')->where('id', $holdB->id)->update(['created_at' => now()->subDays(10)]);

    $this->actingAs($user)
        ->getJson('/api/v1/platform/admin/cross-tenant-audit-log-holds?isActive=0&sortBy=createdAt&sortDir=asc')
        ->assertOk()
        ->assertJsonPath('meta.filters.sortBy', 'createdAt')
        ->assertJsonPath('meta.filters.sortDir', 'asc')
        ->assertJsonPath('data.0.holdCode', 'HOLD-SORT-B')
        ->assertJsonPath('data.1.holdCode', 'HOLD-SORT-A');

    $this->actingAs($user)
        ->getJson('/api/v1/platform/admin/cross-tenant-audit-log-holds?isActive=0&sortBy=releasedAt&sortDir=desc')
        ->assertOk()
        ->assertJsonPath('meta.filters.sortBy', 'releasedAt')
        ->assertJsonPath('meta.filters.sortDir', 'desc')
        ->assertJsonPath('data.0.holdCode', 'HOLD-SORT-A')
        ->assertJsonPath('data.1.holdCode', 'HOLD-SORT-B');

    Carbon::setTestNow();
});

it('lists cross tenant audit log holds with creator and releaser actor filters', function (): void {
    $viewer = User::factory()->create();
    $creatorA = User::factory()->create();
    $creatorB = User::factory()->create();
    $releaserA = User::factory()->create();
    $releaserB = User::factory()->create();
    grantPlatformCrossTenantAuditHoldsViewPermission($viewer);

    CrossTenantAdminAuditLogHoldModel::query()->create([
        'hold_code' => 'HOLD-ACTOR-FILTER-1',
        'reason' => 'Actor filtered target',
        'approval_case_reference' => 'CASE-ACTOR-001',
        'approved_by_user_id' => $viewer->id,
        'review_due_at' => now()->addDays(10),
        'target_tenant_code' => 'EAH',
        'action' => 'platform-admin.patients.search',
        'is_active' => false,
        'created_by_user_id' => $creatorA->id,
        'released_at' => now(),
        'released_by_user_id' => $releaserA->id,
        'release_reason' => 'Released',
        'release_case_reference' => 'CASE-ACTOR-REL-001',
        'release_approved_by_user_id' => $viewer->id,
    ]);

    CrossTenantAdminAuditLogHoldModel::query()->create([
        'hold_code' => 'HOLD-ACTOR-FILTER-2',
        'reason' => 'Different creator',
        'approval_case_reference' => 'CASE-ACTOR-002',
        'approved_by_user_id' => $viewer->id,
        'review_due_at' => now()->addDays(10),
        'target_tenant_code' => 'EAH',
        'action' => 'platform-admin.patients.search',
        'is_active' => false,
        'created_by_user_id' => $creatorB->id,
        'released_at' => now(),
        'released_by_user_id' => $releaserA->id,
        'release_reason' => 'Released',
        'release_case_reference' => 'CASE-ACTOR-REL-002',
        'release_approved_by_user_id' => $viewer->id,
    ]);

    CrossTenantAdminAuditLogHoldModel::query()->create([
        'hold_code' => 'HOLD-ACTOR-FILTER-3',
        'reason' => 'Different releaser',
        'approval_case_reference' => 'CASE-ACTOR-003',
        'approved_by_user_id' => $viewer->id,
        'review_due_at' => now()->addDays(10),
        'target_tenant_code' => 'EAH',
        'action' => 'platform-admin.patients.search',
        'is_active' => false,
        'created_by_user_id' => $creatorA->id,
        'released_at' => now(),
        'released_by_user_id' => $releaserB->id,
        'release_reason' => 'Released',
        'release_case_reference' => 'CASE-ACTOR-REL-003',
        'release_approved_by_user_id' => $viewer->id,
    ]);

    $this->actingAs($viewer)
        ->getJson('/api/v1/platform/admin/cross-tenant-audit-log-holds?createdByUserId='.$creatorA->id.'&releasedByUserId='.$releaserA->id.'&isActive=0')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('meta.filters.createdByUserId', $creatorA->id)
        ->assertJsonPath('meta.filters.releasedByUserId', $releaserA->id)
        ->assertJsonPath('meta.filters.isActive', false)
        ->assertJsonPath('data.0.holdCode', 'HOLD-ACTOR-FILTER-1')
        ->assertJsonPath('data.0.createdByUserId', $creatorA->id)
        ->assertJsonPath('data.0.releasedByUserId', $releaserA->id);
});

it('lists cross tenant audit log holds with text search across hold code and reason', function (): void {
    $viewer = User::factory()->create();
    grantPlatformCrossTenantAuditHoldsViewPermission($viewer);

    CrossTenantAdminAuditLogHoldModel::query()->create([
        'hold_code' => 'HOLD-TEXT-001',
        'reason' => 'Complaint preservation hold',
        'approval_case_reference' => 'CASE-TEXT-001',
        'approved_by_user_id' => $viewer->id,
        'review_due_at' => now()->addDays(7),
        'is_active' => true,
    ]);

    CrossTenantAdminAuditLogHoldModel::query()->create([
        'hold_code' => 'HOLD-TEXT-XYZ-002',
        'reason' => 'Routine retention pause',
        'approval_case_reference' => 'CASE-TEXT-002',
        'approved_by_user_id' => $viewer->id,
        'review_due_at' => now()->addDays(7),
        'is_active' => true,
    ]);

    $this->actingAs($viewer)
        ->getJson('/api/v1/platform/admin/cross-tenant-audit-log-holds?q=Complaint')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('meta.filters.q', 'Complaint')
        ->assertJsonPath('data.0.holdCode', 'HOLD-TEXT-001');

    $this->actingAs($viewer)
        ->getJson('/api/v1/platform/admin/cross-tenant-audit-log-holds?q=XYZ-002')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('meta.filters.q', 'XYZ-002')
        ->assertJsonPath('data.0.holdCode', 'HOLD-TEXT-XYZ-002');
});

it('releases cross tenant audit log hold and writes audit log when authorized', function (): void {
    $user = User::factory()->create();
    grantPlatformCrossTenantAuditHoldsManagePermission($user);

    $hold = CrossTenantAdminAuditLogHoldModel::query()->create([
        'hold_code' => 'HOLD-REL-001',
        'reason' => 'Initial legal hold',
        'target_tenant_code' => 'EAH',
        'action' => 'platform-admin.patients.search',
        'is_active' => true,
        'created_by_user_id' => $user->id,
    ]);

    $this->actingAs($user)
        ->patchJson('/api/v1/platform/admin/cross-tenant-audit-log-holds/'.$hold->id.'/release', [
            'releaseReason' => 'Case closed',
            'releaseCaseReference' => 'CASE-REL-2026-001',
            'releaseApprovedByUserId' => $user->id,
        ])
        ->assertOk()
        ->assertJsonPath('data.id', $hold->id)
        ->assertJsonPath('data.isActive', false)
        ->assertJsonPath('data.releaseReason', 'Case closed')
        ->assertJsonPath('data.releaseCaseReference', 'CASE-REL-2026-001')
        ->assertJsonPath('data.releaseApprovedByUserId', $user->id);

    $hold->refresh();
    expect($hold->is_active)->toBeFalse();
    expect($hold->released_by_user_id)->toBe($user->id);
    expect($hold->released_at)->not->toBeNull();
    expect($hold->release_case_reference)->toBe('CASE-REL-2026-001');
    expect($hold->release_approved_by_user_id)->toBe($user->id);

    $audit = DB::table('platform_cross_tenant_admin_audit_logs')->orderByDesc('created_at')->first();
    expect($audit)->not->toBeNull();
    expect($audit->action)->toBe('platform-admin.audit-log-holds.release');
    expect($audit->operation_type)->toBe('write');
    expect($audit->target_resource_id)->toBe($hold->id);
    expect($audit->outcome)->toBe('success');

    /** @var array<string, mixed> $metadata */
    $metadata = json_decode((string) $audit->metadata, true) ?? [];
    expect($metadata['transition']['is_active']['from'] ?? null)->toBeTrue();
    expect($metadata['transition']['is_active']['to'] ?? null)->toBeFalse();
    expect($metadata['release_reason_required'] ?? null)->toBeTrue();
    expect($metadata['release_reason_provided'] ?? null)->toBeTrue();
});

it('returns validation error when creating hold with unknown tenant code', function (): void {
    $user = User::factory()->create();
    grantPlatformCrossTenantAuditHoldsManagePermission($user);

    $this->actingAs($user)
        ->postJson('/api/v1/platform/admin/cross-tenant-audit-log-holds', [
            'holdCode' => 'HOLD-UNKNOWN-TENANT',
            'reason' => 'Legal review',
            'approvalCaseReference' => 'CASE-UNKNOWN-TENANT',
            'approvedByUserId' => $user->id,
            'reviewDueAt' => now()->addDays(30)->toIso8601String(),
            'targetTenantCode' => 'NOPE',
        ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'VALIDATION_ERROR');
});

it('validates required approval metadata when creating cross tenant audit log hold', function (): void {
    $user = User::factory()->create();
    grantPlatformCrossTenantAuditHoldsManagePermission($user);

    $this->actingAs($user)
        ->postJson('/api/v1/platform/admin/cross-tenant-audit-log-holds', [
            'holdCode' => 'HOLD-MISSING-APPROVAL',
            'reason' => 'Legal review',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors([
            'approvalCaseReference',
            'approvedByUserId',
            'reviewDueAt',
        ]);
});

it('returns validation error when creating hold with whitespace-only reason', function (): void {
    $user = User::factory()->create();
    grantPlatformCrossTenantAuditHoldsManagePermission($user);

    $this->actingAs($user)
        ->postJson('/api/v1/platform/admin/cross-tenant-audit-log-holds', [
            'holdCode' => 'HOLD-WS-REASON-001',
            'reason' => '   ',
            'approvalCaseReference' => 'CASE-WS-REASON-001',
            'approvedByUserId' => $user->id,
            'reviewDueAt' => now()->addDays(30)->toIso8601String(),
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['reason']);
});

it('enforces two person control for hold create when governance setting is enabled', function (): void {
    config()->set('platform_audit_retention.cross_tenant_admin_audit_logs.holds.governance.enforce_two_person_control', true);

    $user = User::factory()->create();
    grantPlatformCrossTenantAuditHoldsManagePermission($user);

    $this->actingAs($user)
        ->postJson('/api/v1/platform/admin/cross-tenant-audit-log-holds', [
            'holdCode' => 'HOLD-2PC-CREATE-001',
            'reason' => 'Legal review',
            'approvalCaseReference' => 'CASE-2PC-CREATE-001',
            'approvedByUserId' => $user->id,
            'reviewDueAt' => now()->addDays(30)->toIso8601String(),
        ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'VALIDATION_ERROR')
        ->assertJsonPath('message', 'Two-person control requires a different approver for hold creation.');
});

it('validates required release approval metadata when releasing cross tenant audit log hold', function (): void {
    $user = User::factory()->create();
    grantPlatformCrossTenantAuditHoldsManagePermission($user);

    $hold = CrossTenantAdminAuditLogHoldModel::query()->create([
        'hold_code' => 'HOLD-REL-VALIDATE-001',
        'reason' => 'Initial legal hold',
        'approval_case_reference' => 'CASE-INIT-001',
        'approved_by_user_id' => $user->id,
        'review_due_at' => now()->addDays(30),
        'target_tenant_code' => 'EAH',
        'is_active' => true,
        'created_by_user_id' => $user->id,
    ]);

    $this->actingAs($user)
        ->patchJson('/api/v1/platform/admin/cross-tenant-audit-log-holds/'.$hold->id.'/release', [
            'releaseReason' => 'Case closed',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors([
            'releaseCaseReference',
            'releaseApprovedByUserId',
        ]);
});

it('returns validation error when releasing hold with whitespace-only release reason', function (): void {
    $user = User::factory()->create();
    grantPlatformCrossTenantAuditHoldsManagePermission($user);

    $hold = CrossTenantAdminAuditLogHoldModel::query()->create([
        'hold_code' => 'HOLD-REL-WS-001',
        'reason' => 'Initial legal hold',
        'approval_case_reference' => 'CASE-REL-WS-INIT',
        'approved_by_user_id' => $user->id,
        'review_due_at' => now()->addDays(30),
        'target_tenant_code' => 'EAH',
        'is_active' => true,
        'created_by_user_id' => $user->id,
    ]);

    $this->actingAs($user)
        ->patchJson('/api/v1/platform/admin/cross-tenant-audit-log-holds/'.$hold->id.'/release', [
            'releaseReason' => '   ',
            'releaseCaseReference' => 'CASE-REL-WS-001',
            'releaseApprovedByUserId' => $user->id,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['releaseReason']);
});

it('enforces two person control for hold release when governance setting is enabled', function (): void {
    config()->set('platform_audit_retention.cross_tenant_admin_audit_logs.holds.governance.enforce_two_person_control', true);

    $user = User::factory()->create();
    grantPlatformCrossTenantAuditHoldsManagePermission($user);

    $hold = CrossTenantAdminAuditLogHoldModel::query()->create([
        'hold_code' => 'HOLD-2PC-REL-001',
        'reason' => 'Initial legal hold',
        'approval_case_reference' => 'CASE-2PC-REL-INIT',
        'approved_by_user_id' => $user->id,
        'review_due_at' => now()->addDays(30),
        'target_tenant_code' => 'EAH',
        'is_active' => true,
        'created_by_user_id' => $user->id,
    ]);

    $this->actingAs($user)
        ->patchJson('/api/v1/platform/admin/cross-tenant-audit-log-holds/'.$hold->id.'/release', [
            'releaseReason' => 'Case closed',
            'releaseCaseReference' => 'CASE-2PC-REL-001',
            'releaseApprovedByUserId' => $user->id,
        ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'VALIDATION_ERROR')
        ->assertJsonPath('message', 'Two-person control requires a different approver for hold release.');
});

it('allows audit log hold list endpoint when tenant isolation is enabled and request has no resolved tenant scope', function (): void {
    config()->set('country_profiles.active', 'TZ');

    $user = User::factory()->create();
    grantPlatformCrossTenantAuditHoldsViewPermission($user);

    DB::table('feature_flag_overrides')->insert([
        'id' => (string) Str::uuid(),
        'flag_name' => 'platform.multi_tenant_isolation',
        'scope_type' => 'country',
        'scope_key' => 'TZ',
        'enabled' => true,
        'reason' => 'platform admin hold route exemption test',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    CrossTenantAdminAuditLogHoldModel::query()->create([
        'hold_code' => 'HOLD-TZH-EXEMPT',
        'reason' => 'Exempt list access test',
        'target_tenant_code' => 'TZH',
        'action' => 'platform-admin.staff.search',
        'is_active' => true,
    ]);

    $this->actingAs($user)
        ->getJson('/api/v1/platform/admin/cross-tenant-audit-log-holds?targetTenantCode=TZH')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.holdCode', 'HOLD-TZH-EXEMPT');
});

function grantPlatformCrossTenantAuditHoldsViewPermission(User $user): void
{
    $user->givePermissionTo('platform.cross-tenant.view-audit-holds');
}

function grantPlatformCrossTenantAuditHoldsManagePermission(User $user): void
{
    $user->givePermissionTo('platform.cross-tenant.manage-audit-holds');
}

function seedPlatformAdminTenantForHolds(string $tenantCode, string $tenantName, string $countryCode): string
{
    $tenantId = (string) Str::uuid();

    DB::table('tenants')->insert([
        'id' => $tenantId,
        'code' => strtoupper($tenantCode),
        'name' => $tenantName,
        'country_code' => strtoupper($countryCode),
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $tenantId;
}
