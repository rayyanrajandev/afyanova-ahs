<?php

use Illuminate\Support\Facades\Artisan;

it('reports baseline-ready status with partner pending for default interoperability version', function (): void {
    $exitCode = Artisan::call('platform:interoperability:readiness-signoff-check', [
        '--json' => true,
    ]);

    $output = Artisan::output();

    expect($exitCode)->toBe(0);
    expect($output)->toContain('"status": "baseline_ready_partner_pending"');
    expect($output)->toContain('"evaluatedVersion": "v1"');
    expect($output)->toContain('"flowCount": 4');
    expect($output)->toContain('"controlCount": 5');
});

it('normalizes version input and reports pending execution details when partner is provided', function (): void {
    $exitCode = Artisan::call('platform:interoperability:readiness-signoff-check', [
        '--contract-version' => ' V1 ',
        '--partner' => 'NHIF',
        '--json' => true,
    ]);

    $output = Artisan::output();

    expect($exitCode)->toBe(0);
    expect($output)->toContain('"status": "baseline_ready_execution_details_pending"');
    expect($output)->toContain('"evaluatedVersion": "v1"');
    expect($output)->toContain('"partner": "NHIF"');
    expect($output)->toContain('"pendingOperationalCheckCount": 3');
});

it('returns non-zero for unknown interoperability adapter version', function (): void {
    $exitCode = Artisan::call('platform:interoperability:readiness-signoff-check', [
        '--contract-version' => 'v9',
        '--json' => true,
    ]);

    $output = Artisan::output();

    expect($exitCode)->toBe(1);
    expect($output)->toContain('"status": "not_ready_unknown_version"');
    expect($output)->toContain('"evaluatedVersion": "v9"');
});
