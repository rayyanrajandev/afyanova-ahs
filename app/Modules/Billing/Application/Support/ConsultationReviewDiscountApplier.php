<?php

namespace App\Modules\Billing\Application\Support;

use App\Modules\Appointment\Application\Support\ConsultationReviewPolicyResolver;
use App\Modules\Billing\Domain\Services\AppointmentLookupServiceInterface;

/**
 * Applies a review consultation discount to billing invoice line items when
 * the linked appointment is classified as REVIEW.
 *
 * Only line items sourced from a consultation charge-capture candidate
 * (sourceWorkflowKind === 'appointment_consultation') are eligible for the
 * discount. All other charges (labs, drugs, etc.) are unaffected.
 *
 * PricingEngine_Migration_Plan.md Phase 5: previously this matched on
 * serviceCode against the legacy service catalog. Consultation's legacy
 * pricing path was removed earlier in Phase 5, so a consultation
 * charge-capture candidate no longer has a serviceCode at all (it's null --
 * see ListBillingChargeCaptureCandidatesUseCase::consultationCandidates()).
 * sourceWorkflowKind is the reliable, always-present signal instead -- it's
 * set directly from the clinical source, never string-matched or guessed.
 *
 * The discount is expressed as an invoice discount and recorded in
 * pricing_context.consultationReviewDiscount for full auditability.
 */
class ConsultationReviewDiscountApplier
{
    public function __construct(
        private readonly AppointmentLookupServiceInterface $appointmentLookupService,
        private readonly ConsultationReviewPolicyResolver $policyResolver,
    ) {}

    /**
     * Inspect the invoice payload and, if the linked appointment is REVIEW,
     * apply a percentage discount to consultation line items.
     *
     * Returns the (possibly modified) payload with updated amounts and
     * pricing_context populated with consultationReviewDiscount metadata.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function apply(array $payload): array
    {
        $appointmentId = isset($payload['appointment_id']) ? (string) $payload['appointment_id'] : null;
        if ($appointmentId === null || $appointmentId === '') {
            return $payload;
        }

        $appointment = $this->appointmentLookupService->findById($appointmentId);
        if ($appointment === null) {
            return $payload;
        }

        $consultationType = strtolower(trim((string) ($appointment['consultation_type'] ?? 'new')));
        if ($consultationType !== 'review') {
            return $this->tagNotApplicable($payload, 'Consultation type is NEW; no review discount applies.');
        }

        $facilityId = $this->normalizeNullableString($payload['facility_id'] ?? null)
            ?? $this->normalizeNullableString($appointment['facility_id'] ?? null);
        $policy = $this->policyResolver->resolve($facilityId);

        $reviewFeePercentage = $this->normalizedReviewFeePercentage($policy);
        $isConfiguredFree = (bool) ($policy['review_fee_is_free'] ?? false);
        $chargePercent = $isConfiguredFree ? 0.0 : $reviewFeePercentage;
        $discountPercent = round(max(100.0 - $chargePercent, 0.0), 2);

        if ($discountPercent <= 0.0) {
            return $this->tagNotApplicable(
                $payload,
                'Review fee percentage is 100; full consultation fee applies.',
                policy: $policy,
                appointment: $appointment,
                reviewFeePercentage: $reviewFeePercentage,
                discountPercent: 0.0,
            );
        }

        return $this->applyDiscount(
            payload: $payload,
            policy: $policy,
            reviewFeePercentage: $reviewFeePercentage,
            discountPercent: $discountPercent,
            isFree: $chargePercent <= 0.0,
            appointment: $appointment,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $policy
     * @param  array<string, mixed>  $appointment
     * @return array<string, mixed>
     */
    private function applyDiscount(
        array $payload,
        array $policy,
        float $reviewFeePercentage,
        float $discountPercent,
        bool $isFree,
        array $appointment,
    ): array
    {
        $lineItems = is_array($payload['line_items'] ?? null) ? $payload['line_items'] : [];

        $reviewDiscountAmount = 0.0;
        $affectedLineCount = 0;
        $affectedServiceCodes = [];

        foreach ($lineItems as &$lineItem) {
            $sourceWorkflowKind = strtolower(trim((string) ($lineItem['sourceWorkflowKind'] ?? ($lineItem['source_workflow_kind'] ?? ''))));
            if ($sourceWorkflowKind !== 'appointment_consultation') {
                continue;
            }

            $serviceCode = strtoupper(trim((string) ($lineItem['serviceCode'] ?? ($lineItem['service_code'] ?? ''))));

            $lineTotal = $this->lineTotal($lineItem);
            $lineDiscount = round($lineTotal * ($discountPercent / 100.0), 2);
            $lineCharge = max(0.0, round($lineTotal - $lineDiscount, 2));

            $lineItem['reviewDiscountAmount'] = $lineDiscount;
            $lineItem['reviewDiscountPercent'] = $discountPercent;
            $lineItem['reviewFeePercentage'] = $reviewFeePercentage;
            $lineItem['reviewChargeAmount'] = $lineCharge;
            $lineItem['consultationType'] = 'review';

            $reviewDiscountAmount += $lineDiscount;
            $affectedLineCount++;
            $affectedServiceCodes[] = $serviceCode;
        }
        unset($lineItem);

        if ($affectedLineCount === 0) {
            return $this->tagNotApplicable(
                payload: $payload,
                reason: 'No consultation service line items found to discount.',
                policy: $policy,
                appointment: $appointment,
                reviewFeePercentage: $reviewFeePercentage,
                discountPercent: $discountPercent,
            );
        }

        $existingDiscount = (float) ($payload['discount_amount'] ?? 0);
        $newDiscount      = round($existingDiscount + $reviewDiscountAmount, 2);
        $subtotal         = (float) ($payload['subtotal_amount'] ?? 0);
        $taxAmount        = (float) ($payload['tax_amount'] ?? 0);
        $paidAmount       = (float) ($payload['paid_amount'] ?? 0);
        $newTotal         = max(0.0, round($subtotal - $newDiscount + $taxAmount, 2));
        $newBalance       = max(0.0, round($newTotal - $paidAmount, 2));

        $payload['line_items']      = $lineItems;
        $payload['discount_amount'] = $newDiscount;
        $payload['total_amount']    = $newTotal;
        $payload['balance_amount']  = $newBalance;

        $existingContext = is_array($payload['pricing_context'] ?? null) ? $payload['pricing_context'] : [];
        $payload['pricing_context'] = array_merge($existingContext, [
            'consultationReviewDiscount' => [
                'applied'                   => true,
                'consultationType'          => 'review',
                'priorAppointmentId'        => $appointment['prior_completed_appointment_id'] ?? null,
                'reviewFeePercentage'       => $reviewFeePercentage,
                'discountPercent'           => $discountPercent,
                'isFreeFollowUp'            => $isFree,
                'reviewDiscountAmount'      => $reviewDiscountAmount,
                'affectedLineCount'         => $affectedLineCount,
                'affectedServiceCodes'      => $affectedServiceCodes,
                'followUpDays'              => $policy['follow_up_days'] ?? null,
                'appliedAt'                 => now()->toISOString(),
            ],
        ]);

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function tagNotApplicable(
        array $payload,
        string $reason,
        ?array $policy = null,
        ?array $appointment = null,
        ?float $reviewFeePercentage = null,
        ?float $discountPercent = null,
    ): array
    {
        $existingContext = is_array($payload['pricing_context'] ?? null) ? $payload['pricing_context'] : [];
        $payload['pricing_context'] = array_merge($existingContext, [
            'consultationReviewDiscount' => array_filter([
                'applied' => false,
                'reason'  => $reason,
                'consultationType' => $appointment !== null
                    ? strtolower(trim((string) ($appointment['consultation_type'] ?? 'new')))
                    : null,
                'priorAppointmentId' => $appointment['prior_completed_appointment_id'] ?? null,
                'reviewFeePercentage' => $reviewFeePercentage,
                'discountPercent' => $discountPercent,
                'followUpDays' => $policy['follow_up_days'] ?? null,
                'appliedAt' => now()->toISOString(),
            ], static fn (mixed $value): bool => $value !== null),
        ]);

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $policy
     */
    private function normalizedReviewFeePercentage(array $policy): float
    {
        return round(min(max((float) ($policy['review_fee_percentage'] ?? 0.0), 0.0), 100.0), 2);
    }

    /**
     * @param  array<string, mixed>  $lineItem
     */
    private function lineTotal(array $lineItem): float
    {
        $explicitTotal = $lineItem['lineTotal']
            ?? $lineItem['line_total']
            ?? $lineItem['total']
            ?? null;

        if ($explicitTotal !== null) {
            return round(max((float) $explicitTotal, 0.0), 2);
        }

        $quantity = max((float) ($lineItem['quantity'] ?? 0), 0.0);
        $unitPrice = max((float) ($lineItem['unitPrice'] ?? $lineItem['unit_price'] ?? 0), 0.0);

        return round($quantity * $unitPrice, 2);
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }
}
