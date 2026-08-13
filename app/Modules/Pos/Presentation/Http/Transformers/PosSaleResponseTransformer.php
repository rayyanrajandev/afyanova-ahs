<?php

namespace App\Modules\Pos\Presentation\Http\Transformers;

class PosSaleResponseTransformer
{
    public static function transform(array $sale): array
    {
        return [
            'id' => $sale['id'] ?? null,
            'tenantId' => $sale['tenant_id'] ?? null,
            'facilityId' => $sale['facility_id'] ?? null,
            'registerId' => $sale['pos_register_id'] ?? null,
            'registerSessionId' => $sale['pos_register_session_id'] ?? null,
            'patientId' => $sale['patient_id'] ?? null,
            'saleNumber' => $sale['sale_number'] ?? null,
            'receiptNumber' => $sale['receipt_number'] ?? null,
            'saleChannel' => $sale['sale_channel'] ?? null,
            'customerType' => $sale['customer_type'] ?? null,
            'customerName' => $sale['customer_name'] ?? null,
            'customerReference' => $sale['customer_reference'] ?? null,
            'currencyCode' => $sale['currency_code'] ?? null,
            'status' => $sale['status'] ?? null,
            'subtotalAmount' => $sale['subtotal_amount'] ?? null,
            'discountAmount' => $sale['discount_amount'] ?? null,
            'taxAmount' => $sale['tax_amount'] ?? null,
            'totalAmount' => $sale['total_amount'] ?? null,
            'paidAmount' => $sale['paid_amount'] ?? null,
            'balanceAmount' => $sale['balance_amount'] ?? null,
            'changeAmount' => $sale['change_amount'] ?? null,
            'soldAt' => $sale['sold_at'] ?? null,
            'completedByUserId' => $sale['completed_by_user_id'] ?? null,
            'notes' => $sale['notes'] ?? null,
            'metadata' => is_array($sale['metadata'] ?? null) ? $sale['metadata'] : null,
            'register' => is_array($sale['register'] ?? null)
                ? PosRegisterResponseTransformer::transformSummary($sale['register'])
                : null,
            'session' => is_array($sale['session'] ?? null)
                ? PosRegisterSessionResponseTransformer::transformSummary($sale['session'])
                : null,
            'lineItems' => is_array($sale['line_items'] ?? null)
                ? array_map([self::class, 'transformLineItem'], $sale['line_items'])
                : null,
            'payments' => is_array($sale['payments'] ?? null)
                ? array_map([self::class, 'transformPayment'], $sale['payments'])
                : null,
            'adjustments' => is_array($sale['adjustments'] ?? null)
                ? array_map([self::class, 'transformAdjustment'], $sale['adjustments'])
                : null,
            'createdAt' => $sale['created_at'] ?? null,
            'updatedAt' => $sale['updated_at'] ?? null,
        ];
    }

    /**
     * @param array<string, mixed> $lineItem
     * @return array<string, mixed>
     */
    public static function transformLineItem(array $lineItem): array
    {
        return [
            'id' => $lineItem['id'] ?? null,
            'lineNumber' => $lineItem['line_number'] ?? null,
            'itemType' => $lineItem['item_type'] ?? null,
            'itemReference' => $lineItem['item_reference'] ?? null,
            'itemCode' => $lineItem['item_code'] ?? null,
            'itemName' => $lineItem['item_name'] ?? null,
            'quantity' => $lineItem['quantity'] ?? null,
            'unitPrice' => $lineItem['unit_price'] ?? null,
            'lineSubtotalAmount' => $lineItem['line_subtotal_amount'] ?? null,
            'discountAmount' => $lineItem['discount_amount'] ?? null,
            'taxAmount' => $lineItem['tax_amount'] ?? null,
            'lineTotalAmount' => $lineItem['line_total_amount'] ?? null,
            'notes' => $lineItem['notes'] ?? null,
            'metadata' => is_array($lineItem['metadata'] ?? null) ? $lineItem['metadata'] : null,
        ];
    }

    /**
     * @param array<string, mixed> $payment
     * @return array<string, mixed>
     */
    public static function transformPayment(array $payment): array
    {
        return [
            'id' => $payment['id'] ?? null,
            'paymentMethod' => $payment['payment_method'] ?? null,
            'amountReceived' => $payment['amount_received'] ?? null,
            'amountApplied' => $payment['amount_applied'] ?? null,
            'changeGiven' => $payment['change_given'] ?? null,
            'paymentReference' => $payment['payment_reference'] ?? null,
            'paidAt' => $payment['paid_at'] ?? null,
            'collectedByUserId' => $payment['collected_by_user_id'] ?? null,
            'note' => $payment['note'] ?? null,
            'metadata' => is_array($payment['metadata'] ?? null) ? $payment['metadata'] : null,
        ];
    }

    /**
     * @param array<string, mixed> $adjustment
     * @return array<string, mixed>
     */
    public static function transformAdjustment(array $adjustment): array
    {
        return [
            'id' => $adjustment['id'] ?? null,
            'registerId' => $adjustment['pos_register_id'] ?? null,
            'registerSessionId' => $adjustment['pos_register_session_id'] ?? null,
            'adjustmentNumber' => $adjustment['adjustment_number'] ?? null,
            'adjustmentType' => $adjustment['adjustment_type'] ?? null,
            'amount' => $adjustment['amount'] ?? null,
            'cashAmount' => $adjustment['cash_amount'] ?? null,
            'nonCashAmount' => $adjustment['non_cash_amount'] ?? null,
            'currencyCode' => $adjustment['currency_code'] ?? null,
            'paymentMethod' => $adjustment['payment_method'] ?? null,
            'adjustmentReference' => $adjustment['adjustment_reference'] ?? null,
            'reasonCode' => $adjustment['reason_code'] ?? null,
            'notes' => $adjustment['notes'] ?? null,
            'processedByUserId' => $adjustment['processed_by_user_id'] ?? null,
            'processedAt' => $adjustment['processed_at'] ?? null,
            'metadata' => is_array($adjustment['metadata'] ?? null) ? $adjustment['metadata'] : null,
        ];
    }
}
