<?php

use Illuminate\Support\Facades\Artisan;

beforeEach(function (): void {
    $this->phase5ReadinessFixturePaths = phase5TestingEnsureConfiguredReadinessFiles();
});

afterEach(function (): void {
    phase5TestingRemoveConfiguredReadinessFiles($this->phase5ReadinessFixturePaths ?? []);
});

it('reports ready when all configured phase 5 signed artifacts are present', function (): void {
    $exitCode = Artisan::call('platform:phase5:gate-readiness-check', [
        '--json' => true,
    ]);

    $output = Artisan::output();

    expect($exitCode)->toBe(0);
    expect($output)->toContain('"status": "ready"');
    expect($output)->toContain('"totalGates": 6');
    expect($output)->toContain('"signedCount": 6');
    expect($output)->toContain('"eligibleToClosePhase5Now": true');
});

it('reports missing signed artifacts when any configured phase 5 gate evidence file is absent', function (): void {
    config()->set(
        'phase5_readiness.gates.5.signedArtifact',
        'documents/99-internal/approvals/2026-03/__missing_g6_signoff__.md'
    );

    $exitCode = Artisan::call('platform:phase5:gate-readiness-check', [
        '--json' => true,
    ]);

    $output = Artisan::output();

    expect($exitCode)->toBe(1);
    expect($output)->toContain('"status": "not_ready_missing_signed_artifacts"');
    expect($output)->toContain('"missingCount": 1');
    expect($output)->toContain('"missingGateKeys": [');
    expect($output)->toContain('"G6"');
});

it('returns non-zero for unknown phase 5 gate selector', function (): void {
    $exitCode = Artisan::call('platform:phase5:gate-readiness-check', [
        '--gate' => 'G9',
        '--json' => true,
    ]);

    $output = Artisan::output();

    expect($exitCode)->toBe(1);
    expect($output)->toContain('"status": "not_ready_unknown_gate"');
    expect($output)->toContain('"requestedGate": "G9"');
});
