<?php

namespace App\Modules\Billing\Infrastructure\Integrations;

use App\Modules\Billing\Domain\Integrations\NhifVerificationInterface;
use App\Modules\Billing\Domain\Integrations\PaymentGatewayInterface;
use App\Modules\Billing\Domain\Integrations\TraFiscalReceiptInterface;
use App\Modules\Billing\Infrastructure\Integrations\DTOs\FiscalReceiptRequest;
use App\Modules\Billing\Infrastructure\Integrations\DTOs\PaymentRequest;
use App\Modules\Billing\Infrastructure\Integrations\DTOs\PaymentResponse;
use App\Modules\Billing\Infrastructure\Models\BillingNhifVerificationModel;
use App\Modules\Billing\Infrastructure\Models\BillingPaymentGatewayTransactionModel;
use App\Modules\Billing\Infrastructure\Models\BillingTraReceiptModel;

class BillingIntegrationService
{
    public function __construct(
        private readonly PaymentGatewayInterface $paymentGateway,
        private readonly NhifVerificationInterface $nhifVerification,
        private readonly TraFiscalReceiptInterface $traReceipt,
        private readonly ?string $tenantId = null,
        private readonly ?string $facilityId = null,
    ) {}

    public function processPaymentViaGateway(
        string $invoiceId,
        string $amount,
        string $phoneNumber,
        string $reference,
        string $description,
        string $currency = 'TZS',
    ): PaymentResponse {
        $request = new PaymentRequest(
            amount: $amount,
            currencyCode: $currency,
            phoneNumber: $phoneNumber,
            reference: $reference,
            description: $description,
        );

        $response = $this->paymentGateway->collectPayment($request);

        BillingPaymentGatewayTransactionModel::create([
            'tenant_id' => $this->tenantId,
            'facility_id' => $this->facilityId,
            'billing_invoice_id' => $invoiceId,
            'gateway' => config('billing-integrations.payment_gateway.driver', 'selcom'),
            'transaction_reference' => $response->transactionReference,
            'provider_reference' => $response->providerReference,
            'phone_number' => $phoneNumber,
            'amount' => $amount,
            'currency' => $currency,
            'status' => $response->success ? 'pending' : 'failed',
            'description' => $description,
            'response_payload' => $response->rawResponse,
        ]);

        return $response;
    }

    public function verifyNhifMember(
        string $memberId,
        ?string $patientId = null,
        ?string $insuranceRecordId = null,
        ?int $userId = null,
    ): ?array {
        $result = $this->nhifVerification->verifyMember($memberId);

        if ($result === null) {
            return null;
        }

        BillingNhifVerificationModel::create([
            'tenant_id' => $this->tenantId,
            'facility_id' => $this->facilityId,
            'patient_id' => $patientId,
            'patient_insurance_record_id' => $insuranceRecordId,
            'member_id' => $memberId,
            'card_status' => $result['card_status'],
            'is_active' => $result['is_active'],
            'member_name' => $result['member_name'],
            'plan_name' => $result['plan_name'],
            'employer_name' => $result['employer_name'],
            'effective_date' => $result['effective_date'],
            'expiry_date' => $result['expiry_date'],
            'outstanding_balance' => $result['outstanding_balance'],
            'dependants' => $result['dependants'],
            'raw_response' => $result['raw_response'],
            'source' => 'api',
            'verified_by_user_id' => $userId,
        ]);

        return $result;
    }

    public function issueFiscalReceipt(
        string $paymentId,
        string $referenceNumber,
        array $lineItems,
        float $totalExclTax,
        float $totalTax,
        float $totalInclTax,
        string $paymentMethod,
        ?string $customerName = null,
        ?string $customerIdType = null,
        ?string $customerId = null,
        ?string $customerMobile = null,
    ): ?array {
        $request = new FiscalReceiptRequest(
            referenceNumber: $referenceNumber,
            tin: config('billing-integrations.tra_vfd.totalvfd.tin', ''),
            businessName: config('billing-integrations.tra_vfd.totalvfd.business_name', ''),
            businessLocation: config('billing-integrations.tra_vfd.totalvfd.business_city', ''),
            lineItems: $lineItems,
            totalExclTax: $totalExclTax,
            totalTax: $totalTax,
            totalInclTax: $totalInclTax,
            paymentMethod: $paymentMethod,
            customerName: $customerName,
            customerIdType: $customerIdType,
            customerId: $customerId,
            customerMobile: $customerMobile,
        );

        $response = $this->traReceipt->issueReceipt($request);

        if ($response->success) {
            BillingTraReceiptModel::create([
                'tenant_id' => $this->tenantId,
                'facility_id' => $this->facilityId,
                'billing_invoice_id' => $referenceNumber,
                'billing_invoice_payment_id' => $paymentId,
                'reference_number' => $referenceNumber,
                'rctvnum' => $response->rctvnum,
                'verification_link' => $response->verificationLink,
                'local_date' => $response->localDate,
                'local_time' => $response->localTime,
                'gc' => $response->gc,
                'dc' => $response->dc,
                'z_number' => $response->zNumber,
                'total_incl_tax' => $totalInclTax,
                'total_tax' => $totalTax,
                'raw_response' => $response->rawResponse,
                'status' => 'active',
            ]);
        }

        return [
            'success' => $response->success,
            'rctvnum' => $response->rctvnum,
            'verification_link' => $response->verificationLink,
            'local_date' => $response->localDate,
            'local_time' => $response->localTime,
            'gc' => $response->gc,
            'dc' => $response->dc,
            'z_number' => $response->zNumber,
            'message' => $response->message,
        ];
    }
}
