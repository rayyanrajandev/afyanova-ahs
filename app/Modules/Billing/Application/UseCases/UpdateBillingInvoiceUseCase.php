<?php

namespace App\Modules\Billing\Application\UseCases;

use App\Modules\Billing\Application\Exceptions\AdmissionNotEligibleForBillingInvoiceException;
use App\Modules\Billing\Application\Exceptions\AppointmentNotEligibleForBillingInvoiceException;
use App\Modules\Billing\Application\Exceptions\BillingInvoiceDraftOnlyFieldUpdateNotAllowedException;
use App\Modules\Billing\Application\Exceptions\BillingInvoiceLineItemsUpdateNotAllowedException;
use App\Modules\Billing\Application\Exceptions\BillingInvoicePricingResolutionException;
use App\Modules\Billing\Application\Exceptions\PatientNotEligibleForBillingInvoiceException;
use App\Modules\Billing\Application\Support\BillingInvoiceLineItemAutoPricingResolver;
use App\Modules\Billing\Application\Support\BillingInvoicePayerSummaryResolver;
use App\Modules\Billing\Application\Support\ConsultationReviewDiscountApplier;
use App\Modules\Billing\Domain\Repositories\BillingInvoiceAuditLogRepositoryInterface;
use App\Modules\Billing\Domain\Repositories\BillingInvoiceRepositoryInterface;
use App\Modules\Billing\Domain\Services\AdmissionLookupServiceInterface;
use App\Modules\Billing\Domain\Services\AppointmentLookupServiceInterface;
use App\Modules\Billing\Domain\Services\PatientLookupServiceInterface;
use App\Modules\Platform\Domain\Services\DefaultCurrencyResolverInterface;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;

class UpdateBillingInvoiceUseCase
{
    public function __construct(
        private readonly BillingInvoiceRepositoryInterface $billingInvoiceRepository,
        private readonly BillingInvoiceAuditLogRepositoryInterface $auditLogRepository,
        private readonly PatientLookupServiceInterface $patientLookupService,
        private readonly AppointmentLookupServiceInterface $appointmentLookupService,
        private readonly AdmissionLookupServiceInterface $admissionLookupService,
        private readonly BillingInvoiceLineItemAutoPricingResolver $lineItemAutoPricingResolver,
        private readonly BillingInvoicePayerSummaryResolver $payerSummaryResolver,
        private readonly ConsultationReviewDiscountApplier $consultationReviewDiscountApplier,
        private readonly DefaultCurrencyResolverInterface $defaultCurrencyResolver,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    public function execute(string $id, array $payload, ?int $actorId = null): ?array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $existing = $this->billingInvoiceRepository->findById($id);
        if (! $existing) {
            return null;
        }

        $this->assertDraftOnlyFieldUpdatesAllowed($payload, $existing);

        $patientId = (string) ($payload['patient_id'] ?? $existing['patient_id']);
        if (! $this->patientLookupService->patientExists($patientId)) {
            throw new PatientNotEligibleForBillingInvoiceException(
                'Billing invoice can only be assigned to an existing patient.',
            );
        }

        $appointmentId = $payload['appointment_id'] ?? ($existing['appointment_id'] ?? null);
        $linkedAppointment = null;
        if ($appointmentId === null && (($payload['admission_id'] ?? ($existing['admission_id'] ?? null)) === null)) {
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

        $admissionId = $payload['admission_id'] ?? ($existing['admission_id'] ?? null);
        $linkedAdmission = null;
        if ($admissionId !== null) {
            $linkedAdmission = $this->admissionLookupService->findById((string) $admissionId);
        }
        if ($admissionId !== null && ($linkedAdmission === null || ($linkedAdmission['patient_id'] ?? null) !== $patientId)) {
            throw new AdmissionNotEligibleForBillingInvoiceException(
                'Admission is not valid for the selected patient.',
            );
        }

        if (array_key_exists('line_items', $payload)) {
            $this->assertUniqueSourceLineItems(is_array($payload['line_items'] ?? null) ? $payload['line_items'] : null);
            $this->assertNoExistingSourceCharges(
                patientId: $patientId,
                lineItems: is_array($payload['line_items'] ?? null) ? $payload['line_items'] : null,
                currentInvoiceId: $id,
            );

            // Mirrors CreateBillingInvoiceUseCase::calculateManualLineItemSubtotal():
            // in manual pricing mode nothing else recomputes subtotal_amount from
            // line_items, so appending/removing a line item silently left
            // subtotal/total/balance stale unless the caller also happened to pass
            // subtotal_amount explicitly.
            $explicitAutoPricingToggle = $payload['auto_price_line_items'] ?? null;
            $willAutoPrice = is_bool($explicitAutoPricingToggle)
                ? $explicitAutoPricingToggle
                : (($existing['pricing_mode'] ?? null) === 'service_catalog');

            if (! $willAutoPrice && ! array_key_exists('subtotal_amount', $payload)) {
                $payload['subtotal_amount'] = $this->calculateManualLineItemSubtotal(
                    is_array($payload['line_items'] ?? null) ? $payload['line_items'] : null,
                );
            }
        }

        $refreshReviewPricing = $this->requiresReviewPricingRefresh($payload);

        $payload = $this->inheritVisitCoverage($payload, $existing, $linkedAppointment, $linkedAdmission);
        $payload = $this->applyLineItemPricing($payload, $existing);
        if ($refreshReviewPricing) {
            $payload = $this->consultationReviewDiscountApplier->apply($this->mergeForPricingAdjustment($payload, $existing));
        }
        $payload = $this->normalizeAmountsIfNeeded($payload, $existing);
        $payload = $this->enrichPricingContext($payload, $existing, $linkedAppointment, $linkedAdmission);

        $updated = $this->billingInvoiceRepository->update($id, $payload);
        if (! $updated) {
            return null;
        }

        $changes = $this->extractChanges($existing, $updated);
        if ($changes !== []) {
            $this->auditLogRepository->write(
                billingInvoiceId: $id,
                action: 'billing-invoice.updated',
                actorId: $actorId,
                changes: $changes,
            );
        }

        return $updated;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $existing
     */
    private function assertDraftOnlyFieldUpdatesAllowed(array $payload, array $existing): void
    {
        if (($existing['status'] ?? null) === 'draft') {
            return;
        }

        if (array_key_exists('line_items', $payload)) {
            throw new BillingInvoiceLineItemsUpdateNotAllowedException(
                'Billing invoice line items can only be edited while the invoice is in draft status.',
            );
        }

        $draftOnlyFields = [
            'patient_id' => 'patientId',
            'admission_id' => 'admissionId',
            'appointment_id' => 'appointmentId',
            'issued_by_user_id' => 'issuedByUserId',
            'invoice_date' => 'invoiceDate',
            'currency_code' => 'currencyCode',
            'billing_payer_contract_id' => 'billingPayerContractId',
            'subtotal_amount' => 'subtotalAmount',
            'discount_amount' => 'discountAmount',
            'tax_amount' => 'taxAmount',
        ];

        foreach ($draftOnlyFields as $storageField => $requestField) {
            if (! array_key_exists($storageField, $payload)) {
                continue;
            }

            throw new BillingInvoiceDraftOnlyFieldUpdateNotAllowedException(
                $requestField,
                'This billing invoice field can only be edited while the invoice is in draft status.',
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeAmountsIfNeeded(array $payload, array $existing): array
    {
        $tracked = ['subtotal_amount', 'discount_amount', 'tax_amount', 'paid_amount'];
        $requiresRecalculate = false;

        foreach ($tracked as $field) {
            if (array_key_exists($field, $payload)) {
                $requiresRecalculate = true;
                break;
            }
        }

        if (! $requiresRecalculate) {
            return $payload;
        }

        $subtotal = max((float) ($payload['subtotal_amount'] ?? $existing['subtotal_amount'] ?? 0), 0);
        $discount = max((float) ($payload['discount_amount'] ?? $existing['discount_amount'] ?? 0), 0);
        $tax = max((float) ($payload['tax_amount'] ?? $existing['tax_amount'] ?? 0), 0);
        $paid = max((float) ($payload['paid_amount'] ?? $existing['paid_amount'] ?? 0), 0);

        $total = round(max(($subtotal - $discount) + $tax, 0), 2);
        $paid = round(min($paid, $total), 2);
        $balance = round(max($total - $paid, 0), 2);

        $payload['subtotal_amount'] = round($subtotal, 2);
        $payload['discount_amount'] = round($discount, 2);
        $payload['tax_amount'] = round($tax, 2);
        $payload['total_amount'] = $total;
        $payload['paid_amount'] = $paid;
        $payload['balance_amount'] = $balance;

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function extractChanges(array $before, array $after): array
    {
        $trackedFields = [
            'patient_id',
            'admission_id',
            'appointment_id',
            'billing_payer_contract_id',
            'issued_by_user_id',
            'invoice_date',
            'currency_code',
            'subtotal_amount',
            'discount_amount',
            'tax_amount',
            'total_amount',
            'paid_amount',
            'balance_amount',
            'payment_due_at',
            'notes',
            'line_items',
            'pricing_mode',
            'pricing_context',
            'status',
            'status_reason',
        ];

        $changes = [];
        foreach ($trackedFields as $field) {
            $beforeValue = $before[$field] ?? null;
            $afterValue = $after[$field] ?? null;

            if ($beforeValue === $afterValue) {
                continue;
            }

            $changes[$field] = [
                'before' => $beforeValue,
                'after' => $afterValue,
            ];
        }

        return $changes;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $existing
     * @return array<string, mixed>
     */
    private function applyLineItemPricing(array $payload, array $existing): array
    {
        $hasLineItemsInPayload = array_key_exists('line_items', $payload);
        $hasPricingRelatedChange = $hasLineItemsInPayload
            || array_key_exists('auto_price_line_items', $payload)
            || array_key_exists('billing_payer_contract_id', $payload)
            || array_key_exists('currency_code', $payload)
            || array_key_exists('invoice_date', $payload)
            || array_key_exists('discount_amount', $payload)
            || array_key_exists('paid_amount', $payload);

        if (! $hasPricingRelatedChange) {
            return $payload;
        }

        $explicitAutoPricingToggle = $payload['auto_price_line_items'] ?? null;
        $shouldAutoPrice = is_bool($explicitAutoPricingToggle)
            ? $explicitAutoPricingToggle
            : ($hasLineItemsInPayload && (($existing['pricing_mode'] ?? null) === 'service_catalog'));

        $effectiveLineItems = $hasLineItemsInPayload
            ? (is_array($payload['line_items']) ? $payload['line_items'] : null)
            : (is_array($existing['line_items'] ?? null) ? $existing['line_items'] : null);

        $effectiveCurrency = $this->resolveCurrencyCode($payload['currency_code'] ?? $existing['currency_code'] ?? null);
        $effectiveInvoiceDate = isset($payload['invoice_date'])
            ? (string) $payload['invoice_date']
            : (isset($existing['invoice_date']) ? (string) $existing['invoice_date'] : null);
        $effectiveDiscount = array_key_exists('discount_amount', $payload)
            ? $this->discountExcludingConsultationReview($payload)
            : $this->discountExcludingConsultationReview($existing);
        $effectivePaid = (float) ($payload['paid_amount'] ?? $existing['paid_amount'] ?? 0);
        $effectivePayerContractId = isset($payload['billing_payer_contract_id'])
            ? (string) $payload['billing_payer_contract_id']
            : (isset($existing['billing_payer_contract_id']) ? (string) $existing['billing_payer_contract_id'] : null);

        $pricingPatch = $this->lineItemAutoPricingResolver->resolve(
            lineItems: $effectiveLineItems,
            currencyCode: $effectiveCurrency,
            invoiceDateTime: $effectiveInvoiceDate,
            discountAmount: $effectiveDiscount,
            paidAmount: $effectivePaid,
            autoPriceLineItems: $shouldAutoPrice,
            billingPayerContractId: $effectivePayerContractId,
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
    private function calculateManualLineItemSubtotal(?array $lineItems): float
    {
        if ($lineItems === null) {
            return 0.0;
        }

        $subtotal = 0.0;
        foreach ($lineItems as $lineItem) {
            $quantity = max((float) ($lineItem['quantity'] ?? 0), 0);
            $unitPrice = max((float) ($lineItem['unitPrice'] ?? 0), 0);
            $lineTotal = isset($lineItem['lineTotal'])
                ? max((float) $lineItem['lineTotal'], 0)
                : round($quantity * $unitPrice, 2);

            $subtotal += $lineTotal;
        }

        return round($subtotal, 2);
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
     * @param  array<int, array<string, mixed>>|null  $lineItems
     */
    private function assertNoExistingSourceCharges(string $patientId, ?array $lineItems, string $currentInvoiceId): void
    {
        foreach ($lineItems ?? [] as $lineItem) {
            $sourceWorkflowKind = $this->normalizeNullableString($lineItem['sourceWorkflowKind'] ?? null);
            $sourceWorkflowId = $this->normalizeNullableString($lineItem['sourceWorkflowId'] ?? null);

            if ($sourceWorkflowKind === null || $sourceWorkflowId === null) {
                continue;
            }

            $existingInvoice = $this->billingInvoiceRepository->findByLineItemSource(
                patientId: $patientId,
                sourceWorkflowKind: $sourceWorkflowKind,
                sourceWorkflowId: $sourceWorkflowId,
                excludeInvoiceId: $currentInvoiceId,
            );

            if ($existingInvoice === null) {
                continue;
            }

            throw new BillingInvoicePricingResolutionException(
                'lineItems',
                sprintf(
                    'This source workflow has already been charged on invoice %s.',
                    $existingInvoice['invoice_number'] ?? $existingInvoice['id'] ?? 'an existing invoice',
                ),
            );
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function requiresReviewPricingRefresh(array $payload): bool
    {
        foreach ([
            'appointment_id',
            'line_items',
            'auto_price_line_items',
            'billing_payer_contract_id',
            'currency_code',
            'invoice_date',
            'discount_amount',
            'subtotal_amount',
            'tax_amount',
            'paid_amount',
        ] as $field) {
            if (array_key_exists($field, $payload)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $existing
     * @return array<string, mixed>
     */
    private function mergeForPricingAdjustment(array $payload, array $existing): array
    {
        return array_merge(
            [
                'patient_id' => $existing['patient_id'] ?? null,
                'admission_id' => $existing['admission_id'] ?? null,
                'appointment_id' => $existing['appointment_id'] ?? null,
                'billing_payer_contract_id' => $existing['billing_payer_contract_id'] ?? null,
                'issued_by_user_id' => $existing['issued_by_user_id'] ?? null,
                'invoice_date' => $existing['invoice_date'] ?? null,
                'currency_code' => $existing['currency_code'] ?? null,
                'subtotal_amount' => $existing['subtotal_amount'] ?? 0,
                'discount_amount' => $this->discountExcludingConsultationReview($existing),
                'tax_amount' => $existing['tax_amount'] ?? 0,
                'paid_amount' => $existing['paid_amount'] ?? 0,
                'balance_amount' => $existing['balance_amount'] ?? 0,
                'payment_due_at' => $existing['payment_due_at'] ?? null,
                'notes' => $existing['notes'] ?? null,
                'line_items' => $existing['line_items'] ?? null,
                'pricing_mode' => $existing['pricing_mode'] ?? null,
                'pricing_context' => is_array($existing['pricing_context'] ?? null)
                    ? $existing['pricing_context']
                    : null,
            ],
            $payload,
            [
                'discount_amount' => array_key_exists('discount_amount', $payload)
                    ? $this->discountExcludingConsultationReview($payload)
                    : $this->discountExcludingConsultationReview($existing),
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $invoiceLike
     */
    private function discountExcludingConsultationReview(array $invoiceLike): float
    {
        $discountAmount = max((float) ($invoiceLike['discount_amount'] ?? 0), 0);
        $pricingContext = is_array($invoiceLike['pricing_context'] ?? null)
            ? $invoiceLike['pricing_context']
            : [];
        $reviewContext = is_array($pricingContext['consultationReviewDiscount'] ?? null)
            ? $pricingContext['consultationReviewDiscount']
            : [];

        if (! (bool) ($reviewContext['applied'] ?? false)) {
            return round($discountAmount, 2);
        }

        $reviewDiscountAmount = max((float) ($reviewContext['reviewDiscountAmount'] ?? 0), 0);

        return round(max($discountAmount - $reviewDiscountAmount, 0), 2);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $existing
     * @return array<string, mixed>
     */
    private function enrichPricingContext(
        array $payload,
        array $existing,
        ?array $appointment,
        ?array $admission
    ): array {
        $requiresRefresh = array_key_exists('billing_payer_contract_id', $payload)
            || array_key_exists('subtotal_amount', $payload)
            || array_key_exists('discount_amount', $payload)
            || array_key_exists('tax_amount', $payload)
            || array_key_exists('total_amount', $payload)
            || array_key_exists('invoice_date', $payload)
            || array_key_exists('line_items', $payload)
            || array_key_exists('pricing_context', $payload)
            || ! is_array($existing['pricing_context'] ?? null);

        if (! $requiresRefresh) {
            return $payload;
        }

        $effectiveContractId = isset($payload['billing_payer_contract_id'])
            ? (string) $payload['billing_payer_contract_id']
            : (isset($existing['billing_payer_contract_id']) ? (string) $existing['billing_payer_contract_id'] : null);
        $effectiveCurrencyCode = $this->resolveCurrencyCode($payload['currency_code'] ?? $existing['currency_code'] ?? null);
        $effectiveInvoiceDate = isset($payload['invoice_date'])
            ? (string) $payload['invoice_date']
            : (isset($existing['invoice_date']) ? (string) $existing['invoice_date'] : null);
        $effectiveTotalAmount = (float) ($payload['total_amount'] ?? $existing['total_amount'] ?? 0);
        $effectivePricingContext = is_array($payload['pricing_context'] ?? null)
            ? $payload['pricing_context']
            : (is_array($existing['pricing_context'] ?? null) ? $existing['pricing_context'] : null);
        $effectivePricingContext = $this->mergeVisitCoverageIntoPricingContext(
            $effectivePricingContext,
            $appointment,
            $admission,
        );

        $payload['pricing_context'] = $this->payerSummaryResolver->resolveFromLegacyInvoice(
            billingPayerContractId: $effectiveContractId,
            currencyCode: $effectiveCurrencyCode,
            totalAmount: $effectiveTotalAmount,
            invoiceDateTime: $effectiveInvoiceDate,
            pricingContext: $effectivePricingContext,
        );

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $existing
     * @param  array<string, mixed>|null  $appointment
     * @param  array<string, mixed>|null  $admission
     * @return array<string, mixed>
     */
    private function inheritVisitCoverage(
        array $payload,
        array $existing,
        ?array $appointment,
        ?array $admission
    ): array {
        $effectiveVisit = $admission ?? $appointment;

        $existingContractId = $this->normalizeNullableString($existing['billing_payer_contract_id'] ?? null);
        $payloadContractId = array_key_exists('billing_payer_contract_id', $payload)
            ? $this->normalizeNullableString($payload['billing_payer_contract_id'] ?? null)
            : $existingContractId;
        $inheritedContractId = $effectiveVisit !== null
            ? $this->normalizeNullableString($effectiveVisit['billing_payer_contract_id'] ?? null)
            : null;

        if (
            $inheritedContractId !== null
            && $payloadContractId === null
            && (! array_key_exists('billing_payer_contract_id', $payload) || $existingContractId === null)
        ) {
            $payload['billing_payer_contract_id'] = $inheritedContractId;
        }

        $effectivePricingContext = is_array($payload['pricing_context'] ?? null)
            ? $payload['pricing_context']
            : (is_array($existing['pricing_context'] ?? null) ? $existing['pricing_context'] : []);
        $payload['pricing_context'] = $this->mergeVisitCoverageIntoPricingContext(
            $effectivePricingContext,
            $appointment,
            $admission,
        );

        return $payload;
    }

    /**
     * @param  array<string, mixed>|null  $pricingContext
     * @param  array<string, mixed>|null  $appointment
     * @param  array<string, mixed>|null  $admission
     * @return array<string, mixed>
     */
    private function mergeVisitCoverageIntoPricingContext(
        ?array $pricingContext,
        ?array $appointment,
        ?array $admission
    ): array {
        $context = is_array($pricingContext) ? $pricingContext : [];
        $visitCoverage = $this->buildVisitCoverageSnapshot($appointment, $admission);

        if ($visitCoverage === null) {
            unset($context['visitCoverage']);
        } else {
            $context['visitCoverage'] = $visitCoverage;
        }

        return $context;
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
