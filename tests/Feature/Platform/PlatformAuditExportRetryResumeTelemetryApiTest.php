<?php

use App\Models\User;
use App\Modules\Platform\Infrastructure\Models\AuditExportRetryResumeTelemetryEventModel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

it('requires authentication for audit export retry-resume telemetry endpoints', function (): void {
    $this->postJson('/api/v1/platform/audit-export-jobs/retry-resume-telemetry/events', [
        'module' => 'billing',
        'event' => 'attempt',
    ])->assertUnauthorized();

    $this->getJson('/api/v1/platform/audit-export-jobs/retry-resume-telemetry/health')
        ->assertUnauthorized();

    $this->getJson('/api/v1/platform/audit-export-jobs/retry-resume-telemetry/health/drilldown')
        ->assertUnauthorized();

    $this->getJson('/api/v1/platform/audit-export-jobs/retry-resume-telemetry/health/drilldown/export')
        ->assertUnauthorized();
});

it('records retry-resume telemetry events and returns actor-scoped health aggregates', function (): void {
    Carbon::setTestNow('2026-02-27 12:00:00');
    Storage::fake('local');

    config([
        'platform_audit_retention.audit_export_retry_resume_telemetry.retention_days' => 30,
        'platform_audit_retention.audit_export_retry_resume_telemetry.observability.cleanup_last_report_path' => 'platform-audit/testing/retry-resume-telemetry-cleanup-last-report.json',
        'platform_audit_retention.audit_export_retry_resume_telemetry.observability.cleanup_stale_after_hours' => 6,
    ]);
    Storage::disk('local')->put(
        'platform-audit/testing/retry-resume-telemetry-cleanup-last-report.json',
        json_encode([
            'status' => 'success',
            'mode' => 'cleanup',
            'ranAt' => now()->subHours(3)->toIso8601String(),
            'retentionDays' => 30,
            'batchSize' => 1000,
            'deletionPerformed' => true,
            'truncatedByBatch' => false,
            'totals' => [
                'totalRows' => 42,
                'candidateRowsBefore' => 10,
                'candidateRowsDeleted' => 10,
                'candidateRowsRemaining' => 0,
            ],
        ], JSON_THROW_ON_ERROR),
    );

    $user = User::factory()->create();
    $user->givePermissionTo('billing-invoices.view-audit-logs');
    $user->givePermissionTo('laboratory-orders.view-audit-logs');

    $otherUser = User::factory()->create();
    $otherUser->givePermissionTo('billing-invoices.view-audit-logs');

    $this->actingAs($user)
        ->postJson('/api/v1/platform/audit-export-jobs/retry-resume-telemetry/events', [
            'module' => 'billing',
            'event' => 'attempt',
        ])
        ->assertCreated()
        ->assertJsonPath('data.module', 'billing')
        ->assertJsonPath('data.event', 'attempt');

    createAuditExportRetryResumeTelemetryEvent(
        userId: $user->id,
        module: 'billing',
        event: 'success',
        occurredAt: now()->subMinute(),
    );
    createAuditExportRetryResumeTelemetryEvent(
        userId: $user->id,
        module: 'billing',
        event: 'failure',
        failureReason: 'target_export_job_focus_failed',
        occurredAt: now()->subMinutes(2),
    );
    createAuditExportRetryResumeTelemetryEvent(
        userId: $user->id,
        module: 'laboratory',
        event: 'attempt',
        occurredAt: now()->subMinutes(3),
    );
    createAuditExportRetryResumeTelemetryEvent(
        userId: $user->id,
        module: 'laboratory',
        event: 'failure',
        failureReason: 'target_order_lookup_failed',
        occurredAt: now()->subMinutes(4),
    );
    createAuditExportRetryResumeTelemetryEvent(
        userId: $user->id,
        module: 'billing',
        event: 'failure',
        failureReason: 'retention_expired_row',
        occurredAt: now()->subDays(95),
    );
    createAuditExportRetryResumeTelemetryEvent(
        userId: $otherUser->id,
        module: 'billing',
        event: 'failure',
        failureReason: 'other_actor_failure',
        occurredAt: now()->subMinutes(5),
    );

    $response = $this->actingAs($user)
        ->getJson('/api/v1/platform/audit-export-jobs/retry-resume-telemetry/health?days=3&failureLimit=5')
        ->assertOk()
        ->assertJsonPath('data.windowDays', 3)
        ->assertJsonPath('data.permissions.billing', true)
        ->assertJsonPath('data.permissions.laboratory', true)
        ->assertJsonPath('data.permissions.pharmacy', false)
        ->assertJsonPath('data.modules.billing.attempts', 1)
        ->assertJsonPath('data.modules.billing.successes', 1)
        ->assertJsonPath('data.modules.billing.failures', 1)
        ->assertJsonPath('data.modules.laboratory.attempts', 1)
        ->assertJsonPath('data.modules.laboratory.failures', 1)
        ->assertJsonPath('data.modules.pharmacy.totalEvents', 0)
        ->assertJsonPath('data.aggregate.accessibleModuleCount', 2)
        ->assertJsonPath('data.aggregate.attempts', 2)
        ->assertJsonPath('data.aggregate.successes', 1)
        ->assertJsonPath('data.aggregate.failures', 2)
        ->assertJsonPath('data.cleanupObservability.retentionDays', 30)
        ->assertJsonPath('data.cleanupObservability.staleAfterHours', 6)
        ->assertJsonPath('data.cleanupObservability.retainedRowsEstimate', 5)
        ->assertJsonPath('data.cleanupObservability.expiredRowsEstimate', 1)
        ->assertJsonPath('data.cleanupObservability.moduleSlices.billing.accessible', true)
        ->assertJsonPath('data.cleanupObservability.moduleSlices.billing.retainedRowsEstimate', 3)
        ->assertJsonPath('data.cleanupObservability.moduleSlices.billing.expiredRowsEstimate', 1)
        ->assertJsonPath('data.cleanupObservability.moduleSlices.laboratory.accessible', true)
        ->assertJsonPath('data.cleanupObservability.moduleSlices.laboratory.retainedRowsEstimate', 2)
        ->assertJsonPath('data.cleanupObservability.moduleSlices.laboratory.expiredRowsEstimate', 0)
        ->assertJsonPath('data.cleanupObservability.moduleSlices.pharmacy.accessible', false)
        ->assertJsonPath('data.cleanupObservability.moduleSlices.pharmacy.retainedRowsEstimate', 0)
        ->assertJsonPath('data.cleanupObservability.moduleSlices.pharmacy.expiredRowsEstimate', 0)
        ->assertJsonPath('data.cleanupObservability.lastCleanup.status', 'success')
        ->assertJsonPath('data.cleanupObservability.lastCleanup.lagHours', 3)
        ->assertJsonPath('data.cleanupObservability.lastCleanup.lagStatus', 'fresh');

    $recentFailures = collect($response->json('data.recentFailures'));
    expect($recentFailures)->toHaveCount(2);
    expect($recentFailures->pluck('moduleKey')->all())->toContain('billing', 'laboratory');
    expect($recentFailures->pluck('failureReason')->all())->toContain(
        'target_export_job_focus_failed',
        'target_order_lookup_failed',
    );

    Carbon::setTestNow();
});

it('forbids recording retry-resume telemetry when module permission is missing', function (): void {
    $user = User::factory()->create();
    $user->givePermissionTo('billing-invoices.view-audit-logs');

    $this->actingAs($user)
        ->postJson('/api/v1/platform/audit-export-jobs/retry-resume-telemetry/events', [
            'module' => 'pharmacy',
            'event' => 'attempt',
        ])
        ->assertForbidden();
});

it('returns actor-scoped retry-resume telemetry drilldown rows and failure-reason slices', function (): void {
    Carbon::setTestNow('2026-02-27 12:00:00');

    $user = User::factory()->create();
    $user->givePermissionTo('billing-invoices.view-audit-logs');
    $user->givePermissionTo('laboratory-orders.view-audit-logs');

    $otherUser = User::factory()->create();
    $otherUser->givePermissionTo('billing-invoices.view-audit-logs');

    createAuditExportRetryResumeTelemetryEvent(
        userId: $user->id,
        module: 'billing',
        event: 'failure',
        failureReason: 'target_export_job_focus_failed',
        occurredAt: now()->setTime(11, 55),
        targetResourceId: '9e205213-2b84-4ca6-8a97-960d05053f20',
        exportJobId: '2be5dd17-3403-42a2-85e0-8cc704eba9d8',
        handoffStatusGroup: 'failed',
        handoffPage: 2,
        handoffPerPage: 25,
    );
    createAuditExportRetryResumeTelemetryEvent(
        userId: $user->id,
        module: 'billing',
        event: 'failure',
        failureReason: 'target_invoice_lookup_failed',
        occurredAt: now()->setTime(11, 54),
    );
    createAuditExportRetryResumeTelemetryEvent(
        userId: $user->id,
        module: 'billing',
        event: 'attempt',
        occurredAt: now()->setTime(11, 53),
    );
    createAuditExportRetryResumeTelemetryEvent(
        userId: $user->id,
        module: 'laboratory',
        event: 'failure',
        failureReason: 'target_order_lookup_failed',
        occurredAt: now()->setTime(11, 52),
    );
    createAuditExportRetryResumeTelemetryEvent(
        userId: $user->id,
        module: 'laboratory',
        event: 'success',
        occurredAt: now()->setTime(11, 51),
    );
    createAuditExportRetryResumeTelemetryEvent(
        userId: $user->id,
        module: 'pharmacy',
        event: 'failure',
        failureReason: 'hidden_pharmacy_failure',
        occurredAt: now()->setTime(11, 50),
    );
    createAuditExportRetryResumeTelemetryEvent(
        userId: $otherUser->id,
        module: 'billing',
        event: 'failure',
        failureReason: 'other_actor_failure',
        occurredAt: now()->setTime(11, 59),
    );

    $date = now()->toDateString();

    $failedBillingResponse = $this->actingAs($user)
        ->getJson("/api/v1/platform/audit-export-jobs/retry-resume-telemetry/health/drilldown?module=billing&event=failure&date={$date}&perPage=1")
        ->assertOk()
        ->assertJsonPath('meta.filters.module', 'billing')
        ->assertJsonPath('meta.filters.event', 'failure')
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('meta.lastPage', 2)
        ->assertJsonPath('data.0.moduleKey', 'billing')
        ->assertJsonPath('data.0.event', 'failure')
        ->assertJsonPath('data.0.moduleRoute', '/billing-invoices')
        ->assertJsonPath('data.0.targetResourceId', '9e205213-2b84-4ca6-8a97-960d05053f20')
        ->assertJsonPath('data.0.exportJobId', '2be5dd17-3403-42a2-85e0-8cc704eba9d8')
        ->assertJsonPath('data.0.handoffStatusGroup', 'failed')
        ->assertJsonPath('data.0.handoffPage', 2)
        ->assertJsonPath('data.0.handoffPerPage', 25);

    $slice = collect($failedBillingResponse->json('meta.failureReasonSlice'));
    expect($slice->pluck('reason')->all())->toContain(
        'target_export_job_focus_failed',
        'target_invoice_lookup_failed',
    );

    $this->actingAs($user)
        ->getJson("/api/v1/platform/audit-export-jobs/retry-resume-telemetry/health/drilldown?module=all&event=failure&date={$date}&failureReason=focus")
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.failureReason', 'target_export_job_focus_failed');

    $this->actingAs($user)
        ->getJson("/api/v1/platform/audit-export-jobs/retry-resume-telemetry/health/drilldown?module=pharmacy&event=failure&date={$date}")
        ->assertOk()
        ->assertJsonPath('meta.permissions.pharmacy', false)
        ->assertJsonPath('meta.total', 0)
        ->assertJsonCount(0, 'data');

    Carbon::setTestNow();
});

it('exports retry-resume telemetry drilldown csv with queue handoff columns', function (): void {
    Carbon::setTestNow('2026-02-27 12:00:00');

    $user = User::factory()->create();
    $user->givePermissionTo('billing-invoices.view-audit-logs');

    createAuditExportRetryResumeTelemetryEvent(
        userId: $user->id,
        module: 'billing',
        event: 'failure',
        failureReason: 'target_export_job_focus_failed',
        occurredAt: now()->setTime(11, 59),
        targetResourceId: 'e8025502-5ef2-4ca1-868c-06f0f6f87cc6',
        exportJobId: 'f261188f-a4d4-410a-9dd4-f40453d27e72',
        handoffStatusGroup: 'failed',
        handoffPage: 3,
        handoffPerPage: 20,
    );

    $response = $this->actingAs($user)
        ->get('/api/v1/platform/audit-export-jobs/retry-resume-telemetry/health/drilldown/export?module=billing&event=failure')
        ->assertOk()
        ->assertHeader('X-Audit-CSV-Schema-Version', 'audit-retry-resume-telemetry-csv.v1')
        ->assertHeader('X-Export-System-Name', 'Afyanova AHS')
        ->assertHeader('X-Export-System-Slug', 'afyanova_ahs');

    $content = $response->streamedContent();
    expect($content)->toContain('queueHandoffUrl');
    expect($content)->toContain('/billing-invoices?');
    expect($content)->toContain('auditExportJobId=f261188f-a4d4-410a-9dd4-f40453d27e72');

    Carbon::setTestNow();
});

function createAuditExportRetryResumeTelemetryEvent(
    int $userId,
    string $module,
    string $event,
    ?string $failureReason = null,
    ?\DateTimeInterface $occurredAt = null,
    ?string $targetResourceId = null,
    ?string $exportJobId = null,
    ?string $handoffStatusGroup = null,
    ?int $handoffPage = null,
    ?int $handoffPerPage = null,
): AuditExportRetryResumeTelemetryEventModel {
    return AuditExportRetryResumeTelemetryEventModel::query()->create([
        'module_key' => $module,
        'event_type' => $event,
        'failure_reason' => $failureReason,
        'actor_user_id' => $userId,
        'target_resource_id' => $targetResourceId,
        'export_job_id' => $exportJobId,
        'handoff_status_group' => $handoffStatusGroup,
        'handoff_page' => $handoffPage,
        'handoff_per_page' => $handoffPerPage,
        'occurred_at' => $occurredAt ?? now(),
    ]);
}
