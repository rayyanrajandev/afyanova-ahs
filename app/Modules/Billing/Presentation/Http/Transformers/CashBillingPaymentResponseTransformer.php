<?php

namespace App\Modules\Billing\Presentation\Http\Transformers;

class CashBillingPaymentResponseTransformer
{
    public static function transform(array $payment): array
    {
        return [
            'id' => $payment['id'] ?? null,
            'cash_billing_account_id' => $payment['cash_billing_account_id'] ?? null,
            'amount_paid' => isset($payment['amount_paid']) ? (float) $payment['amount_paid'] : null,
            'currency_code' => $payment['currency_code'] ?? null,
            'payment_method' => $payment['payment_method'] ?? null,
            'payment_reference' => $payment['payment_reference'] ?? null,
            'mobile_money_provider' => $payment['mobile_money_provider'] ?? null,
            'mobile_money_transaction_id' => $payment['mobile_money_transaction_id'] ?? null,
            'card_last_four' => $payment['card_last_four'] ?? null,
            'check_number' => $payment['check_number'] ?? null,
            'paid_at' => $payment['paid_at'] ?? null,
            'confirmed_by_user_id' => $payment['confirmed_by_user_id'] ?? null,
            'receipt_number' => $payment['receipt_number'] ?? null,
            'notes' => $payment['notes'] ?? null,
            'refunded_amount' => isset($payment['refunded_amount']) ? (float) $payment['refunded_amount'] : null,
            'refunded_at' => $payment['refunded_at'] ?? null,
            'refunded_by_user_id' => $payment['refunded_by_user_id'] ?? null,
            'refund_reason' => $payment['refund_reason'] ?? null,
            'remaining_balance' => isset($payment['remaining_balance']) ? (float) $payment['remaining_balance'] : null,
            'created_at' => $payment['created_at'] ?? null,
            'updated_at' => $payment['updated_at'] ?? null,
        ];
    }
}
