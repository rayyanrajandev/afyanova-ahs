<?php

namespace App\Modules\Billing\Presentation\Http\Controllers;

use App\Modules\Billing\Infrastructure\Integrations\Sms\BillingSmsService;
use App\Modules\Billing\Infrastructure\Models\BillingInvoiceModel;
use App\Modules\Billing\Infrastructure\Models\BillingPaymentLinkModel;
use App\Modules\Billing\Infrastructure\Models\BillingSmsLogModel;
use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class BillingSmsController extends Controller
{
    public function __construct(
        private readonly BillingSmsService $smsService,
        private readonly CurrentPlatformScopeContextInterface $scopeContext,
    ) {}

    public function sendPaymentLinkSms(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'billing_payment_link_id' => 'required|string|uuid',
            'phone_number' => 'required|string|max:20',
        ]);

        $paymentLink = BillingPaymentLinkModel::query()
            ->where('id', $validated['billing_payment_link_id'])
            ->where('tenant_id', $this->scopeContext->tenantId())
            ->where('facility_id', $this->scopeContext->facilityId())
            ->first();

        if (!$paymentLink) {
            return response()->json(['message' => 'Payment link not found'], 404);
        }

        $result = $this->smsService->sendPaymentLinkSms(
            paymentLink: $paymentLink,
            phoneNumber: $validated['phone_number'],
        );

        return response()->json([
            'success' => $result->success,
            'message' => $result->message,
            'data' => [
                'provider_message_id' => $result->providerMessageId,
                'status' => $result->status,
            ],
        ], $result->success ? 200 : 422);
    }

    public function sendReceiptSms(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'billing_invoice_id' => 'required|string|uuid',
            'phone_number' => 'required|string|max:20',
            'payment_reference' => 'nullable|string|max:100',
        ]);

        $invoice = BillingInvoiceModel::query()
            ->where('id', $validated['billing_invoice_id'])
            ->where('tenant_id', $this->scopeContext->tenantId())
            ->where('facility_id', $this->scopeContext->facilityId())
            ->first();

        if (!$invoice) {
            return response()->json(['message' => 'Invoice not found'], 404);
        }

        $result = $this->smsService->sendReceiptSms(
            invoice: $invoice,
            phoneNumber: $validated['phone_number'],
            paymentReference: $validated['payment_reference'] ?? null,
        );

        return response()->json([
            'success' => $result->success,
            'message' => $result->message,
            'data' => [
                'provider_message_id' => $result->providerMessageId,
                'status' => $result->status,
            ],
        ], $result->success ? 200 : 422);
    }

    public function sendCustomSms(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'phone_number' => 'required|string|max:20',
            'message' => 'required|string|max:160',
            'message_type' => 'nullable|string|max:50',
            'billing_invoice_id' => 'nullable|string|uuid',
            'patient_id' => 'nullable|string|uuid',
        ]);

        $result = $this->smsService->sendCustomSms(
            phoneNumber: $validated['phone_number'],
            message: $validated['message'],
            messageType: $validated['message_type'] ?? 'custom',
            billingInvoiceId: $validated['billing_invoice_id'] ?? null,
            patientId: $validated['patient_id'] ?? null,
        );

        return response()->json([
            'success' => $result->success,
            'message' => $result->message,
            'data' => [
                'provider_message_id' => $result->providerMessageId,
                'status' => $result->status,
            ],
        ], $result->success ? 200 : 422);
    }

    public function smsLog(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'message_type' => 'nullable|string|max:50',
            'billing_invoice_id' => 'nullable|string|uuid',
            'status' => 'nullable|string|in:pending,sent,failed',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $query = BillingSmsLogModel::query()
            ->where('tenant_id', $this->scopeContext->tenantId())
            ->where('facility_id', $this->scopeContext->facilityId());

        if (!empty($validated['message_type'])) {
            $query->where('message_type', $validated['message_type']);
        }

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
