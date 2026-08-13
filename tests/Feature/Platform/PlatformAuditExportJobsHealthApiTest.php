<?php

use App\Jobs\GenerateAuditExportCsvJob;
use App\Models\User;
use App\Modules\Platform\Infrastructure\Models\AuditExportJobModel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

it('requires authentication for audit export jobs health endpoint', function (): void {
    $this->getJson('/api/v1/platform/audit-export-jobs/health')
        ->assertUnauthorized();
});

it('requires authentication for audit export jobs health drilldown endpoint', function (): void {
    $this->getJson('/api/v1/platform/audit-export-jobs/health/drilldown')
        ->assertUnauthorized();
});

it('returns permission-aware and actor-scoped audit export health metrics', function (): void {
    Carbon::setTestNow('2026-02-27 12:00:00');

    $user = User::factory()->create();
    $user->givePermissionTo('billing-invoices.view-audit-logs');
    $user->givePermissionTo('laboratory-orders.view-audit-logs');

    $otherUser = User::factory()->create();
    $otherUser->givePermissionTo('billing-invoices.view-audit-logs');

    createAuditExportHealthJob(
        userId: $user->id,
        module: GenerateAuditExportCsvJob::MODULE_BILLING,
        status: 'queued',
        createdAt: now(),
    );
    createAuditExportHealthJob(
        userId: $user->id,
        module: GenerateAuditExportCsvJob::MODULE_BILLING,
        status: 'failed',
        createdAt: now()->subDay(),
        failedAt: now()->subDay(),
        errorMessage: 'billing export failed',
    );
    createAuditExportHealthJob(
        userId: $user->id,
        module: GenerateAuditExportCsvJob::MODULE_LABORATORY,
        status: 'completed',
        createdAt: now(),
        completedAt: now(),
    );
    createAuditExportHealthJob(
        userId: $user->id,
        module: GenerateAuditExportCsvJob::MODULE_PHARMACY,
        status: 'failed',
        createdAt: now(),
        failedAt: now(),
        errorMessage: 'pharmacy export failed',
    );

    createAuditExportHealthJob(
        userId: $otherUser->id,
        module: GenerateAuditExportCsvJob::MODULE_BILLING,
        status: 'failed',
        createdAt: now(),
        failedAt: now(),
        errorMessage: 'other user billing failure',
    );

    $response = $this->actingAs($user)
        ->getJson('/api/v1/platform/audit-export-jobs/health?days=3&failureLimit=5')
        ->assertOk()
        ->assertJsonPath('data.windowDays', 3)
        ->assertJsonPath('data.permissions.billing', true)
        ->assertJsonPath('data.permissions.laboratory', true)
        ->assertJsonPath('data.permissions.pharmacy', false)
        ->assertJsonPath('data.modules.billing.accessible', true)
        ->assertJsonPath('data.modules.billing.currentBacklog', 1)
        ->assertJsonPath('data.modules.billing.recentFailed', 1)
        ->assertJsonPath('data.modules.billing.totalRecent', 2)
        ->assertJsonPath('data.modules.laboratory.accessible', true)
        ->assertJsonPath('data.modules.laboratory.recentCompleted', 1)
        ->assertJsonPath('data.modules.pharmacy.accessible', false)
        ->assertJsonPath('data.modules.pharmacy.totalRecent', 0)
        ->assertJsonPath('data.aggregate.accessibleModuleCount', 2)
        ->assertJsonPath('data.aggregate.currentBacklog', 1)
        ->assertJsonPath('data.aggregate.recentFailed', 1)
        ->assertJsonPath('data.aggregate.recentCompleted', 1)
        ->assertJsonPath('data.aggregate.totalRecent', 3);

    $recentFailures = collect($response->json('data.recentFailures'));
    expect($recentFailures)->toHaveCount(1);
    expect($recentFailures->first()['moduleKey'] ?? null)->toBe('billing');
    expect($recentFailures->first()['errorMessage'] ?? null)->toBe('billing export failed');

    $trendByDate = collect($response->json('data.trend'))->keyBy('date');
    expect($trendByDate)->toHaveCount(3);
    expect((int) ($trendByDate[now()->toDateString()]['total'] ?? -1))->toBe(2);
    expect((int) ($trendByDate[now()->toDateString()]['backlogCreated'] ?? -1))->toBe(1);
    expect((int) ($trendByDate[now()->subDay()->toDateString()]['failed'] ?? -1))->toBe(1);

    Carbon::setTestNow();
});

it('returns empty aggregates when no audit export permissions are granted', function (): void {
    Carbon::setTestNow('2026-02-27 12:00:00');

    $user = User::factory()->create();

    createAuditExportHealthJob(
        userId: $user->id,
        module: GenerateAuditExportCsvJob::MODULE_BILLING,
        status: 'failed',
        createdAt: now(),
        failedAt: now(),
        errorMessage: 'hidden failure',
    );

    $this->actingAs($user)
        ->getJson('/api/v1/platform/audit-export-jobs/health')
        ->assertOk()
        ->assertJsonPath('data.permissions.billing', false)
        ->assertJsonPath('data.permissions.laboratory', false)
        ->assertJsonPath('data.permissions.pharmacy', false)
        ->assertJsonPath('data.aggregate.accessibleModuleCount', 0)
        ->assertJsonPath('data.aggregate.totalRecent', 0)
        ->assertJsonPath('data.aggregate.currentBacklog', 0)
        ->assertJsonCount(0, 'data.recentFailures');

    Carbon::setTestNow();
});

it('returns actor-scoped drilldown rows filtered by module status group and date', function (): void {
    Carbon::setTestNow('2026-02-27 12:00:00');

    $user = User::factory()->create();
    $user->givePermissionTo('billing-invoices.view-audit-logs');
    $user->givePermissionTo('laboratory-orders.view-audit-logs');
    $otherUser = User::factory()->create();
    $otherUser->givePermissionTo('billing-invoices.view-audit-logs');

    createAuditExportHealthJob(
        userId: $user->id,
        module: GenerateAuditExportCsvJob::MODULE_BILLING,
        status: 'failed',
        createdAt: now()->setTime(8, 0),
        failedAt: now()->setTime(8, 1),
        errorMessage: 'billing failed today',
    );
    createAuditExportHealthJob(
        userId: $user->id,
        module: GenerateAuditExportCsvJob::MODULE_BILLING,
        status: 'queued',
        createdAt: now()->setTime(9, 0),
    );
    createAuditExportHealthJob(
        userId: $user->id,
        module: GenerateAuditExportCsvJob::MODULE_BILLING,
        status: 'failed',
        createdAt: now()->subDay()->setTime(10, 0),
        failedAt: now()->subDay()->setTime(10, 5),
        errorMessage: 'billing failed yesterday',
    );
    createAuditExportHealthJob(
        userId: $user->id,
        module: GenerateAuditExportCsvJob::MODULE_LABORATORY,
        status: 'processing',
        createdAt: now()->setTime(11, 0),
    );
    createAuditExportHealthJob(
        userId: $user->id,
        module: GenerateAuditExportCsvJob::MODULE_PHARMACY,
        status: 'failed',
        createdAt: now()->setTime(9, 30),
        failedAt: now()->setTime(9, 35),
        errorMessage: 'pharmacy failed hidden',
    );
    createAuditExportHealthJob(
        userId: $otherUser->id,
        module: GenerateAuditExportCsvJob::MODULE_BILLING,
        status: 'failed',
        createdAt: now()->setTime(8, 30),
        failedAt: now()->setTime(8, 40),
        errorMessage: 'other actor billing failure',
    );

    $date = now()->toDateString();

    $failedResponse = $this->actingAs($user)
        ->getJson("/api/v1/platform/audit-export-jobs/health/drilldown?module=billing&statusGroup=failed&date={$date}&perPage=10")
        ->assertOk()
        ->assertJsonPath('meta.filters.module', 'billing')
        ->assertJsonPath('meta.filters.statusGroup', 'failed')
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.moduleKey', 'billing')
        ->assertJsonPath('data.0.status', 'failed')
        ->assertJsonPath('data.0.errorMessage', 'billing failed today')
        ->assertJsonPath('data.0.moduleRoute', '/billing-invoices');

    $failedIds = collect($failedResponse->json('data'))->pluck('id')->all();
    expect($failedIds)->toHaveCount(1);

    $this->actingAs($user)
        ->getJson("/api/v1/platform/audit-export-jobs/health/drilldown?module=all&statusGroup=backlog&date={$date}&perPage=10")
        ->assertOk()
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('data.0.status', 'processing')
        ->assertJsonPath('data.1.status', 'queued');

    $this->actingAs($user)
        ->getJson("/api/v1/platform/audit-export-jobs/health/drilldown?module=pharmacy&statusGroup=failed&date={$date}")
        ->assertOk()
        ->assertJsonPath('meta.permissions.pharmacy', false)
        ->assertJsonPath('meta.total', 0)
        ->assertJsonCount(0, 'data');

    Carbon::setTestNow();
});

function createAuditExportHealthJob(
    int $userId,
    string $module,
    string $status,
    \DateTimeInterface $createdAt,
    ?\DateTimeInterface $completedAt = null,
    ?\DateTimeInterface $failedAt = null,
    ?string $errorMessage = null,
): AuditExportJobModel {
    $job = AuditExportJobModel::query()->create([
        'module' => $module,
        'target_resource_id' => (string) Str::uuid(),
        'status' => $status,
        'filters' => ['q' => null],
        'created_by_user_id' => $userId,
        'completed_at' => $completedAt,
        'failed_at' => $failedAt,
        'error_message' => $errorMessage,
    ]);

    AuditExportJobModel::query()
        ->whereKey($job->id)
        ->update([
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

    return $job->fresh();
}
