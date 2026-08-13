<?php

namespace App\Modules\ClaimsInsurance\Presentation\Http\Transformers;

class ClaimsInsuranceCaseResponseTransformer
{
    public static function transform(array $case): array
    {
        return [
            'id' => $case['id'] ?? null,
            'claimNumber' => $case['claim_number'] ?? null,
            'invoiceId' => $case['invoice_id'] ?? null,
            'patientId' => $case['patient_id'] ?? null,
            'patientInsuranceRecordId' => $case['patient_insurance_record_id'] ?? null,
            'admissionId' => $case['admission_id'] ?? null,
            'appointmentId' => $case['appointment_id'] ?? null,
            'payerType' => $case['payer_type'] ?? null,
            'payerName' => $case['payer_name'] ?? null,
            'payerPlanName' => $case['payer_plan_name'] ?? null,
            'payerReference' => $case['payer_reference'] ?? null,
            'memberId' => $case['member_id'] ?? null,
            'policyNumber' => $case['policy_number'] ?? null,
            'cardNumber' => $case['card_number'] ?? null,
            'verificationReference' => $case['verification_reference'] ?? null,
            'claimReadiness' => $case['claim_readiness'] ?? null,
            'claimAmount' => $case['claim_amount'] ?? null,
            'currencyCode' => $case['currency_code'] ?? null,
            'submittedAt' => $case['submitted_at'] ?? null,
            'adjudicatedAt' => $case['adjudicated_at'] ?? null,
            'approvedAmount' => $case['approved_amount'] ?? null,
            'rejectedAmount' => $case['rejected_amount'] ?? null,
            'settledAmount' => $case['settled_amount'] ?? null,
            'reconciliationShortfallAmount' => $case['reconciliation_shortfall_amount'] ?? null,
            'settledAt' => $case['settled_at'] ?? null,
            'settlementReference' => $case['settlement_reference'] ?? null,
            'decisionReason' => $case['decision_reason'] ?? null,
            'notes' => $case['notes'] ?? null,
            'status' => $case['status'] ?? null,
            'reconciliationStatus' => $case['reconciliation_status'] ?? null,
            'reconciliationExceptionStatus' => $case['reconciliation_exception_status'] ?? null,
            'reconciliationFollowUpStatus' => $case['reconciliation_follow_up_status'] ?? null,
            'reconciliationFollowUpDueAt' => $case['reconciliation_follow_up_due_at'] ?? null,
            'reconciliationFollowUpNote' => $case['reconciliation_follow_up_note'] ?? null,
            'reconciliationFollowUpUpdatedAt' => $case['reconciliation_follow_up_updated_at'] ?? null,
            'reconciliationFollowUpUpdatedByUserId' => $case['reconciliation_follow_up_updated_by_user_id'] ?? null,
            'reconciliationNotes' => $case['reconciliation_notes'] ?? null,
            'statusReason' => $case['status_reason'] ?? null,
            'createdAt' => $case['created_at'] ?? null,
            'updatedAt' => $case['updated_at'] ?? null,
        ];
    }
}
