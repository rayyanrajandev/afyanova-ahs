<?php

namespace App\Modules\Billing\Presentation\Http\Controllers;

use App\Modules\Billing\Application\UseCases\RecordBillingInvoicePaymentUseCase;
use App\Modules\Billing\Infrastructure\Integrations\BillingIntegrationService;
use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class BillingPaymentGatewayController extends Controller
{
    public function __construct(
        private readonly BillingIntegrationService $integrationService,
        private readonly RecordBillingInvoicePaymentUseCase $recordPaymentUseCase,
        private readonly CurrentPlatformScopeContextInterface $scopeContext,
    ) {}

    public function initiatePayment(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'billing_invoice_id' => 'required|string|uuid',
            'amount' => 'required|numeric|min:0.01',
            'phone_number' => 'required|string|max:20',
            'description' => 'nullable|string|max:255',
        ]);

        $invoiceId = $validated['billing_invoice_id'];
        $amount = (string) $validated['amount'];
        $phone = $validated['phone_number'];
        $description = $validated['description'] ?? 'Invoice payment';

        $response = $this->integrationService->processPaymentViaGateway(
            invoiceId: $invoiceId,
            amount: $amount,
            phoneNumber: $phone,
            reference: $invoiceId,
            description: $description,
        );

        if (! $response->success) {
            return response()->json([
                'success' => false,
                'message' => $response->message,
                'transaction_reference' => $response->transactionReference,
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => $response->message,
            'transaction_reference' => $response->transactionReference,
            'provider_reference' => $response->providerReference,
            'status' => $response->status,
        ]);
    }

    public function checkStatus(string $transactionReference): JsonResponse
    {
        $response = $this->integrationService->processPaymentViaGateway(
            invoiceId: '',
            amount: '0',
            phoneNumber: '',
            reference: $transactionReference,
            description: 'Status check',
        );

        return response()->json([
            'success' => $response->success,
            'message' => $response->message,
            'transaction_reference' => $transactionReference,
            'status' => $response->status,
        ]);
    }

    public function confirmAndRecordPayment(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'billing_invoice_id' => 'required|string|uuid',
            'transaction_reference' => 'required|string',
            'provider_reference' => 'nullable|string',
            'amount' => 'required|numeric|min:0.01',
            'payment_method' => 'required|string|in:mobile_money,lipa_namba,cash,card,bank_transfer,cheque',
            'note' => 'nullable|string|max:500',
        ]);

        $scope = $this->scopeContext->getCurrentScope();

        $payment = $this->recordPaymentUseCase->execute(
            billingInvoiceId: $validated['billing_invoice_id'],
            amount: (float) $validated['amount'],
            payerType: 'cash',
            paymentMethod: $validated['payment_method'],
            paymentReference: $validated['provider_reference'] ?? $validated['transaction_reference'],
            note: $validated['note'] ?? null,
            paymentAt: now()->toDateTimeString(),
            actorId: $request->user()?->id,
        );

        if ($payment === null) {
            return response()->json(['success' => false, 'message' => 'Failed to record payment'], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Payment recorded successfully',
            'invoice' => $payment['invoice'] ?? null,
            'payment' => $payment['payment'] ?? null,
        ]);
    }
}
