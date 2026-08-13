<?php

namespace App\Modules\Billing\Domain\Services;

/**
 * PricingEngine_Technical_Design.md §2 step 4. Turns elapsed time into a
 * billable quantity for duration-based charge models. Extracted so any
 * duration-based chargeable item (bed-day, ICU stay, equipment rental)
 * reuses one implementation instead of each domain hand-rolling its own
 * ceil/rounding logic -- bed-day billing was the first and only place this
 * existed before the pricing engine (ListBillingChargeCaptureCandidatesUseCase
 * ::bedDayCandidatesForAdmission()).
 */
class DurationChargeStrategy
{
    /**
     * Any part of a calendar day counts as a full day, minimum 1 -- the
     * admission/occupancy day is always chargeable even if it ends hours
     * later. Matches the bed-day billing policy already in production.
     */
    public function quantityForPerDay(float $elapsedHours): float
    {
        return max(1.0, ceil(max(0.0, $elapsedHours) / 24));
    }

    /**
     * Any part of an hour counts as a full hour, minimum 1.
     */
    public function quantityForPerHour(float $elapsedHours): float
    {
        return max(1.0, ceil(max(0.0, $elapsedHours)));
    }

    /**
     * @return float quantity to bill, given the chargeable item's charge model
     */
    public function resolveQuantity(string $chargeModel, float $quantityOrDuration): float
    {
        return match ($chargeModel) {
            'flat' => 1.0,
            'per_unit' => max(0.0, $quantityOrDuration),
            'per_day' => $this->quantityForPerDay($quantityOrDuration),
            'per_hour' => $this->quantityForPerHour($quantityOrDuration),
            default => max(0.0, $quantityOrDuration),
        };
    }
}
