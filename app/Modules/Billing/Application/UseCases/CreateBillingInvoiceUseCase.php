<?php

namespace App\Modules\Billing\Application\UseCases;

use App\Modules\Billing\Application\Exceptions\AdmissionNotEligibleForBillingInvoiceException;
use App\Modules\Billing\Application\Exceptions\AppointmentNotEligibleForBillingInvoiceException;
use App\Modules\Billing\Application\Exceptions\BillingInvoicePricingResolutionException;
use App\Modules\Billing\Application\Exceptions\EncounterNotEligibleForBillingInvoiceException;
use App\Modules\Billing\Application\Exceptions\PatientNotEligibleForBillingInvoiceException;
use App\Modules\Billing\Application\Support\BillingInvoiceLineItemAutoPricingResolver;
use App\Modules\Billing\Application\Support\BillingInvoicePayerSummaryResolver;
use App\Modules\Billing\Application\Support\ConsultationReviewDiscountApplier;
use App\Modules\Billing\Domain\Events\BillingCashierQueueUpdated;
use App\Modules\Billing\Domain\Repositories\BillingInvoiceAuditLogRepositoryInterface;
use App\Modules\Billing\Domain\Repositories\BillingInvoiceRepositoryInterface;
use App\Modules\Billing\Domain\Services\AdmissionLookupServiceInterface;
use App\Modules\Billing\Domain\Services\AppointmentLookupServiceInterface;
use App\Modules\Billing\Domain\Services\PatientLookupServiceInterface;
use App\Modules\Billing\Domain\ValueObjects\BillingInvoiceStatus;
use App\Modules\Encounter\Application\Services\EncounterResolverService;
use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use App\Modules\Platform\Domain\Services\DefaultCurrencyResolverInterface;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;
use Illuminate\Support\Str;
use RuntimeException;

class CreateBillingInvoiceUseCase
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
        private readonly EncounterResolverService $encounterResolverService,
        private readonly CurrentPlatformScopeContextInterface $platformScopeContext,
        private readonly DefaultCurrencyResolverInterface $defaultCurrencyResolver,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    public function execute(array $payload, ?int $actorId = null): array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $patientId = (string) $payload['patient_id'];
        if (! $this->patientLookupService->patientExists($patientId)) {
            throw new PatientNotEligibleForBillingInvoiceException(
                'Billing invoice can only be created for an existing patient.',
            );
        }

        $appointmentId = $payload['appointment_id'] ?? null;
        $encounterId = $this->normalizeNullableString($payload['encounter_id'] ?? null);
        if ($encounterId !== null) {
            $linkedEncounter = $this->encounterResolverService->findById($encounterId);
            if ($linkedEncounter === null || (string) $linkedEncounter->patient_id !== $patientId) {
                throw new EncounterNotEligibleForBillingInvoiceException(
                    'Encounter is not valid for the selected patient.',
                );
            }

            $payload['encounter_id'] = $encounterId;
            if ($appointmentId === null && $linkedEncounter->appointment_id !== null) {
                $appointmentId = (string) $linkedEncounter->appointment_id;
                $payload['appointment_id'] = $appointmentId;
            }
            if (($payload['admission_id'] ?? null) === null && $linkedEncounter->admission_id !== null) {
                $payload['admission_id'] = (string) $linkedEncounter->admission_id;
            }
        }

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

        $requestedAutoPricing = (bool) ($payload['auto_price_line_items'] ?? false);
        $this->assertUniqueSourceLineItems(is_array($payload['line_items'] ?? null) ? $payload['line_items'] : null);

        $payload = $this->inheritVisitCoverage($payload, $linkedAppointment, $linkedAdmission);
        $payload = $this->applyLineItemPricing($payload);
        $payload = $this->consultationReviewDiscountApplier->apply($payload);

        $normalizedAmounts = $this->normalizeAmounts($payload);
        $payload = array_merge($payload, $normalizedAmounts);
        $payload['currency_code'] = $this->resolveCurrencyCode($payload['currency_code'] ?? null);
        $payload['pricing_context'] = $this->resolvePricingContext(
            billingPayerContractId: isset($payload['billing_payer_contract_id']) ? (string) $payload['billing_payer_contract_id'] : null,
            currencyCode: $payload['currency_code'],
            totalAmount: (float) ($payload['total_amount'] ?? 0),
            invoiceDateTime: isset($payload['invoice_date']) ? (string) $payload['invoice_date'] : null,
            pricingContext: is_array($payload['pricing_context'] ?? null) ? $payload['pricing_context'] : null,
            billingDecision: is_array($payload['billing_decision'] ?? null) ? $payload['billing_decision'] : null,
        );

        $payload['status'] = BillingInvoiceStatus::DRAFT->value;
        $payload['tenant_id'] = $this->platformScopeContext->tenantId();
        $payload['facility_id'] = $this->platformScopeContext->facilityId();
        $payload['last_payment_at'] = ((float) ($payload['paid_amount'] ?? 0)) > 0 ? now() : null;

        $matchingDraft = $this->billingInvoiceRepository->findMatchingDraft(
            patientId: $patientId,
            appointmentId: $this->normalizeNullableString($payload['appointment_id'] ?? null),
            admissionId: $this->normalizeNullableString($payload['admission_id'] ?? null),
            billingPayerContractId: $this->normalizeNullableString($payload['billing_payer_contract_id'] ?? null),
            currencyCode: $payload['currency_code'],
            encounterId: $this->normalizeNullableString($payload['encounter_id'] ?? null),
        );
        $this->assertNoExistingSourceCharges(
            patientId: $patientId,
            lineItems: is_array($payload['line_items'] ?? null) ? $payload['line_items'] : null,
            allowedInvoiceId: isset($matchingDraft['id']) ? (string) $matchingDraft['id'] : null,
        );

        if ($matchingDraft !== null) {
            $continuedInvoice = $this->continueExistingDraft(
                existingDraft: $matchingDraft,
                payload: $payload,
                actorId: $actorId,
                requestedAutoPricing: $requestedAutoPricing,
            );

            event(new BillingCashierQueueUpdated($continuedInvoice['facility_id'] ?? null));

            return [
                'invoice' => $continuedInvoice,
                'draft_reused' => true,
            ];
        }

        $payload['invoice_number'] = $this->generateInvoiceNumber();
        $createdInvoice = $this->billingInvoiceRepository->create($payload);

        $this->auditLogRepository->write(
            billingInvoiceId: $createdInvoice['id'],
            action: 'billing-invoice.created',
            actorId: $actorId,
            changes: [
                'after' => $this->extractTrackedFields($createdInvoice),
            ],
        );

        event(new BillingCashierQueueUpdated($createdInvoice['facility_id'] ?? null));

        return [
            'invoice' => $createdInvoice,
            'draft_reused' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $existingDraft
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function continueExistingDraft(array $existingDraft, array $payload, ?int $actorId, bool $requestedAutoPricing): array
    {
        $mergedPayload = [
            'patient_id' => $existingDraft['patient_id'] ?? $payload['patient_id'],
            'encounter_id' => $payload['encounter_id'] ?? ($existingDraft['encounter_id'] ?? null),
            'admission_id' => $payload['admission_id'] ?? ($existingDraft['admission_id'] ?? null),
            'appointment_id' => $payload['appointment_id'] ?? ($existingDraft['appointment_id'] ?? null),
            'billing_payer_contract_id' => $payload['billing_payer_contract_id'] ?? ($existingDraft['billing_payer_contract_id'] ?? null),
            'issued_by_user_id' => $payload['issued_by_user_id'] ?? ($existingDraft['issued_by_user_id'] ?? null),
            'invoice_date' => $payload['invoice_date'] ?? ($existingDraft['invoice_date'] ?? null),
            'currency_code' => $payload['currency_code'] ?? ($existingDraft['currency_code'] ?? null),
            'discount_amount' => array_key_exists('discount_amount', $payload)
                ? $this->discountExcludingConsultationReview($payload)
                : $this->discountExcludingConsultationReview($existingDraft),
            'tax_amount' => $payload['tax_amount'] ?? ($existingDraft['tax_amount'] ?? 0),
            'paid_amount' => $payload['paid_amount'] ?? ($existingDraft['paid_amount'] ?? 0),
            'payment_due_at' => $this->preferIncomingValue(
                $payload['payment_due_at'] ?? null,
                $existingDraft['payment_due_at'] ?? null,
            ),
            'notes' => $this->preferIncomingValue(
                $payload['notes'] ?? null,
                $existingDraft['notes'] ?? null,
            ),
            'pricing_context' => is_array($payload['pricing_context'] ?? null)
                ? $payload['pricing_context']
                : (is_array($existingDraft['pricing_context'] ?? null) ? $existingDraft['pricing_context'] : null),
            'line_items' => $this->mergeLineItems(
                is_array($existingDraft['line_items'] ?? null) ? $existingDraft['line_items'] : null,
                is_array($payload['line_items'] ?? null) ? $payload['line_items'] : null,
            ),
            'auto_price_line_items' => $requestedAutoPricing || (($existingDraft['pricing_mode'] ?? null) === 'service_catalog'),
        ];
        $mergedPayload['subtotal_amount'] = $this->calculateManualLineItemSubtotal($mergedPayload['line_items'] ?? null);

        $mergedPayload = $this->applyLineItemPricing($mergedPayload);
        $mergedPayload = $this->consultationReviewDiscountApplier->apply($mergedPayload);
        $mergedPayload = array_merge($mergedPayload, $this->normalizeAmounts($mergedPayload));
        $mergedPayload['currency_code'] = $this->resolveCurrencyCode($mergedPayload['currency_code'] ?? null);
        $mergedPayload['pricing_context'] = $this->resolvePricingContext(
            billingPayerContractId: $this->normalizeNullableString($mergedPayload['billing_payer_contract_id'] ?? null),
            currencyCode: $mergedPayload['currency_code'],
            totalAmount: (float) ($mergedPayload['total_amount'] ?? 0),
            invoiceDateTime: isset($mergedPayload['invoice_date']) ? (string) $mergedPayload['invoice_date'] : null,
            pricingContext: is_array($mergedPayload['pricing_context'] ?? null) ? $mergedPayload['pricing_context'] : null,
            billingDecision: is_array($mergedPayload['billing_decision'] ?? null) ? $mergedPayload['billing_decision'] : null,
        );
        $mergedPayload['status'] = BillingInvoiceStatus::DRAFT->value;
        $mergedPayload['last_payment_at'] = ((float) ($mergedPayload['paid_amount'] ?? 0)) > 0
            ? ($existingDraft['last_payment_at'] ?? now())
            : null;

        $updatedInvoice = $this->billingInvoiceRepository->update((string) $existingDraft['id'], $mergedPayload);
        if ($updatedInvoice === null) {
            throw new RuntimeException('Unable to continue existing billing invoice draft.');
        }

        $before = $this->extractTrackedFields($existingDraft);
        $after = $this->extractTrackedFields($updatedInvoice);
        if ($before !== $after) {
            $this->auditLogRepository->write(
                billingInvoiceId: $updatedInvoice['id'],
                action: 'billing-invoice.draft-continued',
                actorId: $actorId,
                changes: [
                    'before' => $before,
                    'after' => $after,
                ],
            );
        }

        return $updatedInvoice;
    }
    private function generateInvoiceNumber(): string
    {
        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $candidate = 'INV'.now()->format('Ymd').strtoupper(Str::random(6));

            if (! $this->billingInvoiceRepository->existsByInvoiceNumber($candidate)) {
                return $candidate;
            }
        }

        throw new RuntimeException('Unable to generate unique billing invoice number.');
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
     * @return array<string, mixed>
     */
    private function extractTrackedFields(array $invoice): array
    {
        $tracked = [
            'invoice_number',
            'tenant_id',
            'facility_id',
            'patient_id',
            'encounter_id',
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
            'last_payment_at',
            'balance_amount',
            'payment_due_at',
            'notes',
            'line_items',
            'pricing_mode',
            'pricing_context',
            'status',
            'status_reason',
        ];

        $result = [];
        foreach ($tracked as $field) {
            $result[$field] = $invoice[$field] ?? null;
        }

        return $result;
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
            $sourceKey = $this->lineItemSourceKey($lineItem);
            if ($sourceKey === null) {
                continue;
            }

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
    private function assertNoExistingSourceCharges(string $patientId, ?array $lineItems, ?string $allowedInvoiceId): void
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
                excludeInvoiceId: $allowedInvoiceId,
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

    private function preferIncomingValue(mixed $incoming, mixed $fallback): mixed
    {
        if (is_string($incoming)) {
            $trimmed = trim($incoming);

            return $trimmed !== '' ? $trimmed : $fallback;
        }

        return $incoming ?? $fallback;
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $existingLineItems
     * @param  array<int, array<string, mixed>>|null  $incomingLineItems
     * @return array<int, array<string, mixed>>|null
     */
    private function mergeLineItems(?array $existingLineItems, ?array $incomingLineItems): ?array
    {
        if ($existingLineItems === null && $incomingLineItems === null) {
            return null;
        }

        $merged = [];
        $indexedSourceKeys = [];

        foreach ($existingLineItems ?? [] as $lineItem) {
            $merged[] = $lineItem;
            $sourceKey = $this->lineItemSourceKey($lineItem);
            if ($sourceKey !== null) {
                $indexedSourceKeys[$sourceKey] = array_key_last($merged);
            }
        }

        foreach ($incomingLineItems ?? [] as $lineItem) {
            $sourceKey = $this->lineItemSourceKey($lineItem);
            if ($sourceKey !== null && array_key_exists($sourceKey, $indexedSourceKeys)) {
                $merged[$indexedSourceKeys[$sourceKey]] = $lineItem;
                continue;
            }

            $merged[] = $lineItem;
            if ($sourceKey !== null) {
                $indexedSourceKeys[$sourceKey] = array_key_last($merged);
            }
        }

        return array_values($merged);
    }

    /**
     * @param  array<string, mixed>  $lineItem
     */
    private function lineItemSourceKey(array $lineItem): ?string
    {
        $sourceWorkflowKind = $this->normalizeNullableString($lineItem['sourceWorkflowKind'] ?? null);
        $sourceWorkflowId = $this->normalizeNullableString($lineItem['sourceWorkflowId'] ?? null);

        if ($sourceWorkflowKind === null || $sourceWorkflowId === null) {
            return null;
        }

        return strtolower($sourceWorkflowKind).'::'.$sourceWorkflowId;
    }

    /**
     * Route between BillingDecision-driven and legacy resolver paths.
     *
     * @param array<string, mixed>|null $pricingContext
     * @param array<string, mixed>|null $billingDecision
     * @return array<string, mixed>
     */
    private function resolvePricingContext(
        ?string $billingPayerContractId,
        string $currencyCode,
        float $totalAmount,
        ?string $invoiceDateTime,
        ?array $pricingContext,
        ?array $billingDecision,
    ): array {
        if ($billingDecision !== null) {
            return $this->payerSummaryResolver->resolveFromBillingDecision(
                billingDecision: $billingDecision,
                currencyCode: $currencyCode,
                totalAmount: $totalAmount,
                invoiceDateTime: $invoiceDateTime,
                pricingContext: $pricingContext,
            );
        }

        return $this->payerSummaryResolver->resolveFromLegacyInvoice(
            billingPayerContractId: $billingPayerContractId,
            currencyCode: $currencyCode,
            totalAmount: $totalAmount,
            invoiceDateTime: $invoiceDateTime,
            pricingContext: $pricingContext,
        );
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
}
