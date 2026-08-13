<?php

namespace App\Modules\Billing\Presentation\Http\Transformers;

class BillingPayerContractResponseTransformer
{
    public static function transform(array $contract): array
    {
        return [
            'id' => $contract['id'] ?? null,
            'tenantId' => $contract['tenant_id'] ?? null,
            'facilityId' => $contract['facility_id'] ?? null,
            'contractCode' => $contract['contract_code'] ?? null,
            'contractName' => $contract['contract_name'] ?? null,
            'payerType' => $contract['payer_type'] ?? null,
            'payerName' => $contract['payer_name'] ?? null,
            'payerPlanCode' => $contract['payer_plan_code'] ?? null,
            'payerPlanName' => $contract['payer_plan_name'] ?? null,
            'currencyCode' => $contract['currency_code'] ?? null,
            'defaultCoveragePercent' => $contract['default_coverage_percent'] ?? null,
            'defaultCopayType' => $contract['default_copay_type'] ?? null,
            'defaultCopayValue' => $contract['default_copay_value'] ?? null,
            'requiresPreAuthorization' => $contract['requires_pre_authorization'] ?? null,
            'claimSubmissionDeadlineDays' => $contract['claim_submission_deadline_days'] ?? null,
            'settlementCycleDays' => $contract['settlement_cycle_days'] ?? null,
            'effectiveFrom' => $contract['effective_from'] ?? null,
            'effectiveTo' => $contract['effective_to'] ?? null,
            'termsAndNotes' => $contract['terms_and_notes'] ?? null,
            'metadata' => $contract['metadata'] ?? null,
            'status' => $contract['status'] ?? null,
            'statusReason' => $contract['status_reason'] ?? null,
            'createdAt' => $contract['created_at'] ?? null,
            'updatedAt' => $contract['updated_at'] ?? null,
        ];
    }
}
