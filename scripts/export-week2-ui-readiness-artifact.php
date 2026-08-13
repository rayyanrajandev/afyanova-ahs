<?php

declare(strict_types=1);

/**
 * Export Week 2 UI readiness status to a JSON artifact using execution docs.
 *
 * Default output path:
 * storage/app/deployment-artifacts/week2_ui_readiness.json
 *
 * Exit code can be gated with:
 * --require-status=<csv> or --fail-on-status=<csv>.
 */

$args = array_slice($_SERVER['argv'] ?? [], 1);

$options = [
    'week2_cards' => null,
    'evidence_dir' => null,
    'output' => null,
    'fail_on_status' => [],
    'require_status' => [],
];

foreach ($args as $arg) {
    if ($arg === '--help' || $arg === '-h') {
        fwrite(
            STDOUT,
            "Usage: php scripts/export-week2-ui-readiness-artifact.php [--week2-cards=path] [--evidence-dir=path] [--output=path] [--fail-on-status=status1,status2] [--require-status=status1,status2]\n"
        );
        exit(0);
    }

    if (str_starts_with($arg, '--week2-cards=')) {
        $value = trim(substr($arg, strlen('--week2-cards=')));
        if ($value !== '') {
            $options['week2_cards'] = $value;
        }

        continue;
    }

    if (str_starts_with($arg, '--evidence-dir=')) {
        $value = trim(substr($arg, strlen('--evidence-dir=')));
        if ($value !== '') {
            $options['evidence_dir'] = $value;
        }

        continue;
    }

    if (str_starts_with($arg, '--output=')) {
        $value = trim(substr($arg, strlen('--output=')));
        if ($value !== '') {
            $options['output'] = $value;
        }

        continue;
    }

    if (str_starts_with($arg, '--fail-on-status=')) {
        $value = trim(substr($arg, strlen('--fail-on-status=')));
        if ($value !== '') {
            $options['fail_on_status'] = array_values(array_filter(array_map(
                static fn (string $status): string => trim($status),
                explode(',', $value)
            ), static fn (string $status): bool => $status !== ''));
        }

        continue;
    }

    if (str_starts_with($arg, '--require-status=')) {
        $value = trim(substr($arg, strlen('--require-status=')));
        if ($value !== '') {
            $options['require_status'] = array_values(array_filter(array_map(
                static fn (string $status): string => trim($status),
                explode(',', $value)
            ), static fn (string $status): bool => $status !== ''));
        }

        continue;
    }
}

/**
 * @param  array<string, mixed>  $options
 */
function resolveAbsolutePath(string $root, array $options, string $key, string $default): string
{
    $value = isset($options[$key]) && is_string($options[$key]) ? trim($options[$key]) : '';
    if ($value === '') {
        return $default;
    }

    if (preg_match('/^[a-zA-Z]:\\\\/', $value) === 1 || str_starts_with($value, '/')) {
        return $value;
    }

    return $root.DIRECTORY_SEPARATOR.str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $value);
}

/**
 * @return array<string, string>
 */
function parseWeek2CardStatuses(string $content): array
{
    $statuses = [];
    $lines = preg_split('/\R/', $content) ?: [];
    foreach ($lines as $line) {
        $trimmed = trim($line);
        if (! str_starts_with($trimmed, '| W2-')) {
            continue;
        }

        $parts = array_map(static fn (string $part): string => trim($part), explode('|', $trimmed));
        if (count($parts) < 3) {
            continue;
        }

        $cardId = $parts[1] ?? '';
        $status = $parts[count($parts) - 2] ?? '';
        if ($cardId === '' || $status === '') {
            continue;
        }

        $statuses[$cardId] = $status;
    }

    return $statuses;
}

function startsWithStatus(string $status, string $prefix): bool
{
    return str_starts_with(strtolower(trim($status)), strtolower($prefix));
}

/**
 * @return array{path: string|null, status: string|null}
 */
function findEvidenceStatus(string $evidenceDir, string $pattern): array
{
    $matches = glob($evidenceDir.DIRECTORY_SEPARATOR.$pattern);
    if (! is_array($matches) || $matches === []) {
        return [
            'path' => null,
            'status' => null,
        ];
    }

    sort($matches, SORT_STRING);
    $path = (string) end($matches);
    $content = @file_get_contents($path);
    if (! is_string($content)) {
        return [
            'path' => $path,
            'status' => null,
        ];
    }

    if (preg_match('/Status:\s*([^\r\n]+)/i', $content, $statusMatch) === 1) {
        return [
            'path' => $path,
            'status' => trim((string) ($statusMatch[1] ?? '')),
        ];
    }

    return [
        'path' => $path,
        'status' => null,
    ];
}

$root = dirname(__DIR__);
$defaultWeek2Cards = $root.DIRECTORY_SEPARATOR.'documents'.DIRECTORY_SEPARATOR.'06-phase-execution'.DIRECTORY_SEPARATOR.'WEEK2_UI_TASK_CARDS_2026-03-06.md';
$defaultEvidenceDir = $root.DIRECTORY_SEPARATOR.'documents'.DIRECTORY_SEPARATOR.'06-phase-execution';
$defaultOutput = $root.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'deployment-artifacts'.DIRECTORY_SEPARATOR.'week2_ui_readiness.json';

$week2CardsPath = resolveAbsolutePath($root, $options, 'week2_cards', $defaultWeek2Cards);
$evidenceDir = resolveAbsolutePath($root, $options, 'evidence_dir', $defaultEvidenceDir);
$outputPath = resolveAbsolutePath($root, $options, 'output', $defaultOutput);

if (! is_file($week2CardsPath)) {
    fwrite(STDERR, "Week 2 task card file not found: {$week2CardsPath}\n");
    exit(1);
}

$week2Content = file_get_contents($week2CardsPath);
if (! is_string($week2Content)) {
    fwrite(STDERR, "Unable to read Week 2 task card file: {$week2CardsPath}\n");
    exit(1);
}

$cardStatuses = parseWeek2CardStatuses($week2Content);
$expectedCards = array_map(
    static fn (int $number): string => 'W2-'.str_pad((string) $number, 2, '0', STR_PAD_LEFT),
    range(1, 10)
);
$missingCards = array_values(array_diff($expectedCards, array_keys($cardStatuses)));
$doneCards = array_values(array_filter($expectedCards, static fn (string $card): bool => isset($cardStatuses[$card]) && startsWithStatus($cardStatuses[$card], 'Done')));
$openCards = array_values(array_filter($expectedCards, static fn (string $card): bool => ! in_array($card, $doneCards, true)));

$evidencePatterns = [
    'W2-04' => 'W2-04_PATIENTS_KPI_VALIDATION_EVIDENCE_*.md',
    'W2-08' => 'W2-08_APPOINTMENTS_KPI_VALIDATION_EVIDENCE_*.md',
    'W2-09' => 'W2-09_CROSS_MODULE_I18N_LOCALE_EVIDENCE_*.md',
    'W2-10' => 'W2-10_WEEK2_RELEASE_READINESS_SIGNOFF_*.md',
];

$evidence = [];
$missingEvidence = [];
$openEvidence = [];
foreach ($evidencePatterns as $key => $pattern) {
    $result = findEvidenceStatus($evidenceDir, $pattern);
    $evidence[$key] = $result;

    if ($result['path'] === null) {
        $missingEvidence[] = $key;
        continue;
    }

    $status = is_string($result['status']) ? $result['status'] : '';
    if (! startsWithStatus($status, 'Done')) {
        $openEvidence[] = $key;
    }
}

$status = 'in_progress';
if ($missingCards !== []) {
    $status = 'not_ready_missing_task_cards';
} elseif ($missingEvidence !== []) {
    $status = 'not_ready_missing_evidence_artifacts';
} elseif ($openCards === [] && $openEvidence === []) {
    $status = 'ready';
} elseif (array_values(array_diff($openCards, ['W2-04', 'W2-08', 'W2-10'])) === []) {
    $status = 'in_progress_pending_kpi_signoff';
}

$artifact = [
    'generatedAt' => gmdate('c'),
    'status' => $status,
    'readiness' => [
        'eligibleForWeek3' => $status === 'ready',
        'openCards' => $openCards,
        'openEvidence' => $openEvidence,
    ],
    'sources' => [
        'week2CardsPath' => $week2CardsPath,
        'evidenceDir' => $evidenceDir,
    ],
    'cards' => [
        'expected' => $expectedCards,
        'found' => array_keys($cardStatuses),
        'missing' => $missingCards,
        'done' => $doneCards,
        'statuses' => $cardStatuses,
    ],
    'evidence' => [
        'files' => $evidence,
        'missing' => $missingEvidence,
    ],
];

$directory = dirname($outputPath);
if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
    fwrite(STDERR, "Unable to create artifact directory: {$directory}\n");
    exit(1);
}

$bytes = file_put_contents($outputPath, json_encode($artifact, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
if ($bytes === false) {
    fwrite(STDERR, "Unable to write readiness artifact: {$outputPath}\n");
    exit(1);
}

fwrite(STDOUT, "Week 2 UI readiness artifact written: {$outputPath}\n");
fwrite(STDOUT, "Readiness status: {$status}\n");

$requiredStatuses = array_values(array_unique((array) ($options['require_status'] ?? [])));
if ($requiredStatuses !== [] && ! in_array($status, $requiredStatuses, true)) {
    fwrite(STDERR, "Readiness artifact gate failure: status '{$status}' is not allowed by --require-status (allowed: ".implode(', ', $requiredStatuses).").\n");
    exit(3);
}

$strictFailureStatuses = array_values(array_unique((array) ($options['fail_on_status'] ?? [])));
if ($strictFailureStatuses !== [] && in_array($status, $strictFailureStatuses, true)) {
    fwrite(STDERR, "Readiness artifact gate failure: status '{$status}' is configured in --fail-on-status.\n");
    exit(2);
}

exit(0);
