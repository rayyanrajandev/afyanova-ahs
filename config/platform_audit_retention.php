<?php

$allowedEnvironments = array_values(array_filter(array_map(
    static fn ($value): string => trim((string) $value),
    explode(',', (string) env('PLATFORM_CROSS_TENANT_AUDIT_LOG_RETENTION_SCHEDULE_ALLOWED_ENVS', 'production'))
), static fn (string $value): bool => $value !== ''));

$auditExportJobAllowedEnvironments = array_values(array_filter(array_map(
    static fn ($value): string => trim((string) $value),
    explode(',', (string) env('AUDIT_EXPORT_JOB_RETENTION_SCHEDULE_ALLOWED_ENVS', 'production'))
), static fn (string $value): bool => $value !== ''));

$auditExportRetryResumeTelemetryAllowedEnvironments = array_values(array_filter(array_map(
    static fn ($value): string => trim((string) $value),
    explode(',', (string) env('AUDIT_EXPORT_RETRY_RESUME_TELEMETRY_RETENTION_SCHEDULE_ALLOWED_ENVS', 'production'))
), static fn (string $value): bool => $value !== ''));

return [
    'cross_tenant_admin_audit_logs' => [
        'retention_days' => (int) env('PLATFORM_CROSS_TENANT_AUDIT_LOG_RETENTION_DAYS', 400),
        'holds' => [
            'governance' => [
                'enforce_two_person_control' => (bool) env('PLATFORM_CROSS_TENANT_AUDIT_LOG_HOLDS_ENFORCE_TWO_PERSON_CONTROL', false),
            ],
        ],
        'purge' => [
            'batch_size' => (int) env('PLATFORM_CROSS_TENANT_AUDIT_LOG_RETENTION_BATCH', 500),
            'schedule' => [
                'enabled' => (bool) env('PLATFORM_CROSS_TENANT_AUDIT_LOG_RETENTION_SCHEDULE_ENABLED', false),
                'cron' => (string) env('PLATFORM_CROSS_TENANT_AUDIT_LOG_RETENTION_SCHEDULE_CRON', '17 2 * * *'),
                'allowed_environments' => $allowedEnvironments,
                'readiness' => [
                    'require_two_person_control' => (bool) env('PLATFORM_CROSS_TENANT_AUDIT_LOG_RETENTION_REQUIRE_TWO_PERSON_CONTROL_FOR_SCHEDULED_PURGE', true),
                    'two_person_control_waiver' => [
                        'enabled' => (bool) env('PLATFORM_CROSS_TENANT_AUDIT_LOG_RETENTION_SCHEDULE_TWO_PERSON_CONTROL_WAIVER_ENABLED', false),
                        'reference' => env('PLATFORM_CROSS_TENANT_AUDIT_LOG_RETENTION_SCHEDULE_TWO_PERSON_CONTROL_WAIVER_REFERENCE'),
                    ],
                ],
            ],
            'monitoring' => [
                'alerts_enabled' => (bool) env('PLATFORM_CROSS_TENANT_AUDIT_LOG_RETENTION_ALERTS_ENABLED', true),
                'alert_on_environment_guard_skip' => (bool) env('PLATFORM_CROSS_TENANT_AUDIT_LOG_RETENTION_ALERT_ON_ENV_GUARD_SKIP', false),
                'remaining_candidates_warning_threshold' => (int) env('PLATFORM_CROSS_TENANT_AUDIT_LOG_RETENTION_REMAINING_CANDIDATES_WARN_THRESHOLD', 5000),
                'deleted_rows_warning_threshold' => (int) env('PLATFORM_CROSS_TENANT_AUDIT_LOG_RETENTION_DELETED_ROWS_WARN_THRESHOLD', 5000),
                'log_channels' => [
                    'execution' => env('PLATFORM_CROSS_TENANT_AUDIT_LOG_RETENTION_EXECUTION_LOG_CHANNEL'),
                    'metrics' => env('PLATFORM_CROSS_TENANT_AUDIT_LOG_RETENTION_METRICS_LOG_CHANNEL'),
                    'alerts' => env('PLATFORM_CROSS_TENANT_AUDIT_LOG_RETENTION_ALERTS_LOG_CHANNEL'),
                ],
            ],
        ],
    ],
    'audit_export_jobs' => [
        'retention_days' => (int) env('AUDIT_EXPORT_JOB_RETENTION_DAYS', 30),
        'batch_size' => (int) env('AUDIT_EXPORT_JOB_RETENTION_BATCH', 500),
        'file_directory' => (string) env('AUDIT_EXPORT_JOB_FILE_DIRECTORY', 'audit-exports'),
        'schedule' => [
            'enabled' => (bool) env('AUDIT_EXPORT_JOB_RETENTION_SCHEDULE_ENABLED', false),
            'cron' => (string) env('AUDIT_EXPORT_JOB_RETENTION_SCHEDULE_CRON', '41 2 * * *'),
            'allowed_environments' => $auditExportJobAllowedEnvironments,
        ],
    ],
    'audit_export_retry_resume_telemetry' => [
        'retention_days' => (int) env('AUDIT_EXPORT_RETRY_RESUME_TELEMETRY_RETENTION_DAYS', 60),
        'batch_size' => (int) env('AUDIT_EXPORT_RETRY_RESUME_TELEMETRY_RETENTION_BATCH', 1000),
        'schedule' => [
            'enabled' => (bool) env('AUDIT_EXPORT_RETRY_RESUME_TELEMETRY_RETENTION_SCHEDULE_ENABLED', false),
            'cron' => (string) env('AUDIT_EXPORT_RETRY_RESUME_TELEMETRY_RETENTION_SCHEDULE_CRON', '53 2 * * *'),
            'allowed_environments' => $auditExportRetryResumeTelemetryAllowedEnvironments,
        ],
        'observability' => [
            'cleanup_last_report_path' => (string) env(
                'AUDIT_EXPORT_RETRY_RESUME_TELEMETRY_CLEANUP_LAST_REPORT_PATH',
                'platform-audit/retry-resume-telemetry-cleanup-last-report.json'
            ),
            'cleanup_stale_after_hours' => (int) env(
                'AUDIT_EXPORT_RETRY_RESUME_TELEMETRY_CLEANUP_STALE_AFTER_HOURS',
                30
            ),
        ],
    ],
];
