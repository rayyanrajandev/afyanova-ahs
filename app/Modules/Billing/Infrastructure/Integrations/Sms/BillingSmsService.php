<?php

namespace App\Modules\Billing\Infrastructure\Integrations\Sms;

use App\Modules\Billing\Domain\Integrations\SmsProviderInterface;
use App\Modules\Billing\Domain\ValueObjects\SmsResult;
use App\Modules\Billing\Infrastructure\Models\BillingInvoiceModel;
use App\Modules\Billing\Infrastructure\Models\BillingPaymentLinkModel;
use App\Modules\Billing\Infrastructure\Models\BillingSmsLogModel;
use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use Illuminate\Support\Str;

class BillingSmsService
{
    public function __construct(
        private readonly SmsProviderInterface $smsProvider,
        private readonly CurrentPlatformScopeContextInterface $scopeContext,
    ) {}

    public function sendPaymentLinkSms(
        BillingPaymentLinkModel $paymentLink,
        string $phoneNumber,
    ): SmsResult {
        $amount = number_format($paymentLink->amount, 0);
        $message = "You have a pending payment of TZS {$amount} for invoice {$paymentLink->reference_code}. Dial *150*01# or check your M-Pesa prompt to complete payment. Ref: {$paymentLink->reference_code}";

        $result = $this->smsProvider->send($phoneNumber, $message);

        BillingSmsLogModel::create([
            'tenant_id' => $this->scopeContext->tenantId(),
            'facility_id' => $this->scopeContext->facilityId(),
            'phone_number' => $phoneNumber,
            'message_type' => 'payment_link',
            'message' => $message,
            'provider' => class_basename($this->smsProvider),
            'provider_message_id' => $result->providerMessageId,
            'status' => $result->success ? 'sent' : 'failed',
            'error_message' => $result->success ? null : $result->message,
            'context' => [
                'reference_code' => $paymentLink->reference_code,
                'amount' => $paymentLink->amount,
            ],
            'billing_invoice_id' => $paymentLink->billing_invoice_id,
            'billing_payment_link_id' => $paymentLink->id,
            'sent_at' => $result->success ? now() : null,
        ]);

        return $result;
    }

    public function sendReceiptSms(
        BillingInvoiceModel $invoice,
        string $phoneNumber,
        ?string $paymentReference = null,
    ): SmsResult {
        $amount = number_format((float) $invoice->paid_amount, 0);
        $invNum = $invoice->invoice_number ?? Str::limit($invoice->id, 8, '');
        $ref = $paymentReference ? " Ref: {$paymentReference}" : '';
        $message = "Payment received TZS {$amount} for invoice {$invNum}.{$ref} Thank you.";

        $result = $this->smsProvider->send($phoneNumber, $message);

        BillingSmsLogModel::create([
            'tenant_id' => $this->scopeContext->tenantId(),
            'facility_id' => $this->scopeContext->facilityId(),
            'phone_number' => $phoneNumber,
            'message_type' => 'receipt',
            'message' => $message,
            'provider' => class_basename($this->smsProvider),
            'provider_message_id' => $result->providerMessageId,
            'status' => $result->success ? 'sent' : 'failed',
            'error_message' => $result->success ? null : $result->message,
            'context' => [
                'invoice_number' => $invoice->invoice_number,
                'amount' => $invoice->paid_amount,
                'payment_reference' => $paymentReference,
            ],
            'billing_invoice_id' => $invoice->id,
            'sent_at' => $result->success ? now() : null,
        ]);

        return $result;
    }

    public function sendCustomSms(
        string $phoneNumber,
        string $message,
        string $messageType = 'custom',
        ?string $billingInvoiceId = null,
        ?string $patientId = null,
    ): SmsResult {
        $result = $this->smsProvider->send($phoneNumber, $message);

        BillingSmsLogModel::create([
            'tenant_id' => $this->scopeContext->tenantId(),
            'facility_id' => $this->scopeContext->facilityId(),
            'phone_number' => $phoneNumber,
            'message_type' => $messageType,
            'message' => $message,
            'provider' => class_basename($this->smsProvider),
            'provider_message_id' => $result->providerMessageId,
            'status' => $result->success ? 'sent' : 'failed',
            'error_message' => $result->success ? null : $result->message,
            'billing_invoice_id' => $billingInvoiceId,
            'patient_id' => $patientId,
            'sent_at' => $result->success ? now() : null,
        ]);

        return $result;
    }
}
