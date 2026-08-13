<?php

namespace App\Modules\Billing\Presentation\Http\Transformers;

class CashBillingAccountResponseTransformer
{
    public static function transform(array $account): array
    {
        $displayName = mb_strtoupper(collect([
            $account['first_name'] ?? null,
            $account['middle_name'] ?? null,
            $account['last_name'] ?? null,
        ])->filter()->implode(' '));

        return [
            'id' => $account['id'] ?? null,
            'tenant_id' => $account['tenant_id'] ?? null,
            'facility_id' => $account['facility_id'] ?? null,
            'patient_id' => $account['patient_id'] ?? null,
            'currency_code' => $account['currency_code'] ?? null,
            'account_balance' => isset($account['account_balance']) ? (float) $account['account_balance'] : null,
            'total_charged' => isset($account['total_charged']) ? (float) $account['total_charged'] : null,
            'total_paid' => isset($account['total_paid']) ? (float) $account['total_paid'] : null,
            'status' => $account['status'] ?? null,
            'notes' => $account['notes'] ?? null,
            'patient' => [
                'id' => $account['patient_id'] ?? null,
                'patient_number' => $account['patient_number'] ?? null,
                'first_name' => $account['first_name'] ?? null,
                'middle_name' => $account['middle_name'] ?? null,
                'last_name' => $account['last_name'] ?? null,
                'display_name' => $displayName !== '' ? $displayName : ($account['patient_number'] ?? null),
                'phone' => $account['patient_phone'] ?? null,
                'gender' => $account['patient_gender'] ?? null,
                'date_of_birth' => $account['patient_date_of_birth'] ?? null,
                'status' => $account['patient_status'] ?? null,
            ],
            'created_at' => $account['created_at'] ?? null,
            'updated_at' => $account['updated_at'] ?? null,
        ];
    }
}
