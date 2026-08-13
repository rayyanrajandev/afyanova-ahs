<?php

declare(strict_types=1);

/**
 * Export Phase 5 gate-readiness JSON output to a file.
 *
 * Default output path:
 * storage/app/deployment-artifacts/phase5_gate_readiness.json
 *
 * Exit code follows the command by default and can be gated with:
 * --require-status=<csv> or --fail-on-status=<csv>.
 */

$args = array_slice($_SERVER['argv'] ?? [], 1);

$options = [
    'gate' => null,
    'output' => null,
    'fail_on_status' => [],
    'require_status' => [],
];

foreach ($args as $arg) {
    if ($arg === '--help' || $arg === '-h') {
        fwrite(STDOUT, "Usage: php scripts/export-phase5-gate-readiness-artifact.php [--gate=G1] [--output=path] [--fail-on-status=status1,status2] [--require-status=status1,status2]\n");
        exit(0);
    }

    if (str_starts_with($arg, '--gate=')) {
        $value = trim(substr($arg, strlen('--gate=')));
        if ($value !== '') {
            $options['gate'] = strtoupper($value);
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

$root = dirname(__DIR__);
$gate = $options['gate'];
$gate = is_string($gate) && trim($gate) !== '' ? strtoupper(trim($gate)) : null;

$defaultFileName = $gate !== null
    ? 'phase5_gate_readiness_'.$gate.'.json'
    : 'phase5_gate_readiness.json';
$defaultOutput = $root.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'deployment-artifacts'.DIRECTORY_SEPARATOR.$defaultFileName;
$outputPath = (string) ($options['output'] ?? $defaultOutput);

$commandParts = [
    escapeshellarg(PHP_BINARY),
    'artisan',
    'platform:phase5:gate-readiness-check',
];

if ($gate !== null) {
    $commandParts[] = '--gate='.escapeshellarg($gate);
}

$commandParts[] = '--json';
$command = implode(' ', $commandParts);

$cwd = getcwd();
if ($cwd === false || realpath($cwd) !== realpath($root)) {
    chdir($root);
}

$outputLines = [];
$exitCode = 1;
exec($command.' 2>&1', $outputLines, $exitCode);
$rawOutput = trim(implode(PHP_EOL, $outputLines));

if ($rawOutput === '') {
    fwrite(STDERR, "Readiness command produced no output.\n");
    exit(1);
}

$decoded = json_decode($rawOutput, true);
if (! is_array($decoded)) {
    fwrite(STDERR, "Readiness command did not return valid JSON. Raw output follows:\n".$rawOutput."\n");
    exit(1);
}

$directory = dirname($outputPath);
if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
    fwrite(STDERR, "Unable to create artifact directory: {$directory}\n");
    exit(1);
}

$bytes = file_put_contents($outputPath, json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
if ($bytes === false) {
    fwrite(STDERR, "Unable to write readiness artifact: {$outputPath}\n");
    exit(1);
}

fwrite(STDOUT, "Phase 5 gate-readiness artifact written: {$outputPath}\n");
fwrite(STDOUT, "Readiness status: ".((string) ($decoded['status'] ?? 'unknown'))."\n");

$status = (string) ($decoded['status'] ?? 'unknown');
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

exit($exitCode);
