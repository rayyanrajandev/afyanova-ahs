<?php

namespace App\Modules\Revenue\Infrastructure\Policies;

use App\Modules\Revenue\Domain\Exceptions\PayerClassNotImplementedException;
use App\Modules\Revenue\Domain\Services\ChargeAuthorizationPolicyInterface;
use App\Modules\Revenue\Domain\Services\ChargeAuthorizationPolicyResolverInterface;
use App\Modules\Revenue\Domain\ValueObjects\PayerClass;

/**
 * Picks the authorization policy for a charge's payer class.
 *
 * One entry today. A future insurer registers its own here — that registration
 * plus a tariff is the whole of "add NHIF", which is the claim §14 of the plan
 * makes and this class is where it has to hold.
 */
class ChargeAuthorizationPolicyResolver implements ChargeAuthorizationPolicyResolverInterface
{
    /**
     * @var array<string, ChargeAuthorizationPolicyInterface>
     */
    private array $policies;

    public function __construct(SelfPayAuthorizationPolicy $selfPay)
    {
        $this->policies = [
            PayerClass::SELF_PAY->value => $selfPay,
        ];
    }

    public function for(PayerClass $payerClass): ChargeAuthorizationPolicyInterface
    {
        return $this->policies[$payerClass->value]
            ?? throw new PayerClassNotImplementedException($payerClass);
    }

    public function supports(PayerClass $payerClass): bool
    {
        return isset($this->policies[$payerClass->value]);
    }
}
