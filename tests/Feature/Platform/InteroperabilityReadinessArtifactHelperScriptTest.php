<?php

use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

it('writes interoperability readiness artifact and exits zero when required baseline status is satisfied', function (): void {
    $artifactPath = storage_path('app/deployment-artifacts/'.Str::uuid().'-interoperability-readiness-baseline.json');
    @unlink($artifactPath);

    $process = new Process(
        [
            PHP_BINARY,
            'scripts/export-interoperability-readiness-artifact.php',
            '--version=v1',
            '--output='.$artifactPath,
            '--require-status=baseline_ready_partner_pending',
        ],
        base_path(),
    );

    $process->run();

    expect($process->getExitCode())->toBe(0);
    expect(file_exists($artifactPath))->toBeTrue();

    $decoded = json_decode((string) file_get_contents($artifactPath), true);
    expect($decoded)->toBeArray();
    expect($decoded['status'] ?? null)->toBe('baseline_ready_partner_pending');
    expect($decoded['evaluatedVersion'] ?? null)->toBe('v1');
});

it('writes interoperability readiness artifact and exits non-zero when strict required status is not satisfied', function (): void {
    $artifactPath = storage_path('app/deployment-artifacts/'.Str::uuid().'-interoperability-readiness-signoff.json');
    @unlink($artifactPath);

    $process = new Process(
        [
            PHP_BINARY,
            'scripts/export-interoperability-readiness-artifact.php',
            '--version=v1',
            '--partner=NHIF',
            '--output='.$artifactPath,
            '--require-status=signoff_ready',
        ],
        base_path(),
    );

    $process->run();

    expect($process->getExitCode())->toBe(3);
    expect(file_exists($artifactPath))->toBeTrue();

    $decoded = json_decode((string) file_get_contents($artifactPath), true);
    expect($decoded)->toBeArray();
    expect($decoded['status'] ?? null)->toBe('baseline_ready_execution_details_pending');
    expect($decoded['partner'] ?? null)->toBe('NHIF');
});
