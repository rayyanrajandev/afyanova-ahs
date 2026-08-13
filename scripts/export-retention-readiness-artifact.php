<?php

declare(strict_types=1);

/**
 * Export the platform audit retention readiness check JSON output to a file.
 *
 * Default behavior targets the production environment posture and writes to:
 * storage/app/deployment-artifacts/platform_cross_tenant_admin_audit_log_retention_readiness_<env>.json
 *
 * Exit code matches the underlying readiness command by default, but can be
 * made stricter with --fail-on-status=<csv> or --require-status=<csv> while
 * still preserving the artifact.
 */

$args = array_slice($_SERVER['argv'] ?? [], 1);

$options = [
    'environment' => 'production',
    'output' => null,
    'fail_on_status' => [],
    'require_status' => [],
];

foreach ($args as $arg) {
    if ($arg === '--help' || $arg === '-h') {
        fwrite(STDOUT, "Usage: php scripts/export-retention-readiness-artifact.php [--environment=production] [--output=path] [--fail-on-status=status1,status2] [--require-status=status1,status2]\n");
        exit(0);
    }

    if (str_starts_with($arg, '--environment=')) {
        $value = trim(substr($arg, strlen('--environment=')));
        if ($value !== '') {
            $options['environment'] = $value;
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
$environment = (string) $options['environment'];
$defaultOutput = $root.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'deployment-artifacts'.DIRECTORY_SEPARATOR
    .'platform_cross_tenant_admin_audit_log_retention_readiness_'.$environment.'.json';
$outputPath = (string) ($options['output'] ?? $defaultOutput);

$command = implode(' ', [
    escapeshellarg(PHP_BINARY),
    'artisan',
    'platform:cross-tenant-audit-logs:retention-readiness-check',
    '--environment='.escapeshellarg($environment),
    '--json',
]);

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

fwrite(STDOUT, "Retention readiness artifact written: {$outputPath}\n");
fwrite(STDOUT, "Readiness status: ".((string) ($decoded['status'] ?? 'unknown'))."\n");

$status = (string) ($decoded['status'] ?? 'unknown');
$requiredStatuses = (array) ($options['require_status'] ?? []);
$requiredStatuses = array_values(array_unique($requiredStatuses));
if ($requiredStatuses !== [] && ! in_array($status, $requiredStatuses, true)) {
    fwrite(STDERR, "Readiness artifact gate failure: status '{$status}' is not allowed by --require-status (allowed: ".implode(', ', $requiredStatuses).").\n");
    exit(3);
}

$strictFailureStatuses = (array) ($options['fail_on_status'] ?? []);
$strictFailureStatuses = array_values(array_unique($strictFailureStatuses));
if ($strictFailureStatuses !== [] && in_array($status, $strictFailureStatuses, true)) {
    fwrite(STDERR, "Readiness artifact gate failure: status '{$status}' is configured in --fail-on-status.\n");
    exit(2);
}

exit($exitCode);
