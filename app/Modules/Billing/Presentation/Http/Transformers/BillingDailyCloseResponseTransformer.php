<?php

namespace App\Modules\Billing\Presentation\Http\Transformers;

class BillingDailyCloseResponseTransformer
{
    public static function transform(array $dailyClose): array
    {
        return [
            'id' => $dailyClose['id'] ?? null,
            'tenantId' => $dailyClose['tenant_id'] ?? null,
            'facilityId' => $dailyClose['facility_id'] ?? null,
            'closedByUserId' => $dailyClose['closed_by_user_id'] ?? null,
            'closedAt' => $dailyClose['closed_at'] ?? null,
            'openedAt' => $dailyClose['opened_at'] ?? null,
            'totalCashAmount' => (float) ($dailyClose['total_cash_amount'] ?? 0),
            'totalCardAmount' => (float) ($dailyClose['total_card_amount'] ?? 0),
            'totalMpesaAmount' => (float) ($dailyClose['total_mpesa_amount'] ?? 0),
            'totalOtherAmount' => (float) ($dailyClose['total_other_amount'] ?? 0),
            'totalRevenue' => (float) ($dailyClose['total_revenue'] ?? 0),
            'totalRefunds' => (float) ($dailyClose['total_refunds'] ?? 0),
            'netRevenue' => (float) ($dailyClose['net_revenue'] ?? 0),
            'notes' => $dailyClose['notes'] ?? null,
            'status' => $dailyClose['status'] ?? null,
            'verifiedByUserId' => $dailyClose['verified_by_user_id'] ?? null,
            'verifiedAt' => $dailyClose['verified_at'] ?? null,
            'createdAt' => $dailyClose['created_at'] ?? null,
            'updatedAt' => $dailyClose['updated_at'] ?? null,
        ];
    }
}
