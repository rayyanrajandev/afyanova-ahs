<?php

use Illuminate\Support\Facades\Artisan;

beforeEach(function (): void {
    $this->phase5ReadinessFixturePaths = phase5TestingEnsureConfiguredReadinessFiles();
});

afterEach(function (): void {
    phase5TestingRemoveConfiguredReadinessFiles($this->phase5ReadinessFixturePaths ?? []);
});

it('reports ready for phase 5 documentation readiness baseline', function (): void {
    $exitCode = Artisan::call('platform:phase5:documentation-readiness-check', [
        '--json' => true,
    ]);

    $output = Artisan::output();

    expect($exitCode)->toBe(0);
    expect($output)->toContain('"status": "ready"');
    expect($output)->toContain('"totalModules": 12');
    expect($output)->toContain('"eligibleToAdvanceNow": true');
});

it('reports not-ready for unknown phase 5 documentation module selector', function (): void {
    $exitCode = Artisan::call('platform:phase5:documentation-readiness-check', [
        '--module' => 'unknown',
        '--json' => true,
    ]);

    $output = Artisan::output();

    expect($exitCode)->toBe(1);
    expect($output)->toContain('"status": "not_ready_unknown_module"');
    expect($output)->toContain('"requestedModule": "unknown"');
});

it('reports not-ready when required documentation files are missing', function (): void {
    config()->set('phase5_documentation_readiness.modules.0.requiredFiles', [
        'documents/04-compliance/__missing_phase5_tracker__.md',
    ]);

    $exitCode = Artisan::call('platform:phase5:documentation-readiness-check', [
        '--module' => 'approval_tracker',
        '--json' => true,
    ]);

    $output = Artisan::output();

    expect($exitCode)->toBe(1);
    expect($output)->toContain('"status": "not_ready_missing_required_files"');
    expect($output)->toContain('"notReadyModules": 1');
});

it('reports ready for data residency approval worksheet module', function (): void {
    $exitCode = Artisan::call('platform:phase5:documentation-readiness-check', [
        '--module' => 'data_residency_approval_worksheet',
        '--json' => true,
    ]);

    $output = Artisan::output();

    expect($exitCode)->toBe(0);
    expect($output)->toContain('"status": "ready"');
    expect($output)->toContain('"requestedModule": "data_residency_approval_worksheet"');
    expect($output)->toContain('"label": "Tanzania Data Residency Approval Worksheet"');
});

it('reports ready for phase 5 signature capture log module', function (): void {
    $exitCode = Artisan::call('platform:phase5:documentation-readiness-check', [
        '--module' => 'phase5_signature_capture_log',
        '--json' => true,
    ]);

    $output = Artisan::output();

    expect($exitCode)->toBe(0);
    expect($output)->toContain('"status": "ready"');
    expect($output)->toContain('"requestedModule": "phase5_signature_capture_log"');
    expect($output)->toContain('"label": "Tanzania Phase 5 Signature Capture Log"');
});

it('reports ready for phase 5 approvals archive readme module', function (): void {
    $exitCode = Artisan::call('platform:phase5:documentation-readiness-check', [
        '--module' => 'phase5_approvals_archive_readme',
        '--json' => true,
    ]);

    $output = Artisan::output();

    expect($exitCode)->toBe(0);
    expect($output)->toContain('"status": "ready"');
    expect($output)->toContain('"requestedModule": "phase5_approvals_archive_readme"');
    expect($output)->toContain('"label": "Tanzania Phase 5 Approvals Archive README"');
});

it('reports ready for phase 5 evidence capture pack module', function (): void {
    $exitCode = Artisan::call('platform:phase5:documentation-readiness-check', [
        '--module' => 'phase5_evidence_capture_pack',
        '--json' => true,
    ]);

    $output = Artisan::output();

    expect($exitCode)->toBe(0);
    expect($output)->toContain('"status": "ready"');
    expect($output)->toContain('"requestedModule": "phase5_evidence_capture_pack"');
    expect($output)->toContain('"label": "Tanzania Phase 5 Evidence Capture Pack"');
});

it('reports ready for phase 5 signer brief module', function (): void {
    $exitCode = Artisan::call('platform:phase5:documentation-readiness-check', [
        '--module' => 'phase5_signer_brief',
        '--json' => true,
    ]);

    $output = Artisan::output();

    expect($exitCode)->toBe(0);
    expect($output)->toContain('"status": "ready"');
    expect($output)->toContain('"requestedModule": "phase5_signer_brief"');
    expect($output)->toContain('"label": "Tanzania Phase 5 Signer Brief"');
});
