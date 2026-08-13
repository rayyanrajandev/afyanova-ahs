<?php

return [
    'modules' => [
        [
            'key' => 'approval_tracker',
            'label' => 'Tanzania Phase 5 Approval Tracker',
            'requiredFiles' => [
                'documents/04-compliance/TANZANIA_PHASE5_APPROVAL_TRACKER_V1.md',
                'documents/04-compliance/TANZANIA_COMPLIANCE_EVIDENCE_MATRIX_V1.md',
                'documents/99-internal/approvals/2026-03/2026-03-05_G1_legal-citation-approval_signed.md',
                'documents/99-internal/approvals/2026-03/2026-03-05_G2_data-residency-approval_signed.md',
                'documents/99-internal/approvals/2026-03/2026-03-05_G3_phi-access-review_signed.md',
                'documents/99-internal/approvals/2026-03/2026-03-05_G4_statutory-reporting-approval_signed.md',
                'documents/99-internal/approvals/2026-03/2026-03-05_G5_interoperability-readiness_signed.md',
                'documents/99-internal/approvals/2026-03/2026-03-05_G6_tanzania-go-live-control-signoff_signed.md',
            ],
        ],
        [
            'key' => 'legal_citation_pack',
            'label' => 'Tanzania Legal and Regulatory Citation Pack',
            'requiredFiles' => [
                'documents/04-compliance/TANZANIA_LEGAL_AND_REGULATORY_CITATION_PACK_DRAFT_V1.md',
                'documents/04-compliance/TANZANIA_LEGAL_CLAUSE_MAPPING_WORKSHEET_V1.md',
                'documents/99-internal/approvals/2026-03/2026-03-05_G1_legal-citation-approval_signed.md',
            ],
            'citationPackPath' => 'documents/04-compliance/TANZANIA_LEGAL_AND_REGULATORY_CITATION_PACK_DRAFT_V1.md',
        ],
        [
            'key' => 'legal_clause_mapping_worksheet',
            'label' => 'Tanzania Legal Clause Mapping Worksheet',
            'requiredFiles' => [
                'documents/04-compliance/TANZANIA_LEGAL_CLAUSE_MAPPING_WORKSHEET_V1.md',
                'documents/04-compliance/TANZANIA_LEGAL_AND_REGULATORY_CITATION_PACK_DRAFT_V1.md',
                'documents/99-internal/approvals/2026-03/2026-03-05_G1_legal-citation-approval_signed.md',
            ],
        ],
        [
            'key' => 'data_residency_decision_record',
            'label' => 'Tanzania Data Residency Decision Record',
            'requiredFiles' => [
                'documents/02-architecture/TANZANIA_DATA_RESIDENCY_DECISION_RECORD_V1.md',
                'documents/02-architecture/TANZANIA_DATA_RESIDENCY_APPROVAL_WORKSHEET_V1.md',
                'documents/99-internal/approvals/2026-03/2026-03-05_G2_data-residency-approval_signed.md',
            ],
        ],
        [
            'key' => 'data_residency_approval_worksheet',
            'label' => 'Tanzania Data Residency Approval Worksheet',
            'requiredFiles' => [
                'documents/02-architecture/TANZANIA_DATA_RESIDENCY_APPROVAL_WORKSHEET_V1.md',
                'documents/02-architecture/TANZANIA_DATA_RESIDENCY_DECISION_RECORD_V1.md',
                'documents/99-internal/approvals/2026-03/2026-03-05_G2_data-residency-approval_signed.md',
            ],
        ],
        [
            'key' => 'phase5_owner_action_board',
            'label' => 'Tanzania Phase 5 Owner Action Board',
            'requiredFiles' => [
                'documents/04-compliance/TANZANIA_PHASE5_OWNER_ACTION_BOARD_2026-03.md',
                'documents/99-internal/approvals/2026-03/GATE_PACKET_INDEX_2026-03.md',
                'documents/99-internal/approvals/2026-03/SIGNATURE_CAPTURE_LOG_2026-03.md',
            ],
        ],
        [
            'key' => 'statutory_reporting_approval_worksheet',
            'label' => 'Tanzania Statutory Reporting Approval Worksheet',
            'requiredFiles' => [
                'documents/03-operations/TANZANIA_STATUTORY_REPORTING_APPROVAL_WORKSHEET_V1.md',
                'documents/03-operations/TANZANIA_STATUTORY_REPORTING_WORKFLOW_PACK_V1.md',
                'documents/99-internal/approvals/2026-03/2026-03-05_G4_statutory-reporting-approval_signed.md',
            ],
        ],
        [
            'key' => 'phase5_gate_packet_index',
            'label' => 'Tanzania Phase 5 Gate Packet Index',
            'requiredFiles' => [
                'documents/99-internal/approvals/2026-03/GATE_PACKET_INDEX_2026-03.md',
                'documents/99-internal/approvals/2026-03/SIGNATURE_CAPTURE_LOG_2026-03.md',
                'documents/99-internal/approvals/2026-03/2026-03-05_G1_legal-citation-approval_signed.md',
                'documents/99-internal/approvals/2026-03/2026-03-05_G2_data-residency-approval_signed.md',
                'documents/99-internal/approvals/2026-03/2026-03-05_G3_phi-access-review_signed.md',
                'documents/99-internal/approvals/2026-03/2026-03-05_G4_statutory-reporting-approval_signed.md',
                'documents/99-internal/approvals/2026-03/2026-03-05_G5_interoperability-readiness_signed.md',
                'documents/99-internal/approvals/2026-03/2026-03-05_G6_tanzania-go-live-control-signoff_signed.md',
            ],
        ],
        [
            'key' => 'phase5_signature_capture_log',
            'label' => 'Tanzania Phase 5 Signature Capture Log',
            'requiredFiles' => [
                'documents/99-internal/approvals/2026-03/SIGNATURE_CAPTURE_LOG_2026-03.md',
                'documents/99-internal/approvals/2026-03/GATE_PACKET_INDEX_2026-03.md',
                'documents/99-internal/approvals/2026-03/2026-03-05_G1_legal-citation-approval_signed.md',
                'documents/99-internal/approvals/2026-03/2026-03-05_G2_data-residency-approval_signed.md',
                'documents/99-internal/approvals/2026-03/2026-03-05_G3_phi-access-review_signed.md',
                'documents/99-internal/approvals/2026-03/2026-03-05_G4_statutory-reporting-approval_signed.md',
                'documents/99-internal/approvals/2026-03/2026-03-05_G5_interoperability-readiness_signed.md',
                'documents/99-internal/approvals/2026-03/2026-03-05_G6_tanzania-go-live-control-signoff_signed.md',
            ],
        ],
        [
            'key' => 'phase5_approvals_archive_readme',
            'label' => 'Tanzania Phase 5 Approvals Archive README',
            'requiredFiles' => [
                'documents/99-internal/approvals/2026-03/README.md',
                'documents/99-internal/approvals/2026-03/GATE_PACKET_INDEX_2026-03.md',
                'documents/99-internal/approvals/2026-03/SIGNATURE_CAPTURE_LOG_2026-03.md',
                'documents/99-internal/approvals/2026-03/2026-03-05_G1_legal-citation-approval_signed.md',
                'documents/99-internal/approvals/2026-03/2026-03-05_G2_data-residency-approval_signed.md',
                'documents/99-internal/approvals/2026-03/2026-03-05_G3_phi-access-review_signed.md',
                'documents/99-internal/approvals/2026-03/2026-03-05_G4_statutory-reporting-approval_signed.md',
                'documents/99-internal/approvals/2026-03/2026-03-05_G5_interoperability-readiness_signed.md',
                'documents/99-internal/approvals/2026-03/2026-03-05_G6_tanzania-go-live-control-signoff_signed.md',
            ],
        ],
        [
            'key' => 'phase5_evidence_capture_pack',
            'label' => 'Tanzania Phase 5 Evidence Capture Pack',
            'requiredFiles' => [
                'documents/99-internal/approvals/2026-03/evidence/README.md',
                'documents/99-internal/approvals/2026-03/evidence/G2_HOSTING_REGION_ATTESTATION_TEMPLATE.md',
                'documents/99-internal/approvals/2026-03/evidence/G2_BACKUP_DR_ATTESTATION_TEMPLATE.md',
                'documents/99-internal/approvals/2026-03/evidence/G4_STATUTORY_REPORT_DRY_RUN_EVIDENCE_TEMPLATE.md',
                'documents/99-internal/approvals/2026-03/evidence/G5_PARTNER_FLOW_VALIDATION_EVIDENCE_TEMPLATE.md',
                'documents/99-internal/approvals/2026-03/evidence/2026-03-06_G2_hosting-region-attestation.md',
                'documents/99-internal/approvals/2026-03/evidence/2026-03-06_G2_backup-dr-attestation.md',
                'documents/99-internal/approvals/2026-03/evidence/2026-03-08_G4_statutory-report-dry-run.md',
                'documents/99-internal/approvals/2026-03/evidence/2026-03-09_G5_partner-flow-validation.md',
            ],
        ],
        [
            'key' => 'phase5_signer_brief',
            'label' => 'Tanzania Phase 5 Signer Brief',
            'requiredFiles' => [
                'documents/99-internal/approvals/2026-03/SIGNER_BRIEF_2026-03.md',
                'documents/99-internal/approvals/2026-03/GATE_PACKET_INDEX_2026-03.md',
                'documents/99-internal/approvals/2026-03/SIGNATURE_CAPTURE_LOG_2026-03.md',
                'documents/99-internal/approvals/2026-03/README.md',
            ],
        ],
    ],
];
