<?php

use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    $this->phase5ReadinessFixturePaths = phase5TestingEnsureConfiguredReadinessFiles();
});

afterEach(function (): void {
    phase5TestingRemoveConfiguredReadinessFiles($this->phase5ReadinessFixturePaths ?? []);
});

it('writes phase 5 documentation-readiness artifact and exits zero when required status is satisfied', function (): void {
    $artifactPath = storage_path('app/deployment-artifacts/'.Str::uuid().'-phase5-documentation-readiness.json');
    @unlink($artifactPath);

    $process = new Process(
        [
            PHP_BINARY,
            'scripts/export-phase5-documentation-readiness-artifact.php',
            '--output='.$artifactPath,
            '--require-status=ready',
        ],
        base_path(),
    );

    $process->run();

    expect($process->getExitCode())->toBe(0);
    expect(file_exists($artifactPath))->toBeTrue();

    $decoded = json_decode((string) file_get_contents($artifactPath), true);
    expect($decoded)->toBeArray();
    expect($decoded['status'] ?? null)->toBe('ready');
    expect(data_get($decoded, 'readiness.eligibleToAdvanceNow'))->toBeTrue();
});

it('writes phase 5 documentation-readiness artifact and exits non-zero when strict required status is not satisfied', function (): void {
    $artifactPath = storage_path('app/deployment-artifacts/'.Str::uuid().'-phase5-documentation-readiness-legal.json');
    @unlink($artifactPath);

    $process = new Process(
        [
            PHP_BINARY,
            'scripts/export-phase5-documentation-readiness-artifact.php',
            '--module=legal_citation_pack',
            '--output='.$artifactPath,
            '--require-status=not_ready_missing_required_files',
        ],
        base_path(),
    );

    $process->run();

    expect($process->getExitCode())->toBe(3);
    expect(file_exists($artifactPath))->toBeTrue();

    $decoded = json_decode((string) file_get_contents($artifactPath), true);
    expect($decoded)->toBeArray();
    expect($decoded['status'] ?? null)->toBe('ready');
    expect($decoded['requestedModule'] ?? null)->toBe('legal_citation_pack');
});
