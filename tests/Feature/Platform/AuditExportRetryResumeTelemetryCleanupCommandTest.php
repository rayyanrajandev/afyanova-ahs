<?php

use App\Modules\Platform\Infrastructure\Models\AuditExportRetryResumeTelemetryEventModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('reports retry-resume telemetry cleanup candidates in dry run mode without deleting rows', function (): void {
    Carbon::setTestNow('2026-02-27 12:00:00');

    createRetryResumeTelemetryEventForCleanup(now()->subDays(90));
    createRetryResumeTelemetryEventForCleanup(now()->subDays(5));

    $exitCode = Artisan::call('platform:audit-export-retry-resume-telemetry:cleanup', [
        '--days' => 30,
        '--batch' => 50,
        '--json' => true,
    ]);

    $report = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0);
    expect($report['mode'])->toBe('dry_run');
    expect($report['totals']['candidateRowsBefore'])->toBe(1);
    expect($report['totals']['candidateRowsDeleted'])->toBe(0);
    expect($report['deletionPerformed'])->toBeFalse();
    expect(AuditExportRetryResumeTelemetryEventModel::query()->count())->toBe(2);

    Carbon::setTestNow();
});

it('deletes expired retry-resume telemetry events when confirmed', function (): void {
    Carbon::setTestNow('2026-02-27 12:00:00');

    createRetryResumeTelemetryEventForCleanup(now()->subDays(95));
    createRetryResumeTelemetryEventForCleanup(now()->subDays(65));
    createRetryResumeTelemetryEventForCleanup(now()->subDays(2));

    $exitCode = Artisan::call('platform:audit-export-retry-resume-telemetry:cleanup', [
        '--days' => 30,
        '--batch' => 50,
        '--confirm' => true,
        '--json' => true,
    ]);

    $report = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0);
    expect($report['mode'])->toBe('cleanup');
    expect($report['totals']['candidateRowsBefore'])->toBe(2);
    expect($report['totals']['candidateRowsDeleted'])->toBe(2);
    expect($report['totals']['candidateRowsRemaining'])->toBe(0);
    expect($report['deletionPerformed'])->toBeTrue();
    expect(AuditExportRetryResumeTelemetryEventModel::query()->count())->toBe(1);

    Carbon::setTestNow();
});

it('respects telemetry cleanup batch size and leaves remaining candidates for later runs', function (): void {
    Carbon::setTestNow('2026-02-27 12:00:00');

    createRetryResumeTelemetryEventForCleanup(now()->subDays(80));
    createRetryResumeTelemetryEventForCleanup(now()->subDays(79));
    createRetryResumeTelemetryEventForCleanup(now()->subDays(78));

    $exitCode = Artisan::call('platform:audit-export-retry-resume-telemetry:cleanup', [
        '--days' => 30,
        '--batch' => 2,
        '--confirm' => true,
        '--json' => true,
    ]);

    $report = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0);
    expect($report['totals']['candidateRowsBefore'])->toBe(3);
    expect($report['totals']['candidateRowsDeleted'])->toBe(2);
    expect($report['totals']['candidateRowsRemaining'])->toBe(1);
    expect($report['truncatedByBatch'])->toBeTrue();
    expect(AuditExportRetryResumeTelemetryEventModel::query()->count())->toBe(1);

    Carbon::setTestNow();
});

it('persists retry-resume telemetry cleanup last report for dashboard observability', function (): void {
    Carbon::setTestNow('2026-02-27 12:00:00');
    Storage::fake('local');

    config([
        'platform_audit_retention.audit_export_retry_resume_telemetry.observability.cleanup_last_report_path' => 'platform-audit/testing/retry-resume-telemetry-cleanup-last-report.json',
    ]);

    createRetryResumeTelemetryEventForCleanup(now()->subDays(95));
    createRetryResumeTelemetryEventForCleanup(now()->subDays(2));

    $exitCode = Artisan::call('platform:audit-export-retry-resume-telemetry:cleanup', [
        '--days' => 30,
        '--batch' => 50,
        '--confirm' => true,
        '--json' => true,
    ]);

    expect($exitCode)->toBe(0);
    Storage::disk('local')->assertExists('platform-audit/testing/retry-resume-telemetry-cleanup-last-report.json');

    $storedReport = json_decode(
        Storage::disk('local')->get('platform-audit/testing/retry-resume-telemetry-cleanup-last-report.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    expect($storedReport['status'])->toBe('success');
    expect($storedReport['command'])->toBe('platform:audit-export-retry-resume-telemetry:cleanup');
    expect($storedReport['mode'])->toBe('cleanup');
    expect($storedReport['totals']['candidateRowsBefore'])->toBe(1);
    expect($storedReport['totals']['candidateRowsDeleted'])->toBe(1);
    expect($storedReport['totals']['candidateRowsRemaining'])->toBe(0);
    expect($storedReport['deletionPerformed'])->toBeTrue();
    expect($storedReport['ranAt'])->not->toBeNull();
    expect($storedReport['startedAt'])->not->toBeNull();

    Carbon::setTestNow();
});

function createRetryResumeTelemetryEventForCleanup(
    \DateTimeInterface $occurredAt,
): AuditExportRetryResumeTelemetryEventModel {
    $event = AuditExportRetryResumeTelemetryEventModel::query()->create([
        'module_key' => 'billing',
        'event_type' => 'failure',
        'failure_reason' => 'target_export_job_focus_failed',
        'actor_user_id' => 1,
        'occurred_at' => $occurredAt,
    ]);

    AuditExportRetryResumeTelemetryEventModel::query()
        ->whereKey($event->id)
        ->update([
            'created_at' => $occurredAt,
            'updated_at' => $occurredAt,
        ]);

    return $event->fresh();
}
