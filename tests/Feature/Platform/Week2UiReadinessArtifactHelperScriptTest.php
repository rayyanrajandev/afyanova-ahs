<?php

use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

function writeWeek2FixtureCards(string $path, array $cardStatuses): void
{
    $rows = [];
    foreach ($cardStatuses as $card => $status) {
        $rows[] = sprintf(
            '| %s | Module | Task | Owner | 2026-03-21 | Criteria | %s |',
            $card,
            $status
        );
    }

    file_put_contents($path, implode(PHP_EOL, [
        '# Week 2 UI Task Cards',
        '',
        '| Card ID | Module | Task | Owner | Due Date | Done Criteria | Status |',
        '| --- | --- | --- | --- | --- | --- | --- |',
        ...$rows,
        '',
    ]));
}

function writeWeek2EvidenceDoc(string $path, string $status): void
{
    file_put_contents($path, implode(PHP_EOL, [
        '# Evidence',
        '# Due: 2026-03-21 | Status: '.$status,
        '',
    ]));
}

it('writes week 2 readiness artifact and exits zero when all week 2 cards are done', function (): void {
    $fixtureDir = storage_path('app/testing/week2-readiness-'.Str::uuid());
    mkdir($fixtureDir, 0777, true);

    $week2CardsPath = $fixtureDir.'/WEEK2_UI_TASK_CARDS_2026-03-06.md';
    writeWeek2FixtureCards($week2CardsPath, [
        'W2-01' => 'Done (2026-03-06)',
        'W2-02' => 'Done (2026-03-06)',
        'W2-03' => 'Done (2026-03-06)',
        'W2-04' => 'Done (2026-03-06)',
        'W2-05' => 'Done (2026-03-06)',
        'W2-06' => 'Done (2026-03-06)',
        'W2-07' => 'Done (2026-03-06)',
        'W2-08' => 'Done (2026-03-06)',
        'W2-09' => 'Done (2026-03-06)',
        'W2-10' => 'Done (2026-03-06)',
    ]);

    writeWeek2EvidenceDoc($fixtureDir.'/W2-04_PATIENTS_KPI_VALIDATION_EVIDENCE_2026-03-06.md', 'Done (2026-03-06)');
    writeWeek2EvidenceDoc($fixtureDir.'/W2-08_APPOINTMENTS_KPI_VALIDATION_EVIDENCE_2026-03-06.md', 'Done (2026-03-06)');
    writeWeek2EvidenceDoc($fixtureDir.'/W2-09_CROSS_MODULE_I18N_LOCALE_EVIDENCE_2026-03-06.md', 'Done (2026-03-06)');
    writeWeek2EvidenceDoc($fixtureDir.'/W2-10_WEEK2_RELEASE_READINESS_SIGNOFF_2026-03-06.md', 'Done (2026-03-06)');

    $artifactPath = storage_path('app/deployment-artifacts/'.Str::uuid().'-week2-ui-readiness-ready.json');
    @unlink($artifactPath);

    $process = new Process(
        [
            PHP_BINARY,
            'scripts/export-week2-ui-readiness-artifact.php',
            '--week2-cards='.$week2CardsPath,
            '--evidence-dir='.$fixtureDir,
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
    expect(data_get($decoded, 'readiness.eligibleForWeek3'))->toBeTrue();
    expect(data_get($decoded, 'readiness.openCards'))->toBe([]);
});

it('writes week 2 readiness artifact and exits non-zero when KPI/signoff cards are still open', function (): void {
    $fixtureDir = storage_path('app/testing/week2-readiness-'.Str::uuid());
    mkdir($fixtureDir, 0777, true);

    $week2CardsPath = $fixtureDir.'/WEEK2_UI_TASK_CARDS_2026-03-06.md';
    writeWeek2FixtureCards($week2CardsPath, [
        'W2-01' => 'Done (2026-03-06)',
        'W2-02' => 'Done (2026-03-06)',
        'W2-03' => 'Done (2026-03-06)',
        'W2-04' => 'In Progress',
        'W2-05' => 'Done (2026-03-06)',
        'W2-06' => 'Done (2026-03-06)',
        'W2-07' => 'Done (2026-03-06)',
        'W2-08' => 'In Progress',
        'W2-09' => 'Done (2026-03-06)',
        'W2-10' => 'In Progress',
    ]);

    writeWeek2EvidenceDoc($fixtureDir.'/W2-04_PATIENTS_KPI_VALIDATION_EVIDENCE_2026-03-06.md', 'In Progress');
    writeWeek2EvidenceDoc($fixtureDir.'/W2-08_APPOINTMENTS_KPI_VALIDATION_EVIDENCE_2026-03-06.md', 'In Progress');
    writeWeek2EvidenceDoc($fixtureDir.'/W2-09_CROSS_MODULE_I18N_LOCALE_EVIDENCE_2026-03-06.md', 'Done (2026-03-06)');
    writeWeek2EvidenceDoc($fixtureDir.'/W2-10_WEEK2_RELEASE_READINESS_SIGNOFF_2026-03-06.md', 'In Progress');

    $artifactPath = storage_path('app/deployment-artifacts/'.Str::uuid().'-week2-ui-readiness-in-progress.json');
    @unlink($artifactPath);

    $process = new Process(
        [
            PHP_BINARY,
            'scripts/export-week2-ui-readiness-artifact.php',
            '--week2-cards='.$week2CardsPath,
            '--evidence-dir='.$fixtureDir,
            '--output='.$artifactPath,
            '--require-status=ready',
        ],
        base_path(),
    );

    $process->run();

    expect($process->getExitCode())->toBe(3);
    expect(file_exists($artifactPath))->toBeTrue();

    $decoded = json_decode((string) file_get_contents($artifactPath), true);
    expect($decoded)->toBeArray();
    expect($decoded['status'] ?? null)->toBe('in_progress_pending_kpi_signoff');
    expect(data_get($decoded, 'readiness.eligibleForWeek3'))->toBeFalse();
    expect(data_get($decoded, 'readiness.openCards'))->toContain('W2-04', 'W2-08', 'W2-10');
});
