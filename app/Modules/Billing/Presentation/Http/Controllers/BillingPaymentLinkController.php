<?php

namespace App\Modules\Billing\Presentation\Http\Controllers;

use App\Modules\Billing\Infrastructure\Integrations\PaymentGateway\SelcomPaymentLinkService;
use App\Modules\Billing\Infrastructure\Integrations\Sms\BillingSmsService;
use App\Modules\Billing\Infrastructure\Models\BillingInvoiceModel;
use App\Modules\Billing\Infrastructure\Models\BillingPaymentLinkModel;
use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

class BillingPaymentLinkController extends Controller
{
    public function __construct(
        private readonly SelcomPaymentLinkService $paymentLinkService,
        private readonly CurrentPlatformScopeContextInterface $scopeContext,
        private readonly ?BillingSmsService $smsService = null,
    ) {}

    public function initiatePayment(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'billing_invoice_id' => 'required|string|uuid',
            'phone_number' => 'required|string|max:20',
            'amount' => 'nullable|numeric|min:0',
            'expires_in_hours' => 'nullable|integer|min:1|max:168',
        ]);

        $invoice = BillingInvoiceModel::query()
            ->where('id', $validated['billing_invoice_id'])
            ->where('tenant_id', $this->scopeContext->tenantId())
            ->where('facility_id', $this->scopeContext->facilityId())
            ->first();

        if (!$invoice) {
            return response()->json(['message' => 'Invoice not found'], 404);
        }

        $amount = $validated['amount'] ?? (float) $invoice->total_amount;
        $referenceCode = 'PAY-' . strtoupper(Str::random(12));

        $existingLink = BillingPaymentLinkModel::query()
            ->where('billing_invoice_id', $invoice->id)
            ->where('status', 'pending')
            ->where('expires_at', '>', now())
            ->first();

        if ($existingLink) {
            return response()->json([
                'message' => 'An active payment link already exists',
                'data' => $existingLink,
            ], 409);
        }

        $expiresAt = now()->addHours((int) ($validated['expires_in_hours'] ?? 24));

        $payload = [
            'invoice_number' => $invoice->invoice_number ?? $invoice->id,
            'description' => 'Payment for invoice ' . ($invoice->invoice_number ?? $invoice->id),
            'currency' => 'TZS',
        ];

        $result = $this->paymentLinkService->generatePaymentLink(
            payload: $payload,
            phoneNumber: $validated['phone_number'],
            amount: $amount,
            referenceCode: $referenceCode,
        );

        $paymentLink = BillingPaymentLinkModel::create([
            'tenant_id' => $this->scopeContext->tenantId(),
            'facility_id' => $this->scopeContext->facilityId(),
            'billing_invoice_id' => $invoice->id,
            'patient_id' => $invoice->patient_id,
            'phone_number' => $validated['phone_number'],
            'amount' => $amount,
            'currency' => 'TZS',
            'reference_code' => $referenceCode,
            'status' => $result->success ? 'pending' : 'failed',
            'gateway_transaction_id' => $result->gatewayTransactionId,
            'provider_reference' => $result->providerReference,
            'request_payload' => $payload,
            'response_payload' => $result->rawResponse,
            'expires_at' => $expiresAt,
        ]);

        if ($result->success) {
            $smsSent = false;
            if ($this->smsService) {
                $smsResult = $this->smsService->sendPaymentLinkSms(
                    paymentLink: $paymentLink,
                    phoneNumber: $validated['phone_number'],
                );
                $smsSent = $smsResult->success;
            }

            return response()->json([
                'success' => true,
                'message' => 'M-Pesa push initiated to ' . $validated['phone_number'],
                'data' => [
                    'payment_link_id' => $paymentLink->id,
                    'reference_code' => $referenceCode,
                    'amount' => $amount,
                    'status' => 'pending',
                    'expires_at' => $expiresAt,
                    'sms_sent' => $smsSent,
                ],
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => $result->message ?? 'Failed to initiate payment',
            'data' => $paymentLink,
        ], 422);
    }

    public function checkPaymentStatus(Request $request, string $referenceCode): JsonResponse
    {
        $paymentLink = BillingPaymentLinkModel::query()
            ->where('reference_code', $referenceCode)
            ->where('tenant_id', $this->scopeContext->tenantId())
            ->where('facility_id', $this->scopeContext->facilityId())
            ->first();

        if (!$paymentLink) {
            return response()->json(['message' => 'Payment link not found'], 404);
        }

        $result = $this->paymentLinkService->checkPaymentStatus($referenceCode);

        if ($result->status === 'completed') {
            $paymentLink->update([
                'status' => 'completed',
                'paid_at' => now(),
            ]);
        } elseif ($result->status === 'failed') {
            $paymentLink->update(['status' => 'failed']);
        }

        return response()->json([
            'success' => $result->success,
            'data' => [
                'reference_code' => $referenceCode,
                'status' => $paymentLink->fresh()->status,
                'amount' => $paymentLink->amount,
                'paid_at' => $paymentLink->paid_at,
            ],
        ]);
    }

    public function listPaymentLinks(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'billing_invoice_id' => 'nullable|string|uuid',
            'status' => 'nullable|string|in:pending,completed,failed,expired',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $query = BillingPaymentLinkModel::query()
            ->where('tenant_id', $this->scopeContext->tenantId())
            ->where('facility_id', $this->scopeContext->facilityId());

        if (!empty($validated['billing_invoice_id'])) {
            $query->where('billing_invoice_id', $validated['billing_invoice_id']);
        }

        if (!empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        $perPage = min((int) ($validated['per_page'] ?? 20), 100);

        return response()->json([
            'data' => $query->orderByDesc('created_at')->paginate($perPage),
        ]);
    }
}
