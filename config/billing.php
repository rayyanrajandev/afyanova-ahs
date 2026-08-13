<?php

return [
    'payments' => [
        'reversal' => [
            // If set (> 0), reversals at or above this amount require approvalCaseReference.
            'approval_case_reference_required_at_or_above_amount' => (float) env(
                'BILLING_PAYMENT_REVERSAL_APPROVAL_CASE_REFERENCE_REQUIRED_AT_OR_ABOVE_AMOUNT',
                0
            ),
            // If enabled, any reversal on a fully paid invoice requires approvalCaseReference.
            'approval_case_reference_required_for_paid_invoice_reversals' => (bool) env(
                'BILLING_PAYMENT_REVERSAL_APPROVAL_CASE_REFERENCE_REQUIRED_FOR_PAID_INVOICE_REVERSALS',
                false
            ),
        ],
    ],
];
