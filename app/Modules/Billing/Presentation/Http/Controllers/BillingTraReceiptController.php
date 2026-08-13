<?php

namespace App\Modules\Billing\Presentation\Http\Controllers;

use App\Modules\Billing\Infrastructure\Integrations\BillingIntegrationService;
use App\Modules\Billing\Infrastructure\Models\BillingTraReceiptModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class BillingTraReceiptController extends Controller
{
    public function __construct(
        private readonly BillingIntegrationService $integrationService,
    ) {}

    public function issueReceipt(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'billing_invoice_id' => 'required|string|uuid',
            'payment_id' => 'nullable|string|uuid',
            'line_items' => 'required|array|min:1',
            'line_items.*.description' => 'required|string',
            'line_items.*.quantity' => 'required|numeric|min:0.01',
            'line_items.*.unitPrice' => 'required|numeric|min:0',
            'line_items.*.lineTotal' => 'required|numeric|min:0',
            'line_items.*.vatGroup' => 'nullable|string|in:A,B,C,D,E',
            'total_excl_tax' => 'required|numeric|min:0',
            'total_tax' => 'required|numeric|min:0',
            'total_incl_tax' => 'required|numeric|min:0',
            'payment_method' => 'required|string',
            'customer_name' => 'nullable|string|max:255',
            'customer_id_type' => 'nullable|string|in:1,2,3,4,5,6',
            'customer_id' => 'nullable|string|max:100',
            'customer_mobile' => 'nullable|string|max:20',
        ]);

        $receipt = $this->integrationService->issueFiscalReceipt(
            paymentId: $validated['payment_id'] ?? $validated['billing_invoice_id'],
            referenceNumber: $validated['billing_invoice_id'],
            lineItems: $validated['line_items'],
            totalExclTax: (float) $validated['total_excl_tax'],
            totalTax: (float) $validated['total_tax'],
            totalInclTax: (float) $validated['total_incl_tax'],
            paymentMethod: $validated['payment_method'],
            customerName: $validated['customer_name'] ?? null,
            customerIdType: $validated['customer_id_type'] ?? null,
            customerId: $validated['customer_id'] ?? null,
            customerMobile: $validated['customer_mobile'] ?? null,
        );

        if (! $receipt['success']) {
            return response()->json([
                'success' => false,
                'message' => $receipt['message'] ?? 'Failed to issue fiscal receipt',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Fiscal receipt issued successfully',
            'data' => $receipt,
        ]);
    }

    public function getReceipt(string $rctvnum): JsonResponse
    {
        $receipt = BillingTraReceiptModel::where('rctvnum', $rctvnum)->first();

        if ($receipt === null) {
            return response()->json([
                'success' => false,
                'message' => 'Receipt not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $receipt->toArray(),
        ]);
    }

    public function getReceiptsForInvoice(string $billingInvoiceId): JsonResponse
    {
        $receipts = BillingTraReceiptModel::where('billing_invoice_id', $billingInvoiceId)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $receipts->toArray(),
        ]);
    }
}
