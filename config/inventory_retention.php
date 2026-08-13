<?php

return [
    'inventory_access_audit_logs' => [
        'retention_days' => (int) env('INVENTORY_ACCESS_AUDIT_LOG_RETENTION_DAYS', 2190), // 6 years (HIPAA)
        'batch_size' => (int) env('INVENTORY_ACCESS_AUDIT_LOG_RETENTION_BATCH', 500),
        'schedule' => [
            'enabled' => (bool) env('INVENTORY_ACCESS_AUDIT_LOG_RETENTION_SCHEDULE_ENABLED', false),
            'cron' => (string) env('INVENTORY_ACCESS_AUDIT_LOG_RETENTION_SCHEDULE_CRON', '23 3 * * *'),
        ],
        'archive' => [
            'strategy' => env('INVENTORY_ACCESS_AUDIT_LOG_ARCHIVE_STRATEGY', 'mark'), // mark | delete
        ],
        'monitoring' => [
            'alerts_enabled' => (bool) env('INVENTORY_ACCESS_AUDIT_LOG_RETENTION_ALERTS_ENABLED', true),
            'remaining_candidates_warning_threshold' => (int) env('INVENTORY_ACCESS_AUDIT_LOG_RETENTION_REMAINING_CANDIDATES_WARN_THRESHOLD', 5000),
            'archived_rows_warning_threshold' => (int) env('INVENTORY_ACCESS_AUDIT_LOG_RETENTION_ARCHIVED_ROWS_WARN_THRESHOLD', 5000),
        ],
    ],
    'inventory_approval_decisions' => [
        'retention_days' => (int) env('INVENTORY_APPROVAL_DECISION_RETENTION_DAYS', 2190), // 6 years (HIPAA)
        'batch_size' => (int) env('INVENTORY_APPROVAL_DECISION_RETENTION_BATCH', 500),
        'schedule' => [
            'enabled' => (bool) env('INVENTORY_APPROVAL_DECISION_RETENTION_SCHEDULE_ENABLED', false),
            'cron' => (string) env('INVENTORY_APPROVAL_DECISION_RETENTION_SCHEDULE_CRON', '27 3 * * *'),
        ],
        'archive' => [
            'strategy' => env('INVENTORY_APPROVAL_DECISION_ARCHIVE_STRATEGY', 'mark'), // mark | delete
        ],
        'monitoring' => [
            'alerts_enabled' => (bool) env('INVENTORY_APPROVAL_DECISION_RETENTION_ALERTS_ENABLED', true),
            'remaining_candidates_warning_threshold' => (int) env('INVENTORY_APPROVAL_DECISION_RETENTION_REMAINING_CANDIDATES_WARN_THRESHOLD', 5000),
            'archived_rows_warning_threshold' => (int) env('INVENTORY_APPROVAL_DECISION_RETENTION_ARCHIVED_ROWS_WARN_THRESHOLD', 5000),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | PII (Personally Identifiable Information) Configuration
    |--------------------------------------------------------------------------
    |
    | Defines which fields in audit logs and business context should be
    | masked to prevent PII leakage. The sanitizer scans for patterns
    | like SSN, email, phone, MRN, and patient identifiers.
    |
    | sensitive_keys: Array of keys in business_context that should
    | always be masked regardless of content.
    |
    | additional_patterns: Custom regex patterns for PII detection.
    |
    */
    /*
    |--------------------------------------------------------------------------
    | Approval Timeout Configuration
    |--------------------------------------------------------------------------
    |
    | Defines the default timeout for approval workflows. When a workflow
    | instance exceeds its timeout, it is eligible for auto-rejection via
    | the inventory:auto-reject-expired-workflows command.
    |
    */
    'approval_timeout' => [
        'enabled' => (bool) env('INVENTORY_APPROVAL_TIMEOUT_ENABLED', true),
        'default_hours' => (int) env('INVENTORY_APPROVAL_TIMEOUT_DEFAULT_HOURS', 72), // 3 days
        'batch_size' => (int) env('INVENTORY_APPROVAL_TIMEOUT_BATCH_SIZE', 100),
    ],

    /*
    |--------------------------------------------------------------------------
    | Segregation of Duties (SOD) Alerting Configuration
    |--------------------------------------------------------------------------
    |
    | Configure email and webhook notifications for SOD violations detected
    | during the approval workflow. Supports compliance officer notification
    | via email and optional webhook integration for SIEM/audit systems.
    |
    */
    'sod_alerting' => [
        'enabled' => (bool) env('INVENTORY_SOD_ALERTING_ENABLED', true),
        'webhook_enabled' => (bool) env('INVENTORY_SOD_ALERTING_WEBHOOK_ENABLED', false),
        'webhook_url' => env('INVENTORY_SOD_ALERTING_WEBHOOK_URL'),
        'notification_emails' => explode(',', (string) env('INVENTORY_SOD_ALERTING_NOTIFICATION_EMAILS', '')),
    ],

    'pii' => [
        'sensitive_keys' => [
            'comments',           // Free-text comments (highest PII risk)
            'deny_reason',        // Denial reason may contain identifiable info
            'approver_notes',     // Approver free-text notes
        ],
        'additional_patterns' => [
            // Override via env: INVENTORY_PII_ADDITIONAL_PATTERNS
        ],
    ],
];
