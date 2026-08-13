<?php

use App\Modules\Appointment\Application\Support\ConsultationReviewPolicyResolver;
use App\Modules\Billing\Application\Support\ConsultationReviewDiscountApplier;
use App\Modules\Billing\Domain\Services\AppointmentLookupServiceInterface;

/**
 * PricingEngine_Migration_Plan.md Phase 5: consultation charge-capture
 * candidates no longer carry a serviceCode (see
 * ListBillingChargeCaptureCandidatesUseCase::consultationCandidates()), so
 * this applier must identify consultation line items via
 * sourceWorkflowKind instead of a legacy service-catalog lookup. No
 * end-to-end feature test file exercises this in isolation (the existing
 * ConsultationClassificationApiTest.php is broken for unrelated reasons --
 * pre-existing 403/404 failures across the whole file, confirmed against
 * the full-suite baseline), so this unit test is the actual safety net for
 * this specific change.
 */
function makeReviewDiscountApplier(array $appointment, array $policy = []): ConsultationReviewDiscountApplier
{
    $appointmentLookup = Mockery::mock(AppointmentLookupServiceInterface::class);
    $appointmentLookup->shouldReceive('findById')->andReturn($appointment);

    $policyResolver = Mockery::mock(ConsultationReviewPolicyResolver::class);
    $policyResolver->shouldReceive('resolve')->andReturn(array_merge([
        'follow_up_days' => 14,
        'review_fee_percentage' => 50.0,
        'review_fee_is_free' => false,
        'same_complaint_required' => true,
    ], $policy));

    return new ConsultationReviewDiscountApplier($appointmentLookup, $policyResolver);
}

it('applies the review discount to a new-engine consultation line item that has no serviceCode', function (): void {
    $applier = makeReviewDiscountApplier(['id' => 'appt-1', 'consultation_type' => 'review']);

    $result = $applier->apply([
        'appointment_id' => 'appt-1',
        'currency_code' => 'TZS',
        'subtotal_amount' => 10000,
        'discount_amount' => 0,
        'tax_amount' => 0,
        'paid_amount' => 0,
        'line_items' => [
            [
                'description' => 'Outpatient Consultation',
                'quantity' => 1,
                'unitPrice' => 10000,
                'lineTotal' => 10000,
                'serviceCode' => null,
                'sourceWorkflowKind' => 'appointment_consultation',
                'sourceWorkflowId' => 'appt-1',
            ],
        ],
    ]);

    expect($result['pricing_context']['consultationReviewDiscount']['applied'])->toBeTrue()
        ->and((float) $result['pricing_context']['consultationReviewDiscount']['discountPercent'])->toBe(50.0)
        ->and($result['line_items'][0]['reviewDiscountAmount'])->toBe(5000.0)
        ->and((float) $result['discount_amount'])->toBe(5000.0);
});

it('does not discount a non-consultation line item even when review applies', function (): void {
    $applier = makeReviewDiscountApplier(['id' => 'appt-1', 'consultation_type' => 'review']);

    $result = $applier->apply([
        'appointment_id' => 'appt-1',
        'currency_code' => 'TZS',
        'subtotal_amount' => 12000,
        'discount_amount' => 0,
        'tax_amount' => 0,
        'paid_amount' => 0,
        'line_items' => [
            [
                'description' => 'Complete Blood Count',
                'quantity' => 1,
                'unitPrice' => 12000,
                'lineTotal' => 12000,
                'serviceCode' => 'LOINC:57021-8',
                'sourceWorkflowKind' => 'laboratory_order',
                'sourceWorkflowId' => 'order-1',
            ],
        ],
    ]);

    expect($result['pricing_context']['consultationReviewDiscount']['applied'])->toBeFalsy();
});

it('does not apply a discount for a NEW consultation', function (): void {
    $applier = makeReviewDiscountApplier(['id' => 'appt-1', 'consultation_type' => 'new']);

    $result = $applier->apply([
        'appointment_id' => 'appt-1',
        'line_items' => [
            [
                'description' => 'Outpatient Consultation',
                'quantity' => 1,
                'unitPrice' => 10000,
                'lineTotal' => 10000,
                'sourceWorkflowKind' => 'appointment_consultation',
            ],
        ],
    ]);

    expect($result['pricing_context']['consultationReviewDiscount']['applied'])->toBeFalsy();
});
