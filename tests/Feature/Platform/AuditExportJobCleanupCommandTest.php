<?php

use App\Jobs\GenerateAuditExportCsvJob;
use App\Modules\Platform\Infrastructure\Models\AuditExportJobModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('reports audit export cleanup candidates in dry run mode without deleting rows or files', function (): void {
    Carbon::setTestNow('2026-02-27 12:00:00');
    Storage::fake('local');
    config()->set('platform_audit_retention.audit_export_jobs.file_directory', 'audit-exports');

    createAuditExportJobForCleanup(
        status: 'completed',
        createdAt: now()->subDays(45),
        completedAt: now()->subDays(45),
        filePath: 'audit-exports/old-completed.csv',
    );
    createAuditExportJobForCleanup(
        status: 'completed',
        createdAt: now()->subDays(5),
        completedAt: now()->subDays(5),
        filePath: 'audit-exports/recent-completed.csv',
    );

    Storage::disk('local')->put('audit-exports/old-completed.csv', "header\n");
    Storage::disk('local')->put('audit-exports/recent-completed.csv', "header\n");
    Storage::disk('local')->put('audit-exports/orphan-old.csv', "header\n");
    touch(Storage::disk('local')->path('audit-exports/orphan-old.csv'), now()->subDays(50)->getTimestamp());

    $exitCode = Artisan::call('platform:audit-export-jobs:cleanup', [
        '--days' => 30,
        '--batch' => 50,
        '--json' => true,
    ]);

    $report = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0);
    expect($report['mode'])->toBe('dry_run');
    expect($report['totals']['candidateRowsBefore'])->toBe(1);
    expect($report['totals']['candidateRowsDeleted'])->toBe(0);
    expect($report['staleOrphanFiles']['staleOrphanFilesBefore'])->toBe(1);
    expect($report['deletionPerformed'])->toBeFalse();
    expect(AuditExportJobModel::query()->count())->toBe(2);
    Storage::disk('local')->assertExists('audit-exports/old-completed.csv');
    Storage::disk('local')->assertExists('audit-exports/orphan-old.csv');

    Carbon::setTestNow();
});

it('deletes expired audit export jobs and stale orphan files when confirmed', function (): void {
    Carbon::setTestNow('2026-02-27 12:00:00');
    Storage::fake('local');
    config()->set('platform_audit_retention.audit_export_jobs.file_directory', 'audit-exports');

    createAuditExportJobForCleanup(
        status: 'completed',
        createdAt: now()->subDays(60),
        completedAt: now()->subDays(60),
        filePath: 'audit-exports/old-completed.csv',
    );
    createAuditExportJobForCleanup(
        status: 'failed',
        createdAt: now()->subDays(55),
        failedAt: now()->subDays(55),
        filePath: 'audit-exports/missing-old.csv',
    );
    createAuditExportJobForCleanup(
        status: 'queued',
        createdAt: now()->subDays(40),
        filePath: null,
    );
    createAuditExportJobForCleanup(
        status: 'completed',
        createdAt: now()->subDays(3),
        completedAt: now()->subDays(3),
        filePath: 'audit-exports/recent-completed.csv',
    );

    Storage::disk('local')->put('audit-exports/old-completed.csv', "header\n");
    Storage::disk('local')->put('audit-exports/recent-completed.csv', "header\n");
    Storage::disk('local')->put('audit-exports/orphan-old.csv', "header\n");
    Storage::disk('local')->put('audit-exports/orphan-recent.csv', "header\n");

    touch(Storage::disk('local')->path('audit-exports/orphan-old.csv'), now()->subDays(45)->getTimestamp());
    touch(Storage::disk('local')->path('audit-exports/orphan-recent.csv'), now()->subDays(2)->getTimestamp());

    $exitCode = Artisan::call('platform:audit-export-jobs:cleanup', [
        '--days' => 30,
        '--batch' => 10,
        '--confirm' => true,
        '--json' => true,
    ]);

    $report = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0);
    expect($report['mode'])->toBe('cleanup');
    expect($report['totals']['candidateRowsBefore'])->toBe(3);
    expect($report['totals']['candidateRowsDeleted'])->toBe(3);
    expect($report['totals']['candidateRowsRemaining'])->toBe(0);
    expect($report['candidateFiles']['deletedFiles'])->toBe(1);
    expect($report['candidateFiles']['missingFiles'])->toBe(1);
    expect($report['staleOrphanFiles']['deletedOrphanFiles'])->toBe(1);
    expect($report['deletionPerformed'])->toBeTrue();

    expect(AuditExportJobModel::query()->count())->toBe(1);
    Storage::disk('local')->assertMissing('audit-exports/old-completed.csv');
    Storage::disk('local')->assertExists('audit-exports/recent-completed.csv');
    Storage::disk('local')->assertMissing('audit-exports/orphan-old.csv');
    Storage::disk('local')->assertExists('audit-exports/orphan-recent.csv');

    Carbon::setTestNow();
});

it('respects cleanup batch size and leaves remaining candidates for later runs', function (): void {
    Carbon::setTestNow('2026-02-27 12:00:00');
    Storage::fake('local');
    config()->set('platform_audit_retention.audit_export_jobs.file_directory', 'audit-exports');

    createAuditExportJobForCleanup(
        status: 'completed',
        createdAt: now()->subDays(50),
        completedAt: now()->subDays(50),
        filePath: null,
    );
    createAuditExportJobForCleanup(
        status: 'failed',
        createdAt: now()->subDays(49),
        failedAt: now()->subDays(49),
        filePath: null,
    );
    createAuditExportJobForCleanup(
        status: 'queued',
        createdAt: now()->subDays(48),
        filePath: null,
    );

    $exitCode = Artisan::call('platform:audit-export-jobs:cleanup', [
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
    expect(AuditExportJobModel::query()->count())->toBe(1);

    Carbon::setTestNow();
});

function createAuditExportJobForCleanup(
    string $status,
    \DateTimeInterface $createdAt,
    ?\DateTimeInterface $completedAt = null,
    ?\DateTimeInterface $failedAt = null,
    ?string $filePath = null,
): AuditExportJobModel {
    $job = AuditExportJobModel::query()->create([
        'module' => GenerateAuditExportCsvJob::MODULE_BILLING,
        'target_resource_id' => (string) Str::uuid(),
        'status' => $status,
        'filters' => ['q' => null],
        'file_path' => $filePath,
        'file_name' => $filePath !== null ? basename($filePath) : null,
        'created_by_user_id' => 1,
        'completed_at' => $completedAt,
        'failed_at' => $failedAt,
    ]);

    AuditExportJobModel::query()
        ->whereKey($job->id)
        ->update([
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

    return $job->fresh();
}
