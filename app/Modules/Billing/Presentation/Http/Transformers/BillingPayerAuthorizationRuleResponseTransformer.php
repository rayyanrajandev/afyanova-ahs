<?php

namespace App\Modules\Billing\Presentation\Http\Transformers;

class BillingPayerAuthorizationRuleResponseTransformer
{
    public static function transform(array $rule): array
    {
        return [
            'id' => $rule['id'] ?? null,
            'billingPayerContractId' => $rule['billing_payer_contract_id'] ?? null,
            'tenantId' => $rule['tenant_id'] ?? null,
            'facilityId' => $rule['facility_id'] ?? null,
            'billingServiceCatalogItemId' => $rule['billing_service_catalog_item_id'] ?? null,
            'ruleCode' => $rule['rule_code'] ?? null,
            'ruleName' => $rule['rule_name'] ?? null,
            'serviceCode' => $rule['service_code'] ?? null,
            'serviceType' => $rule['service_type'] ?? null,
            'department' => $rule['department'] ?? null,
            'diagnosisCode' => $rule['diagnosis_code'] ?? null,
            'priority' => $rule['priority'] ?? null,
            'minPatientAgeYears' => $rule['min_patient_age_years'] ?? null,
            'maxPatientAgeYears' => $rule['max_patient_age_years'] ?? null,
            'gender' => $rule['gender'] ?? null,
            'amountThreshold' => $rule['amount_threshold'] ?? null,
            'quantityLimit' => $rule['quantity_limit'] ?? null,
            'coverageDecision' => $rule['coverage_decision'] ?? null,
            'coveragePercentOverride' => $rule['coverage_percent_override'] ?? null,
            'copayType' => $rule['copay_type'] ?? null,
            'copayValue' => $rule['copay_value'] ?? null,
            'benefitLimitAmount' => $rule['benefit_limit_amount'] ?? null,
            'effectiveFrom' => $rule['effective_from'] ?? null,
            'effectiveTo' => $rule['effective_to'] ?? null,
            'requiresAuthorization' => $rule['requires_authorization'] ?? null,
            'autoApprove' => $rule['auto_approve'] ?? null,
            'authorizationValidityDays' => $rule['authorization_validity_days'] ?? null,
            'ruleNotes' => $rule['rule_notes'] ?? null,
            'ruleExpression' => $rule['rule_expression'] ?? null,
            'metadata' => $rule['metadata'] ?? null,
            'status' => $rule['status'] ?? null,
            'statusReason' => $rule['status_reason'] ?? null,
            'createdAt' => $rule['created_at'] ?? null,
            'updatedAt' => $rule['updated_at'] ?? null,
        ];
    }
}
