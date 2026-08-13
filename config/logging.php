<?php

use Monolog\Handler\NullHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\SyslogUdpHandler;
use Monolog\Processor\PsrLogMessageProcessor;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Log Channel
    |--------------------------------------------------------------------------
    |
    | This option defines the default log channel that is utilized to write
    | messages to your logs. The value provided here should match one of
    | the channels present in the list of "channels" configured below.
    |
    */

    'default' => env('LOG_CHANNEL', 'stack'),

    /*
    |--------------------------------------------------------------------------
    | Deprecations Log Channel
    |--------------------------------------------------------------------------
    |
    | This option controls the log channel that should be used to log warnings
    | regarding deprecated PHP and library features. This allows you to get
    | your application ready for upcoming major versions of dependencies.
    |
    */

    'deprecations' => [
        'channel' => env('LOG_DEPRECATIONS_CHANNEL', 'null'),
        'trace' => env('LOG_DEPRECATIONS_TRACE', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Log Channels
    |--------------------------------------------------------------------------
    |
    | Here you may configure the log channels for your application. Laravel
    | utilizes the Monolog PHP logging library, which includes a variety
    | of powerful log handlers and formatters that you're free to use.
    |
    | Available drivers: "single", "daily", "slack", "syslog",
    |                    "errorlog", "monolog", "custom", "stack"
    |
    */

    'channels' => [

        'stack' => [
            'driver' => 'stack',
            'channels' => explode(',', (string) env('LOG_STACK', 'single')),
            'ignore_exceptions' => false,
        ],

        'single' => [
            'driver' => 'single',
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'replace_placeholders' => true,
        ],

        'daily' => [
            'driver' => 'daily',
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'days' => env('LOG_DAILY_DAYS', 14),
            'replace_placeholders' => true,
        ],

        'reception_shadow_automation' => [
            'driver' => 'daily',
            'path' => storage_path('logs/reception_shadow_automation.log'),
            'level' => env('RECEPTION_SHADOW_AUTOMATION_LOG_LEVEL', 'info'),
            'days' => env('RECEPTION_SHADOW_AUTOMATION_LOG_DAYS', 30),
            'replace_placeholders' => true,
        ],

        'patient_flow_shadow_automation' => [
            'driver' => 'daily',
            'path' => storage_path('logs/patient_flow_shadow_automation.log'),
            'level' => env('PATIENT_FLOW_SHADOW_AUTOMATION_LOG_LEVEL', 'info'),
            'days' => env('PATIENT_FLOW_SHADOW_AUTOMATION_LOG_DAYS', 30),
            'replace_placeholders' => true,
        ],

        'platform_audit_retention_execution' => [
            'driver' => 'daily',
            'path' => storage_path('logs/platform_audit_retention_execution.log'),
            'level' => env('PLATFORM_AUDIT_RETENTION_EXECUTION_LOG_LEVEL', env('LOG_LEVEL', 'info')),
            'days' => env('PLATFORM_AUDIT_RETENTION_EXECUTION_LOG_DAYS', 30),
            'replace_placeholders' => true,
        ],

        'platform_audit_retention_metrics' => [
            'driver' => 'daily',
            'path' => storage_path('logs/platform_audit_retention_metrics.log'),
            'level' => env('PLATFORM_AUDIT_RETENTION_METRICS_LOG_LEVEL', 'info'),
            'days' => env('PLATFORM_AUDIT_RETENTION_METRICS_LOG_DAYS', 30),
            'replace_placeholders' => true,
        ],

        'platform_audit_retention_alerts' => [
            'driver' => 'daily',
            'path' => storage_path('logs/platform_audit_retention_alerts.log'),
            'level' => env('PLATFORM_AUDIT_RETENTION_ALERTS_LOG_LEVEL', 'warning'),
            'days' => env('PLATFORM_AUDIT_RETENTION_ALERTS_LOG_DAYS', 60),
            'replace_placeholders' => true,
        ],

        // Canonical Encounter State Machine — Shadow Mode diagnostics only.
        // Deliberately separate from the clinical audit-log tables/channels above:
        // this channel carries system-diagnostic evaluation output, not clinical
        // audit trail. See reports/encounter-state-machine-design/01-integration-and-migration-architecture.md §4.1.
        'canonical_encounter_shadow' => [
            'driver' => 'daily',
            'path' => storage_path('logs/canonical_encounter_shadow.log'),
            'level' => env('CANONICAL_ENCOUNTER_SHADOW_LOG_LEVEL', 'info'),
            'days' => env('CANONICAL_ENCOUNTER_SHADOW_LOG_DAYS', 14),
            'replace_placeholders' => true,
        ],

        'slack' => [
            'driver' => 'slack',
            'url' => env('LOG_SLACK_WEBHOOK_URL'),
            'username' => env('LOG_SLACK_USERNAME', 'Laravel Log'),
            'emoji' => env('LOG_SLACK_EMOJI', ':boom:'),
            'level' => env('LOG_LEVEL', 'critical'),
            'replace_placeholders' => true,
        ],

        'papertrail' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'debug'),
            'handler' => env('LOG_PAPERTRAIL_HANDLER', SyslogUdpHandler::class),
            'handler_with' => [
                'host' => env('PAPERTRAIL_URL'),
                'port' => env('PAPERTRAIL_PORT'),
                'connectionString' => 'tls://'.env('PAPERTRAIL_URL').':'.env('PAPERTRAIL_PORT'),
            ],
            'processors' => [PsrLogMessageProcessor::class],
        ],

        'stderr' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'debug'),
            'handler' => StreamHandler::class,
            'handler_with' => [
                'stream' => 'php://stderr',
            ],
            'formatter' => env('LOG_STDERR_FORMATTER'),
            'processors' => [PsrLogMessageProcessor::class],
        ],

        'syslog' => [
            'driver' => 'syslog',
            'level' => env('LOG_LEVEL', 'debug'),
            'facility' => env('LOG_SYSLOG_FACILITY', LOG_USER),
            'replace_placeholders' => true,
        ],

        'errorlog' => [
            'driver' => 'errorlog',
            'level' => env('LOG_LEVEL', 'debug'),
            'replace_placeholders' => true,
        ],

        'null' => [
            'driver' => 'monolog',
            'handler' => NullHandler::class,
        ],

        'tenant' => [
            'driver' => 'daily',
            'path' => storage_path('logs/tenant/{tenant_id}/laravel.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'days' => env('LOG_DAILY_DAYS', 14),
            'replace_placeholders' => true,
        ],

        'emergency' => [
            'path' => storage_path('logs/laravel.log'),
        ],

    ],

];
