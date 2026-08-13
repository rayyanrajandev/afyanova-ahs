<?php

namespace App\Modules\Billing\Application\UseCases;

use App\Modules\Billing\Application\Exceptions\AdmissionNotEligibleForBillingInvoiceException;
use App\Modules\Billing\Application\Exceptions\AppointmentNotEligibleForBillingInvoiceException;
use App\Modules\Billing\Application\Exceptions\BillingInvoicePricingResolutionException;
use App\Modules\Billing\Application\Exceptions\PatientNotEligibleForBillingInvoiceException;
use App\Modules\Billing\Application\Support\BillingInvoiceLineItemAutoPricingResolver;
use App\Modules\Billing\Application\Support\BillingInvoicePayerSummaryResolver;
use App\Modules\Billing\Application\Support\ConsultationReviewDiscountApplier;
use App\Modules\Billing\Domain\Services\AdmissionLookupServiceInterface;
use App\Modules\Billing\Domain\Services\AppointmentLookupServiceInterface;
use App\Modules\Billing\Domain\Services\PatientLookupServiceInterface;
use App\Modules\Platform\Domain\Services\DefaultCurrencyResolverInterface;

class PreviewBillingInvoiceUseCase
{
    public function __construct(
        private readonly PatientLookupServiceInterface $patientLookupService,
        private readonly AppointmentLookupServiceInterface $appointmentLookupService,
        private readonly AdmissionLookupServiceInterface $admissionLookupService,
        private readonly BillingInvoiceLineItemAutoPricingResolver $lineItemAutoPricingResolver,
        private readonly BillingInvoicePayerSummaryResolver $payerSummaryResolver,
        private readonly ConsultationReviewDiscountApplier $consultationReviewDiscountApplier,
        private readonly DefaultCurrencyResolverInterface $defaultCurrencyResolver,
    ) {}

    public function execute(array $payload): array
    {
        $patientId = (string) $payload['patient_id'];
        if (! $this->patientLookupService->patientExists($patientId)) {
            throw new PatientNotEligibleForBillingInvoiceException(
                'Billing invoice preview requires an existing patient.',
            );
        }

        $appointmentId = $payload['appointment_id'] ?? null;
        $linkedAppointment = null;
        if ($appointmentId === null && ($payload['admission_id'] ?? null) === null) {
            $linkedAppointment = $this->appointmentLookupService->findSingleActiveBillingAppointmentForPatient($patientId);
            if ($linkedAppointment !== null) {
                $appointmentId = $linkedAppointment['id'] ?? null;
                $payload['appointment_id'] = $appointmentId;
            }
        }
        if ($appointmentId !== null) {
            $linkedAppointment = $this->appointmentLookupService->findById((string) $appointmentId);
        }
        if ($appointmentId !== null && ($linkedAppointment === null || ($linkedAppointment['patient_id'] ?? null) !== $patientId)) {
            throw new AppointmentNotEligibleForBillingInvoiceException(
                'Appointment is not valid for the selected patient.',
            );
        }

        $admissionId = $payload['admission_id'] ?? null;
        $linkedAdmission = null;
        if ($admissionId !== null) {
            $linkedAdmission = $this->admissionLookupService->findById((string) $admissionId);
        }
        if ($admissionId !== null && ($linkedAdmission === null || ($linkedAdmission['patient_id'] ?? null) !== $patientId)) {
            throw new AdmissionNotEligibleForBillingInvoiceException(
                'Admission is not valid for the selected patient.',
            );
        }

        $this->assertUniqueSourceLineItems(is_array($payload['line_items'] ?? null) ? $payload['line_items'] : null);
        $payload = $this->inheritVisitCoverage($payload, $linkedAppointment, $linkedAdmission);
        $payload = $this->applyLineItemPricing($payload);
        $payload = $this->consultationReviewDiscountApplier->apply($payload);
        $payload = array_merge($payload, $this->normalizeAmounts($payload));
        $payload['currency_code'] = $this->resolveCurrencyCode($payload['currency_code'] ?? null);
        $payload['pricing_context'] = $this->payerSummaryResolver->resolveFromLegacyInvoice(
            billingPayerContractId: isset($payload['billing_payer_contract_id']) ? (string) $payload['billing_payer_contract_id'] : null,
            currencyCode: $payload['currency_code'],
            totalAmount: (float) ($payload['total_amount'] ?? 0),
            invoiceDateTime: isset($payload['invoice_date']) ? (string) $payload['invoice_date'] : null,
            pricingContext: is_array($payload['pricing_context'] ?? null) ? $payload['pricing_context'] : null,
        );

        return [
            'id' => null,
            'invoice_number' => null,
            'patient_id' => $patientId,
            'admission_id' => $payload['admission_id'] ?? null,
            'appointment_id' => $payload['appointment_id'] ?? null,
            'billing_payer_contract_id' => $payload['billing_payer_contract_id'] ?? null,
            'issued_by_user_id' => $payload['issued_by_user_id'] ?? null,
            'invoice_date' => $payload['invoice_date'] ?? null,
            'currency_code' => $payload['currency_code'],
            'subtotal_amount' => $payload['subtotal_amount'] ?? 0,
            'discount_amount' => $payload['discount_amount'] ?? 0,
            'tax_amount' => $payload['tax_amount'] ?? 0,
            'total_amount' => $payload['total_amount'] ?? 0,
            'paid_amount' => $payload['paid_amount'] ?? 0,
            'last_payment_at' => null,
            'last_payment_payer_type' => null,
            'last_payment_method' => null,
            'last_payment_reference' => null,
            'balance_amount' => $payload['balance_amount'] ?? 0,
            'payment_due_at' => $payload['payment_due_at'] ?? null,
            'notes' => $payload['notes'] ?? null,
            'line_items' => $payload['line_items'] ?? null,
            'pricing_mode' => $payload['pricing_mode'] ?? null,
            'pricing_context' => $payload['pricing_context'] ?? null,
            'status' => 'draft',
            'status_reason' => null,
            'created_at' => null,
            'updated_at' => null,
        ];
    }

    /**
     * @return array<string, float>
     */
    private function normalizeAmounts(array $payload): array
    {
        $subtotal = max((float) ($payload['subtotal_amount'] ?? 0), 0);
        $discount = max((float) ($payload['discount_amount'] ?? 0), 0);
        $tax = max((float) ($payload['tax_amount'] ?? 0), 0);
        $paid = max((float) ($payload['paid_amount'] ?? 0), 0);

        $total = round(max(($subtotal - $discount) + $tax, 0), 2);
        $paid = round(min($paid, $total), 2);
        $balance = round(max($total - $paid, 0), 2);

        return [
            'subtotal_amount' => round($subtotal, 2),
            'discount_amount' => round($discount, 2),
            'tax_amount' => round($tax, 2),
            'total_amount' => $total,
            'paid_amount' => $paid,
            'balance_amount' => $balance,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function applyLineItemPricing(array $payload): array
    {
        $pricingPatch = $this->lineItemAutoPricingResolver->resolve(
            lineItems: is_array($payload['line_items'] ?? null) ? $payload['line_items'] : null,
            currencyCode: $this->resolveCurrencyCode($payload['currency_code'] ?? null),
            invoiceDateTime: isset($payload['invoice_date']) ? (string) $payload['invoice_date'] : null,
            discountAmount: (float) ($payload['discount_amount'] ?? 0),
            paidAmount: (float) ($payload['paid_amount'] ?? 0),
            autoPriceLineItems: (bool) ($payload['auto_price_line_items'] ?? false),
            billingPayerContractId: isset($payload['billing_payer_contract_id']) ? (string) $payload['billing_payer_contract_id'] : null,
        );

        unset($payload['auto_price_line_items']);

        return array_merge($payload, $pricingPatch);
    }

    private function resolveCurrencyCode(mixed $value): string
    {
        $currencyCode = strtoupper(trim((string) $value));

        return $currencyCode !== '' ? $currencyCode : $this->defaultCurrencyResolver->resolve();
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $lineItems
     */
    private function assertUniqueSourceLineItems(?array $lineItems): void
    {
        $seen = [];

        foreach ($lineItems ?? [] as $index => $lineItem) {
            $sourceWorkflowKind = $this->normalizeNullableString($lineItem['sourceWorkflowKind'] ?? null);
            $sourceWorkflowId = $this->normalizeNullableString($lineItem['sourceWorkflowId'] ?? null);

            if ($sourceWorkflowKind === null || $sourceWorkflowId === null) {
                continue;
            }

            $sourceKey = strtolower($sourceWorkflowKind).'::'.$sourceWorkflowId;
            if (isset($seen[$sourceKey])) {
                throw new BillingInvoicePricingResolutionException(
                    'lineItems',
                    sprintf(
                        'Line item %d duplicates a source workflow already present on this invoice.',
                        $index + 1,
                    ),
                );
            }

            $seen[$sourceKey] = true;
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>|null  $appointment
     * @param  array<string, mixed>|null  $admission
     * @return array<string, mixed>
     */
    private function inheritVisitCoverage(array $payload, ?array $appointment, ?array $admission): array
    {
        $effectiveVisit = $admission ?? $appointment;

        if ($effectiveVisit !== null) {
            $inheritedContractId = $this->normalizeNullableString($effectiveVisit['billing_payer_contract_id'] ?? null);
            if (
                $inheritedContractId !== null
                && $this->normalizeNullableString($payload['billing_payer_contract_id'] ?? null) === null
            ) {
                $payload['billing_payer_contract_id'] = $inheritedContractId;
            }
        }

        $pricingContext = is_array($payload['pricing_context'] ?? null) ? $payload['pricing_context'] : [];
        $visitCoverage = $this->buildVisitCoverageSnapshot($appointment, $admission);

        if ($visitCoverage === null) {
            unset($pricingContext['visitCoverage']);
        } else {
            $pricingContext['visitCoverage'] = $visitCoverage;
        }

        $payload['pricing_context'] = $pricingContext;

        return $payload;
    }

    /**
     * @param  array<string, mixed>|null  $appointment
     * @param  array<string, mixed>|null  $admission
     * @return array<string, mixed>|null
     */
    private function buildVisitCoverageSnapshot(?array $appointment, ?array $admission): ?array
    {
        $effectiveVisit = $admission ?? $appointment;
        if ($effectiveVisit === null) {
            return null;
        }

        return [
            'source' => $admission !== null ? 'admission' : 'appointment',
            'sourceId' => $effectiveVisit['id'] ?? null,
            'sourceNumber' => $effectiveVisit['admission_number'] ?? $effectiveVisit['appointment_number'] ?? null,
            'financialClass' => $effectiveVisit['financial_coverage_type'] ?? 'self_pay',
            'billingPayerContractId' => $effectiveVisit['billing_payer_contract_id'] ?? null,
            'coverageReference' => $effectiveVisit['coverage_reference'] ?? null,
            'coverageNotes' => $effectiveVisit['coverage_notes'] ?? null,
        ];
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }
}
