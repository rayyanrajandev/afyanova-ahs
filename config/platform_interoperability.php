<?php

return [
    'default_version' => 'v1',

    'adapter_envelopes' => [
        'v1' => [
            'eventTypePattern' => 'resource.event',
            'envelope' => [
                'version' => 'v1',
                'eventType' => 'resource.event',
                'eventId' => 'uuid',
                'occurredAt' => 'ISO-8601',
                'tenantId' => 'uuid-or-null',
                'facilityId' => 'uuid-or-null',
                'sourceSystem' => 'string',
                'payload' => [],
            ],
            'priorityFlows' => [
                [
                    'key' => 'patient.demographics.identifiers',
                    'label' => 'Patient demographics and identifiers',
                ],
                [
                    'key' => 'appointment.encounter.scheduling',
                    'label' => 'Appointment and encounter scheduling events',
                ],
                [
                    'key' => 'orders.results.laboratory_radiology',
                    'label' => 'Orders and results (laboratory/radiology/clinical procedure)',
                ],
                [
                    'key' => 'billing.claims.status',
                    'label' => 'Billing invoice and claims status events',
                ],
            ],
            'nonFunctionalControls' => [
                'Idempotent replay safety for inbound messages.',
                'Dead-letter handling for failed transforms.',
                'Structured error logging with correlation IDs.',
                'Throughput and retry observability.',
                'Partner-specific credential and key-rotation policy.',
            ],
        ],
    ],

    'signoff' => [
        'operational_checks' => [
            [
                'key' => 'payload_schema_validation',
                'label' => 'Payload schema validation',
                'owner' => 'Integration Lead',
                'status' => 'contract_baseline_ready',
                'evidence' => [
                    'documents/01-contracts/platform/PLATFORM_INTEROPERABILITY_ADAPTER_READINESS_V1_CONTRACT.md',
                ],
            ],
            [
                'key' => 'error_handling_retry_model',
                'label' => 'Error handling/retry model',
                'owner' => 'Platform Lead',
                'status' => 'pending_execution_detail',
                'evidence' => [
                    'documents/99-internal/approvals/2026-03/2026-03-03_G5_interoperability-readiness_draft.md',
                    'documents/99-internal/approvals/2026-03/evidence/G5_PARTNER_FLOW_VALIDATION_EVIDENCE_TEMPLATE.md',
                ],
            ],
            [
                'key' => 'security_controls',
                'label' => 'Security controls',
                'owner' => 'Security Lead',
                'status' => 'internally_mapped',
                'evidence' => [
                    'documents/02-architecture/PLATFORM_CROSS_TENANT_ADMIN_AUDIT_LOG_POLICY_V1.md',
                    'documents/03-operations/PLATFORM_CROSS_TENANT_ADMIN_AUDIT_LOG_OPERATIONS_RUNBOOK_V1.md',
                ],
            ],
            [
                'key' => 'monitoring_and_alerting',
                'label' => 'Monitoring and alerting',
                'owner' => 'Ops Lead',
                'status' => 'pending_execution_detail',
                'evidence' => [
                    'documents/99-internal/approvals/2026-03/2026-03-03_G5_interoperability-readiness_draft.md',
                    'documents/99-internal/approvals/2026-03/evidence/G5_PARTNER_FLOW_VALIDATION_EVIDENCE_TEMPLATE.md',
                ],
            ],
            [
                'key' => 'partner_onboarding_sop',
                'label' => 'Partner onboarding SOP',
                'owner' => 'Product + Integration',
                'status' => 'pending_execution_detail',
                'evidence' => [
                    'documents/01-contracts/platform/PLATFORM_INTEROPERABILITY_ADAPTER_READINESS_V1_CONTRACT.md',
                    'documents/99-internal/approvals/2026-03/evidence/G5_PARTNER_FLOW_VALIDATION_EVIDENCE_TEMPLATE.md',
                ],
            ],
        ],
    ],
];
