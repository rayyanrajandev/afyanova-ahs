<?php

namespace App\Modules\Billing\Presentation\Http\Transformers;

class BillingCorporateAccountResponseTransformer
{
    public static function transform(array $account): array
    {
        return [
            'id' => $account['id'] ?? null,
            'billingPayerContractId' => $account['billing_payer_contract_id'] ?? null,
            'accountCode' => $account['account_code'] ?? null,
            'accountName' => $account['account_name'] ?? null,
            'billingContactName' => $account['billing_contact_name'] ?? null,
            'billingContactEmail' => $account['billing_contact_email'] ?? null,
            'billingContactPhone' => $account['billing_contact_phone'] ?? null,
            'billingCycleDay' => $account['billing_cycle_day'] ?? null,
            'settlementTermsDays' => $account['settlement_terms_days'] ?? null,
            'status' => $account['status'] ?? null,
            'notes' => $account['notes'] ?? null,
            'metadata' => $account['metadata'] ?? null,
            'contractCode' => $account['contract_code'] ?? null,
            'contractName' => $account['contract_name'] ?? null,
            'payerType' => $account['payer_type'] ?? null,
            'payerName' => $account['payer_name'] ?? null,
            'currencyCode' => $account['currency_code'] ?? null,
            'createdAt' => $account['created_at'] ?? null,
            'updatedAt' => $account['updated_at'] ?? null,
        ];
    }
}
