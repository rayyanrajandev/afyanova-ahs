<?php

namespace App\Modules\Billing\Presentation\Http\Transformers;

class PatientInsuranceRecordResponseTransformer
{
    public static function transform(array $record): array
    {
        return [
            'id' => $record['id'] ?? null,
            'tenantId' => $record['tenant_id'] ?? null,
            'facilityId' => $record['facility_id'] ?? null,
            'patientId' => $record['patient_id'] ?? null,
            'billingPayerContractId' => $record['billing_payer_contract_id'] ?? null,
            'insuranceType' => $record['insurance_type'] ?? null,
            'insuranceProvider' => $record['insurance_provider'] ?? null,
            'providerCode' => $record['provider_code'] ?? null,
            'planName' => $record['plan_name'] ?? null,
            'policyNumber' => $record['policy_number'] ?? null,
            'memberId' => $record['member_id'] ?? null,
            'principalMemberName' => $record['principal_member_name'] ?? null,
            'relationshipToPrincipal' => $record['relationship_to_principal'] ?? null,
            'cardNumber' => $record['card_number'] ?? null,
            'effectiveDate' => $record['effective_date'] ?? null,
            'expiryDate' => $record['expiry_date'] ?? null,
            'coverageLevel' => $record['coverage_level'] ?? null,
            'copayPercent' => $record['copay_percent'] ?? null,
            'coverageLimitAmount' => $record['coverage_limit_amount'] ?? null,
            'status' => $record['status'] ?? null,
            'verificationStatus' => $record['verification_status'] ?? null,
            'verificationDate' => $record['verification_date'] ?? null,
            'verificationSource' => $record['verification_source'] ?? null,
            'verificationReference' => $record['verification_reference'] ?? null,
            'lastVerifiedAt' => $record['last_verified_at'] ?? null,
            'verifiedByUserId' => $record['verified_by_user_id'] ?? null,
            'notes' => $record['notes'] ?? null,
            'metadata' => $record['metadata'] ?? null,
            'createdAt' => $record['created_at'] ?? null,
            'updatedAt' => $record['updated_at'] ?? null,
        ];
    }
}
