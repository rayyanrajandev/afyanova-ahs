<?php

namespace App\Modules\Revenue\Domain\Services;

use App\Modules\Revenue\Infrastructure\Models\ServiceChargeModel;

/**
 * Whether a charge's authorization requirement has been met.
 *
 * This one indirection is what keeps insurance additive. A charge is not
 * authorized "when money arrives" — it is authorized when *its payer's*
 * requirement is satisfied. For self-pay that means the patient has paid in
 * full. For an insurer it will mean a payer authorization exists, possibly
 * alongside a part-payment of the copay.
 *
 * Adding NHIF or a private insurer is therefore a new implementation of this
 * interface plus a tariff in the price book — not a change to the charge
 * table, the cashier workspace, or the gate that reads authorization status.
 */
interface ChargeAuthorizationPolicyInterface
{
    public function isSatisfiedBy(ServiceChargeModel $charge): bool;

    /**
     * What still has to happen, in words a person at a counter can act on.
     */
    public function describeRequirement(ServiceChargeModel $charge): string;
}
