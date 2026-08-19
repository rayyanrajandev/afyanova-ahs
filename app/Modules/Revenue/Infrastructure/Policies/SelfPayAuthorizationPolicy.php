<?php

namespace App\Modules\Revenue\Infrastructure\Policies;

use App\Modules\Revenue\Domain\Services\ChargeAuthorizationPolicyInterface;
use App\Modules\Revenue\Infrastructure\Models\ServiceChargeModel;

/**
 * The only authorization policy implemented in this phase.
 *
 * A self-pay charge clears when allocated payments cover the patient's share
 * in full. Deliberately not "greater than zero": a part payment leaves the
 * service unprovided, because prepaid means paid, and the alternative is a
 * receivable — which is the model this system was rebuilt to remove.
 */
class SelfPayAuthorizationPolicy implements ChargeAuthorizationPolicyInterface
{
    public function isSatisfiedBy(ServiceChargeModel $charge): bool
    {
        // An unpriced charge can never be satisfied by payment: nobody knows
        // what to pay. It needs pricing, or a waiver.
        if ($charge->pricing_status !== null && $charge->pricing_status !== 'priced') {
            return false;
        }

        return $charge->allocatedAmount()->isGreaterThanOrEqualTo($charge->patientResponsibility());
    }

    public function describeRequirement(ServiceChargeModel $charge): string
    {
        if ($charge->pricing_status !== null && $charge->pricing_status !== 'priced') {
            return 'This service has no price configured yet — it must be priced before it can be paid for.';
        }

        $outstanding = $charge->outstandingAmount();

        if (! $outstanding->isPositive()) {
            return 'Paid in full.';
        }

        return sprintf(
            '%s %s outstanding.',
            $outstanding->currencyCode,
            $outstanding->toDecimalString(),
        );
    }
}
