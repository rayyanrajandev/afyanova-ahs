<?php

declare(strict_types=1);

/**
 * Export Phase 5 documentation-readiness JSON output to a file.
 *
 * Default output path:
 * storage/app/deployment-artifacts/phase5_documentation_readiness.json
 *
 * Exit code follows the command by default and can be gated with:
 * --require-status=<csv> or --fail-on-status=<csv>.
 */

$args = array_slice($_SERVER['argv'] ?? [], 1);

$options = [
    'module' => null,
    'output' => null,
    'fail_on_status' => [],
    'require_status' => [],
];

foreach ($args as $arg) {
    if ($arg === '--help' || $arg === '-h') {
        fwrite(STDOUT, "Usage: php scripts/export-phase5-documentation-readiness-artifact.php [--module=approval_tracker|legal_citation_pack|legal_clause_mapping_worksheet|data_residency_decision_record|data_residency_approval_worksheet|phase5_owner_action_board|statutory_reporting_approval_worksheet|phase5_gate_packet_index|phase5_signature_capture_log|phase5_approvals_archive_readme|phase5_evidence_capture_pack|phase5_signer_brief] [--output=path] [--fail-on-status=status1,status2] [--require-status=status1,status2]\n");
        exit(0);
    }

    if (str_starts_with($arg, '--module=')) {
        $value = trim(substr($arg, strlen('--module=')));
        if ($value !== '') {
            $options['module'] = strtolower($value);
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
$module = $options['module'];
$module = is_string($module) && trim($module) !== '' ? strtolower(trim($module)) : null;

$defaultFileName = $module !== null
    ? 'phase5_documentation_readiness_'.$module.'.json'
    : 'phase5_documentation_readiness.json';
$defaultOutput = $root.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'deployment-artifacts'.DIRECTORY_SEPARATOR.$defaultFileName;
$outputPath = (string) ($options['output'] ?? $defaultOutput);

$commandParts = [
    escapeshellarg(PHP_BINARY),
    'artisan',
    'platform:phase5:documentation-readiness-check',
];

if ($module !== null) {
    $commandParts[] = '--module='.escapeshellarg($module);
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

fwrite(STDOUT, "Phase 5 documentation-readiness artifact written: {$outputPath}\n");
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
