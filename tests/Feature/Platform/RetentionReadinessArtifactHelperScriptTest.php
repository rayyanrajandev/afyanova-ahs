<?php

use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

it('writes a readiness artifact and exits zero when required status is satisfied', function (): void {
    $artifactPath = storage_path('app/deployment-artifacts/'.Str::uuid().'-retention-readiness-ready.json');
    @unlink($artifactPath);

    $process = new Process(
        [
            PHP_BINARY,
            'scripts/export-retention-readiness-artifact.php',
            '--environment=testing',
            '--output='.$artifactPath,
            '--require-status=ready',
        ],
        base_path(),
        [
            'PLATFORM_CROSS_TENANT_AUDIT_LOG_RETENTION_SCHEDULE_ENABLED' => 'true',
            'PLATFORM_CROSS_TENANT_AUDIT_LOG_RETENTION_SCHEDULE_ALLOWED_ENVS' => 'testing',
            'PLATFORM_CROSS_TENANT_AUDIT_LOG_HOLDS_ENFORCE_TWO_PERSON_CONTROL' => 'true',
        ],
    );

    $process->run();

    expect($process->getExitCode())->toBe(0);
    expect(file_exists($artifactPath))->toBeTrue();

    $decoded = json_decode((string) file_get_contents($artifactPath), true);
    expect($decoded)->toBeArray();
    expect($decoded['status'] ?? null)->toBe('ready');
    expect($decoded['eligibleToRunScheduledWrapperNow'] ?? null)->toBeTrue();
});

it('writes a readiness artifact and exits non-zero when required status is not satisfied', function (): void {
    $artifactPath = storage_path('app/deployment-artifacts/'.Str::uuid().'-retention-readiness-waiver.json');
    @unlink($artifactPath);

    $process = new Process(
        [
            PHP_BINARY,
            'scripts/export-retention-readiness-artifact.php',
            '--environment=testing',
            '--output='.$artifactPath,
            '--require-status=ready',
        ],
        base_path(),
        [
            'PLATFORM_CROSS_TENANT_AUDIT_LOG_RETENTION_SCHEDULE_ENABLED' => 'true',
            'PLATFORM_CROSS_TENANT_AUDIT_LOG_RETENTION_SCHEDULE_ALLOWED_ENVS' => 'testing',
            'PLATFORM_CROSS_TENANT_AUDIT_LOG_HOLDS_ENFORCE_TWO_PERSON_CONTROL' => 'false',
            'PLATFORM_CROSS_TENANT_AUDIT_LOG_RETENTION_REQUIRE_TWO_PERSON_CONTROL_FOR_SCHEDULED_PURGE' => 'true',
            'PLATFORM_CROSS_TENANT_AUDIT_LOG_RETENTION_SCHEDULE_TWO_PERSON_CONTROL_WAIVER_ENABLED' => 'true',
            'PLATFORM_CROSS_TENANT_AUDIT_LOG_RETENTION_SCHEDULE_TWO_PERSON_CONTROL_WAIVER_REFERENCE' => 'WAIVER-TEST-001',
        ],
    );

    $process->run();

    expect($process->getExitCode())->toBe(3);
    expect(file_exists($artifactPath))->toBeTrue();

    $decoded = json_decode((string) file_get_contents($artifactPath), true);
    expect($decoded)->toBeArray();
    expect($decoded['status'] ?? null)->toBe('ready_with_waiver');
    expect(data_get($decoded, 'readinessGuard.waiver.valid'))->toBeTrue();
});
