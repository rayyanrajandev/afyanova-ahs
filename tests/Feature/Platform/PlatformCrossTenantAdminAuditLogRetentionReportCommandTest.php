<?php

use App\Modules\Platform\Infrastructure\Models\CrossTenantAdminAuditLogModel;
use App\Modules\Platform\Infrastructure\Models\CrossTenantAdminAuditLogHoldModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('reports retention purge candidates for platform cross tenant admin audit logs without deleting rows', function (): void {
    Carbon::setTestNow('2026-02-25 12:00:00');

    seedCrossTenantAdminAuditLogForRetentionReport('platform-admin.patients.search', 'EAH', now()->subDays(500));
    seedCrossTenantAdminAuditLogForRetentionReport('platform-admin.billing-invoices.search', 'EAH', now()->subDays(30));
    seedCrossTenantAdminAuditLogForRetentionReport('platform-admin.staff.search', 'TZH', now()->subDays(700));

    $beforeCount = CrossTenantAdminAuditLogModel::query()->count();

    $exitCode = Artisan::call('platform:cross-tenant-audit-logs:retention-report', [
        '--days' => 400,
        '--tenantCode' => 'EAH',
        '--json' => true,
    ]);

    $output = Artisan::output();

    expect($exitCode)->toBe(0);
    expect($output)->toContain('"mode": "dry_run"');
    expect($output)->toContain('"retentionDays": 400');
    expect($output)->toContain('"tenantCode": "EAH"');
    expect($output)->toContain('"totalRows": 2');
    expect($output)->toContain('"purgeCandidateRows": 1');
    expect($output)->toContain('"retainedRows": 1');
    expect($output)->toContain('"deletionPerformed": false');

    expect(CrossTenantAdminAuditLogModel::query()->count())->toBe($beforeCount);

    Carbon::setTestNow();
});

it('rejects invalid retention day values for the platform cross tenant admin audit log retention report command', function (): void {
    $this->artisan('platform:cross-tenant-audit-logs:retention-report', [
        '--days' => 0,
    ])
        ->expectsOutputToContain('must be at least 1')
        ->assertExitCode(1);
});

it('refuses retention purge without confirm and does not delete rows', function (): void {
    Carbon::setTestNow('2026-02-25 12:00:00');

    seedCrossTenantAdminAuditLogForRetentionReport('platform-admin.patients.search', 'EAH', now()->subDays(500));
    seedCrossTenantAdminAuditLogForRetentionReport('platform-admin.patients.search', 'EAH', now()->subDays(450));
    $beforeCount = CrossTenantAdminAuditLogModel::query()->count();

    $exitCode = Artisan::call('platform:cross-tenant-audit-logs:retention-purge', [
        '--days' => 400,
        '--tenantCode' => 'EAH',
        '--batch' => 1,
        '--json' => true,
    ]);

    $output = Artisan::output();

    expect($exitCode)->toBe(1);
    expect($output)->toContain('"mode": "blocked_no_confirm"');
    expect($output)->toContain('"confirmRequired": true');
    expect($output)->toContain('"purgeCandidateRowsBefore": 2');
    expect($output)->toContain('"deletionPerformed": false');
    expect(CrossTenantAdminAuditLogModel::query()->count())->toBe($beforeCount);

    Carbon::setTestNow();
});

it('purges one confirmed batch of retention candidates and leaves remaining candidates for later runs', function (): void {
    Carbon::setTestNow('2026-02-25 12:00:00');

    seedCrossTenantAdminAuditLogForRetentionReport('platform-admin.staff.search', 'EAH', now()->subDays(600));
    seedCrossTenantAdminAuditLogForRetentionReport('platform-admin.staff.search', 'EAH', now()->subDays(550));
    seedCrossTenantAdminAuditLogForRetentionReport('platform-admin.staff.search', 'EAH', now()->subDays(500));
    seedCrossTenantAdminAuditLogForRetentionReport('platform-admin.staff.search', 'EAH', now()->subDays(10));
    seedCrossTenantAdminAuditLogForRetentionReport('platform-admin.staff.search', 'TZH', now()->subDays(700));

    $totalBefore = CrossTenantAdminAuditLogModel::query()->count();

    $exitCode = Artisan::call('platform:cross-tenant-audit-logs:retention-purge', [
        '--days' => 400,
        '--tenantCode' => 'EAH',
        '--action' => 'platform-admin.staff.search',
        '--batch' => 2,
        '--confirm' => true,
        '--json' => true,
    ]);

    $output = Artisan::output();

    expect($exitCode)->toBe(0);
    expect($output)->toContain('"mode": "purge"');
    expect($output)->toContain('"batchSize": 2');
    expect($output)->toContain('"purgeCandidateRowsBefore": 3');
    expect($output)->toContain('"deletedRows": 2');
    expect($output)->toContain('"remainingCandidateRows": 1');
    expect($output)->toContain('"truncatedByBatch": true');
    expect(CrossTenantAdminAuditLogModel::query()->count())->toBe($totalBefore - 2);

    $remainingScopedCandidates = CrossTenantAdminAuditLogModel::query()
        ->where('target_tenant_code', 'EAH')
        ->where('action', 'platform-admin.staff.search')
        ->where('created_at', '<', now()->subDays(400))
        ->count();

    expect($remainingScopedCandidates)->toBe(1);

    $otherTenantRowStillPresent = CrossTenantAdminAuditLogModel::query()
        ->where('target_tenant_code', 'TZH')
        ->exists();

    expect($otherTenantRowStillPresent)->toBeTrue();

    Carbon::setTestNow();
});

it('rejects invalid batch size values for the platform cross tenant admin audit log retention purge command', function (): void {
    $this->artisan('platform:cross-tenant-audit-logs:retention-purge', [
        '--batch' => 0,
        '--confirm' => true,
    ])
        ->expectsOutputToContain('must be between 1 and 10000')
        ->assertExitCode(1);
});

it('excludes active legal-hold matched rows from retention report and purge', function (): void {
    Carbon::setTestNow('2026-02-25 12:00:00');

    seedCrossTenantAdminAuditLogForRetentionReport('platform-admin.patients.search', 'EAH', now()->subDays(650));
    seedCrossTenantAdminAuditLogForRetentionReport('platform-admin.patients.search', 'EAH', now()->subDays(500));
    seedCrossTenantAdminAuditLogForRetentionReport('platform-admin.patients.search', 'EAH', now()->subDays(450));
    seedCrossTenantAdminAuditLogForRetentionReport('platform-admin.patients.search', 'EAH', now()->subDays(20));

    seedCrossTenantAdminAuditLogHoldForRetention(
        holdCode: 'HOLD-EAH-PAT-001',
        tenantCode: 'EAH',
        action: 'platform-admin.patients.search',
        startsAt: now()->subDays(700),
        endsAt: now()->subDays(480),
    );

    $reportExitCode = Artisan::call('platform:cross-tenant-audit-logs:retention-report', [
        '--days' => 400,
        '--tenantCode' => 'EAH',
        '--action' => 'platform-admin.patients.search',
        '--json' => true,
    ]);

    $reportOutput = Artisan::output();

    expect($reportExitCode)->toBe(0);
    expect($reportOutput)->toContain('"candidateRowsBeforeHoldExclusion": 3');
    expect($reportOutput)->toContain('"activeHoldRowsExcluded": 2');
    expect($reportOutput)->toContain('"purgeCandidateRows": 1');

    $purgeExitCode = Artisan::call('platform:cross-tenant-audit-logs:retention-purge', [
        '--days' => 400,
        '--tenantCode' => 'EAH',
        '--action' => 'platform-admin.patients.search',
        '--batch' => 10,
        '--confirm' => true,
        '--json' => true,
    ]);

    $purgeOutput = Artisan::output();

    expect($purgeExitCode)->toBe(0);
    expect($purgeOutput)->toContain('"candidateRowsBeforeHoldExclusion": 3');
    expect($purgeOutput)->toContain('"activeHoldRowsExcluded": 2');
    expect($purgeOutput)->toContain('"purgeCandidateRowsBefore": 1');
    expect($purgeOutput)->toContain('"deletedRows": 1');
    expect($purgeOutput)->toContain('"remainingCandidateRows": 0');

    $heldRowsStillPresent = CrossTenantAdminAuditLogModel::query()
        ->where('target_tenant_code', 'EAH')
        ->where('action', 'platform-admin.patients.search')
        ->where('created_at', '<', now()->subDays(480))
        ->count();

    expect($heldRowsStillPresent)->toBe(2);

    Carbon::setTestNow();
});

it('skips scheduled retention purge wrapper when schedule is disabled', function (): void {
    config()->set('platform_audit_retention.cross_tenant_admin_audit_logs.purge.schedule.enabled', false);
    config()->set('platform_audit_retention.cross_tenant_admin_audit_logs.purge.schedule.allowed_environments', ['testing']);

    $exitCode = Artisan::call('platform:cross-tenant-audit-logs:retention-purge-scheduled', [
        '--json' => true,
    ]);

    $output = Artisan::output();

    expect($exitCode)->toBe(0);
    expect($output)->toContain('"status": "skipped_disabled"');
    expect($output)->toContain('"deletionPerformed": false');
});

it('skips scheduled retention purge wrapper when current environment is not allowed', function (): void {
    config()->set('platform_audit_retention.cross_tenant_admin_audit_logs.purge.schedule.enabled', true);
    config()->set('platform_audit_retention.cross_tenant_admin_audit_logs.purge.schedule.allowed_environments', ['production']);

    $exitCode = Artisan::call('platform:cross-tenant-audit-logs:retention-purge-scheduled', [
        '--json' => true,
    ]);

    $output = Artisan::output();

    expect($exitCode)->toBe(0);
    expect($output)->toContain('"status": "skipped_environment_guard"');
    expect($output)->toContain('"environment": "testing"');
});

it('reports scheduled retention readiness as not ready when schedule is disabled', function (): void {
    config()->set('platform_audit_retention.cross_tenant_admin_audit_logs.purge.schedule.enabled', false);
    config()->set('platform_audit_retention.cross_tenant_admin_audit_logs.purge.schedule.allowed_environments', ['testing']);
    config()->set('platform_audit_retention.cross_tenant_admin_audit_logs.holds.governance.enforce_two_person_control', true);

    $exitCode = Artisan::call('platform:cross-tenant-audit-logs:retention-readiness-check', [
        '--json' => true,
    ]);

    $output = Artisan::output();

    expect($exitCode)->toBe(1);
    expect($output)->toContain('"status": "not_ready_schedule_disabled"');
    expect($output)->toContain('"eligibleToRunScheduledWrapperNow": false');
});

it('reports scheduled retention readiness as blocked when two person control is required and no waiver is configured', function (): void {
    config()->set('platform_audit_retention.cross_tenant_admin_audit_logs.purge.schedule.enabled', true);
    config()->set('platform_audit_retention.cross_tenant_admin_audit_logs.purge.schedule.allowed_environments', ['testing']);
    config()->set('platform_audit_retention.cross_tenant_admin_audit_logs.holds.governance.enforce_two_person_control', false);
    config()->set('platform_audit_retention.cross_tenant_admin_audit_logs.purge.schedule.readiness.require_two_person_control', true);
    config()->set('platform_audit_retention.cross_tenant_admin_audit_logs.purge.schedule.readiness.two_person_control_waiver.enabled', false);
    config()->set('platform_audit_retention.cross_tenant_admin_audit_logs.purge.schedule.readiness.two_person_control_waiver.reference', null);

    $exitCode = Artisan::call('platform:cross-tenant-audit-logs:retention-readiness-check', [
        '--json' => true,
    ]);

    $output = Artisan::output();

    expect($exitCode)->toBe(1);
    expect($output)->toContain('"status": "not_ready_two_person_control_required"');
    expect($output)->toContain('"twoPersonControlEnabled": false');
    expect($output)->toContain('"valid": false');
});

it('reports scheduled retention readiness as ready when two person control is enabled', function (): void {
    config()->set('platform_audit_retention.cross_tenant_admin_audit_logs.purge.schedule.enabled', true);
    config()->set('platform_audit_retention.cross_tenant_admin_audit_logs.purge.schedule.allowed_environments', ['testing']);
    config()->set('platform_audit_retention.cross_tenant_admin_audit_logs.holds.governance.enforce_two_person_control', true);
    config()->set('platform_audit_retention.cross_tenant_admin_audit_logs.purge.schedule.readiness.require_two_person_control', true);

    $exitCode = Artisan::call('platform:cross-tenant-audit-logs:retention-readiness-check', [
        '--json' => true,
    ]);

    $output = Artisan::output();

    expect($exitCode)->toBe(0);
    expect($output)->toContain('"status": "ready"');
    expect($output)->toContain('"eligibleToRunScheduledWrapperNow": true');
    expect($output)->toContain('"twoPersonControlEnabled": true');
});

it('reports scheduled retention readiness as ready with waiver when explicit waiver is configured', function (): void {
    config()->set('platform_audit_retention.cross_tenant_admin_audit_logs.purge.schedule.enabled', true);
    config()->set('platform_audit_retention.cross_tenant_admin_audit_logs.purge.schedule.allowed_environments', ['testing']);
    config()->set('platform_audit_retention.cross_tenant_admin_audit_logs.holds.governance.enforce_two_person_control', false);
    config()->set('platform_audit_retention.cross_tenant_admin_audit_logs.purge.schedule.readiness.require_two_person_control', true);
    config()->set('platform_audit_retention.cross_tenant_admin_audit_logs.purge.schedule.readiness.two_person_control_waiver.enabled', true);
    config()->set('platform_audit_retention.cross_tenant_admin_audit_logs.purge.schedule.readiness.two_person_control_waiver.reference', 'WAIVER-2026-RET-001');

    $exitCode = Artisan::call('platform:cross-tenant-audit-logs:retention-readiness-check', [
        '--json' => true,
    ]);

    $output = Artisan::output();

    expect($exitCode)->toBe(0);
    expect($output)->toContain('"status": "ready_with_waiver"');
    expect($output)->toContain('"eligibleToRunScheduledWrapperNow": true');
    expect($output)->toContain('"valid": true');
    expect($output)->toContain('"reference": "WAIVER-2026-RET-001"');
});

it('blocks scheduled retention purge wrapper when two person control readiness guard is not satisfied', function (): void {
    config()->set('platform_audit_retention.cross_tenant_admin_audit_logs.purge.schedule.enabled', true);
    config()->set('platform_audit_retention.cross_tenant_admin_audit_logs.purge.schedule.allowed_environments', ['testing']);
    config()->set('platform_audit_retention.cross_tenant_admin_audit_logs.holds.governance.enforce_two_person_control', false);
    config()->set('platform_audit_retention.cross_tenant_admin_audit_logs.purge.schedule.readiness.require_two_person_control', true);
    config()->set('platform_audit_retention.cross_tenant_admin_audit_logs.purge.schedule.readiness.two_person_control_waiver.enabled', false);
    config()->set('platform_audit_retention.cross_tenant_admin_audit_logs.purge.schedule.readiness.two_person_control_waiver.reference', null);

    $exitCode = Artisan::call('platform:cross-tenant-audit-logs:retention-purge-scheduled', [
        '--json' => true,
    ]);

    $output = Artisan::output();

    expect($exitCode)->toBe(1);
    expect($output)->toContain('"status": "blocked_readiness_guard_two_person_control_required"');
    expect($output)->toContain('"twoPersonControlEnabled": false');
    expect($output)->toContain('"enabled": false');
});

it('allows scheduled retention purge wrapper when explicit two person control waiver is configured', function (): void {
    config()->set('platform_audit_retention.cross_tenant_admin_audit_logs.purge.schedule.enabled', true);
    config()->set('platform_audit_retention.cross_tenant_admin_audit_logs.purge.schedule.allowed_environments', ['testing']);
    config()->set('platform_audit_retention.cross_tenant_admin_audit_logs.holds.governance.enforce_two_person_control', false);
    config()->set('platform_audit_retention.cross_tenant_admin_audit_logs.purge.schedule.readiness.require_two_person_control', true);
    config()->set('platform_audit_retention.cross_tenant_admin_audit_logs.purge.schedule.readiness.two_person_control_waiver.enabled', true);
    config()->set('platform_audit_retention.cross_tenant_admin_audit_logs.purge.schedule.readiness.two_person_control_waiver.reference', 'WAIVER-RET-001');

    $exitCode = Artisan::call('platform:cross-tenant-audit-logs:retention-purge-scheduled', [
        '--json' => true,
    ]);

    $output = Artisan::output();

    expect($exitCode)->toBe(0);
    expect($output)->not->toContain('"status": "blocked_readiness_guard_two_person_control_required"');
});

it('routes scheduled retention wrapper execution and metrics logs to configured channels', function (): void {
    config()->set('platform_audit_retention.cross_tenant_admin_audit_logs.purge.schedule.enabled', false);
    config()->set('platform_audit_retention.cross_tenant_admin_audit_logs.purge.schedule.allowed_environments', ['testing']);
    config()->set('platform_audit_retention.cross_tenant_admin_audit_logs.purge.monitoring.log_channels.execution', 'stack');
    config()->set('platform_audit_retention.cross_tenant_admin_audit_logs.purge.monitoring.log_channels.metrics', 'stack');
    config()->set('platform_audit_retention.cross_tenant_admin_audit_logs.purge.monitoring.log_channels.alerts', 'slack');

    Log::partialMock()
        ->shouldReceive('channel')
        ->with('stack')
        ->atLeast()
        ->twice()
        ->andReturnSelf();

    Log::partialMock()
        ->shouldReceive('info')
        ->withArgs(function (string $message, array $context): bool {
            return in_array($message, [
                'platform.cross_tenant_admin_audit_logs.retention_purge_scheduled',
                'platform.cross_tenant_admin_audit_logs.retention_purge_scheduled.metrics',
            ], true);
        })
        ->atLeast()
        ->twice()
        ->andReturnNull();

    $exitCode = Artisan::call('platform:cross-tenant-audit-logs:retention-purge-scheduled', [
        '--json' => true,
    ]);

    expect($exitCode)->toBe(0);
});

it('executes scheduled retention purge wrapper and logs metrics when enabled in allowed environment', function (): void {
    Carbon::setTestNow('2026-02-25 12:00:00');

    Log::spy();

    config()->set('platform_audit_retention.cross_tenant_admin_audit_logs.retention_days', 400);
    config()->set('platform_audit_retention.cross_tenant_admin_audit_logs.purge.batch_size', 1);
    config()->set('platform_audit_retention.cross_tenant_admin_audit_logs.purge.schedule.enabled', true);
    config()->set('platform_audit_retention.cross_tenant_admin_audit_logs.purge.schedule.allowed_environments', ['testing']);
    config()->set('platform_audit_retention.cross_tenant_admin_audit_logs.holds.governance.enforce_two_person_control', true);

    seedCrossTenantAdminAuditLogForRetentionReport('platform-admin.patients.search', 'EAH', now()->subDays(700));
    seedCrossTenantAdminAuditLogForRetentionReport('platform-admin.patients.search', 'EAH', now()->subDays(500));
    seedCrossTenantAdminAuditLogForRetentionReport('platform-admin.patients.search', 'EAH', now()->subDays(5));

    $totalBefore = CrossTenantAdminAuditLogModel::query()->count();

    $exitCode = Artisan::call('platform:cross-tenant-audit-logs:retention-purge-scheduled', [
        '--tenantCode' => 'EAH',
        '--action' => 'platform-admin.patients.search',
        '--json' => true,
    ]);

    expect($exitCode)->toBe(0);
    expect(CrossTenantAdminAuditLogModel::query()->count())->toBe($totalBefore - 1);

    Log::shouldHaveReceived('info')
        ->withArgs(function (string $message, array $context): bool {
            return $message === 'platform.cross_tenant_admin_audit_logs.retention_purge_scheduled'
                && ($context['status'] ?? null) === 'executed'
                && ($context['deletedRows'] ?? null) === 1
                && ($context['remainingCandidateRows'] ?? null) === 1;
        })
        ->atLeast()
        ->once();

    Log::shouldHaveReceived('info')
        ->withArgs(function (string $message, array $context): bool {
            return $message === 'platform.cross_tenant_admin_audit_logs.retention_purge_scheduled.metrics'
                && ($context['status'] ?? null) === 'executed'
                && ($context['deletedRows'] ?? null) === 1
                && ($context['remainingCandidateRows'] ?? null) === 1;
        })
        ->atLeast()
        ->once();

    Carbon::setTestNow();
});

it('emits backlog warning alert when scheduled retention purge leaves remaining candidates above threshold', function (): void {
    Carbon::setTestNow('2026-02-25 12:00:00');

    Log::spy();

    config()->set('platform_audit_retention.cross_tenant_admin_audit_logs.retention_days', 400);
    config()->set('platform_audit_retention.cross_tenant_admin_audit_logs.purge.batch_size', 1);
    config()->set('platform_audit_retention.cross_tenant_admin_audit_logs.purge.schedule.enabled', true);
    config()->set('platform_audit_retention.cross_tenant_admin_audit_logs.purge.schedule.allowed_environments', ['testing']);
    config()->set('platform_audit_retention.cross_tenant_admin_audit_logs.holds.governance.enforce_two_person_control', true);
    config()->set('platform_audit_retention.cross_tenant_admin_audit_logs.purge.monitoring.alerts_enabled', true);
    config()->set('platform_audit_retention.cross_tenant_admin_audit_logs.purge.monitoring.remaining_candidates_warning_threshold', 1);
    config()->set('platform_audit_retention.cross_tenant_admin_audit_logs.purge.monitoring.deleted_rows_warning_threshold', 9999);

    seedCrossTenantAdminAuditLogForRetentionReport('platform-admin.billing-invoices.search', 'EAH', now()->subDays(800));
    seedCrossTenantAdminAuditLogForRetentionReport('platform-admin.billing-invoices.search', 'EAH', now()->subDays(700));
    seedCrossTenantAdminAuditLogForRetentionReport('platform-admin.billing-invoices.search', 'EAH', now()->subDays(10));

    $exitCode = Artisan::call('platform:cross-tenant-audit-logs:retention-purge-scheduled', [
        '--tenantCode' => 'EAH',
        '--action' => 'platform-admin.billing-invoices.search',
        '--json' => true,
    ]);

    expect($exitCode)->toBe(0);

    Log::shouldHaveReceived('warning')
        ->withArgs(function (string $message, array $context): bool {
            return $message === 'platform.cross_tenant_admin_audit_logs.retention_purge_scheduled.alert'
                && ($context['alertType'] ?? null) === 'remaining_candidates_threshold_exceeded'
                && ($context['remainingCandidateRows'] ?? null) === 1
                && ($context['threshold'] ?? null) === 1;
        })
        ->atLeast()
        ->once();

    Carbon::setTestNow();
});

it('emits failure alert when scheduled retention purge wrapper cannot parse purge command output', function (): void {
    Log::spy();

    config()->set('platform_audit_retention.cross_tenant_admin_audit_logs.retention_days', 0);
    config()->set('platform_audit_retention.cross_tenant_admin_audit_logs.purge.batch_size', 1);
    config()->set('platform_audit_retention.cross_tenant_admin_audit_logs.purge.schedule.enabled', true);
    config()->set('platform_audit_retention.cross_tenant_admin_audit_logs.purge.schedule.allowed_environments', ['testing']);
    config()->set('platform_audit_retention.cross_tenant_admin_audit_logs.holds.governance.enforce_two_person_control', true);
    config()->set('platform_audit_retention.cross_tenant_admin_audit_logs.purge.monitoring.alerts_enabled', true);

    $exitCode = Artisan::call('platform:cross-tenant-audit-logs:retention-purge-scheduled', [
        '--json' => true,
    ]);

    expect($exitCode)->toBe(1);

    Log::shouldHaveReceived('error')
        ->withArgs(function (string $message, array $context): bool {
            return $message === 'platform.cross_tenant_admin_audit_logs.retention_purge_scheduled.alert'
                && ($context['alertType'] ?? null) === 'failed_unparseable_purge_output'
                && ($context['status'] ?? null) === 'failed_unparseable_purge_output';
        })
        ->atLeast()
        ->once();
});

function seedCrossTenantAdminAuditLogForRetentionReport(string $action, string $tenantCode, \DateTimeInterface $createdAt): void
{
    CrossTenantAdminAuditLogModel::query()->create([
        'id' => (string) Str::uuid(),
        'action' => $action,
        'operation_type' => 'read',
        'actor_id' => 1,
        'target_tenant_id' => (string) Str::uuid(),
        'target_tenant_code' => $tenantCode,
        'target_resource_type' => 'patient',
        'target_resource_id' => null,
        'outcome' => 'success',
        'reason' => 'retention report test seed',
        'metadata' => ['seeded' => true],
        'created_at' => $createdAt,
    ]);
}

function seedCrossTenantAdminAuditLogHoldForRetention(
    string $holdCode,
    ?string $tenantCode,
    ?string $action,
    ?\DateTimeInterface $startsAt,
    ?\DateTimeInterface $endsAt,
): void {
    CrossTenantAdminAuditLogHoldModel::query()->create([
        'id' => (string) Str::uuid(),
        'hold_code' => $holdCode,
        'reason' => 'retention hold test seed',
        'target_tenant_code' => $tenantCode,
        'action' => $action,
        'starts_at' => $startsAt,
        'ends_at' => $endsAt,
        'is_active' => true,
        'created_by_user_id' => null,
        'released_at' => null,
        'released_by_user_id' => null,
        'release_reason' => null,
    ]);
}
