<?php

namespace App\Modules\Pos\Presentation\Http\Transformers;

class PosRegisterSessionResponseTransformer
{
    public static function transform(array $session): array
    {
        return [
            'id' => $session['id'] ?? null,
            'tenantId' => $session['tenant_id'] ?? null,
            'facilityId' => $session['facility_id'] ?? null,
            'registerId' => $session['pos_register_id'] ?? null,
            'sessionNumber' => $session['session_number'] ?? null,
            'status' => $session['status'] ?? null,
            'openedAt' => $session['opened_at'] ?? null,
            'closedAt' => $session['closed_at'] ?? null,
            'openingCashAmount' => $session['opening_cash_amount'] ?? null,
            'closingCashAmount' => $session['closing_cash_amount'] ?? null,
            'expectedCashAmount' => $session['expected_cash_amount'] ?? null,
            'discrepancyAmount' => $session['discrepancy_amount'] ?? null,
            'grossSalesAmount' => $session['gross_sales_amount'] ?? null,
            'totalDiscountAmount' => $session['total_discount_amount'] ?? null,
            'totalTaxAmount' => $session['total_tax_amount'] ?? null,
            'cashNetSalesAmount' => $session['cash_net_sales_amount'] ?? null,
            'nonCashSalesAmount' => $session['non_cash_sales_amount'] ?? null,
            'saleCount' => $session['sale_count'] ?? null,
            'voidCount' => $session['void_count'] ?? null,
            'refundCount' => $session['refund_count'] ?? null,
            'adjustmentAmount' => $session['adjustment_amount'] ?? null,
            'cashAdjustmentAmount' => $session['cash_adjustment_amount'] ?? null,
            'nonCashAdjustmentAmount' => $session['non_cash_adjustment_amount'] ?? null,
            'openingNote' => $session['opening_note'] ?? null,
            'closingNote' => $session['closing_note'] ?? null,
            'openedByUserId' => $session['opened_by_user_id'] ?? null,
            'closedByUserId' => $session['closed_by_user_id'] ?? null,
            'register' => is_array($session['register'] ?? null)
                ? PosRegisterResponseTransformer::transformSummary($session['register'])
                : null,
            'closeoutPreview' => is_array($session['closeout_preview'] ?? null)
                ? self::transformCloseoutPreview($session['closeout_preview'])
                : null,
            'createdAt' => $session['created_at'] ?? null,
            'updatedAt' => $session['updated_at'] ?? null,
        ];
    }

    public static function transformSummary(array $session): array
    {
        return [
            'id' => $session['id'] ?? null,
            'sessionNumber' => $session['session_number'] ?? null,
            'status' => $session['status'] ?? null,
            'openedAt' => $session['opened_at'] ?? null,
            'openedByUserId' => $session['opened_by_user_id'] ?? null,
        ];
    }

    /**
     * @param array<string, mixed> $preview
     * @return array<string, mixed>
     */
    public static function transformCloseoutPreview(array $preview): array
    {
        return [
            'expectedCashAmount' => $preview['expected_cash_amount'] ?? null,
            'grossSalesAmount' => $preview['gross_sales_amount'] ?? null,
            'totalDiscountAmount' => $preview['total_discount_amount'] ?? null,
            'totalTaxAmount' => $preview['total_tax_amount'] ?? null,
            'cashNetSalesAmount' => $preview['cash_net_sales_amount'] ?? null,
            'nonCashSalesAmount' => $preview['non_cash_sales_amount'] ?? null,
            'saleCount' => $preview['sale_count'] ?? null,
            'voidCount' => $preview['void_count'] ?? null,
            'refundCount' => $preview['refund_count'] ?? null,
            'adjustmentAmount' => $preview['adjustment_amount'] ?? null,
            'cashAdjustmentAmount' => $preview['cash_adjustment_amount'] ?? null,
            'nonCashAdjustmentAmount' => $preview['non_cash_adjustment_amount'] ?? null,
        ];
    }
}
