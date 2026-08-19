<?php

namespace App\Modules\Revenue\Domain\Services;

use App\Modules\Revenue\Domain\ValueObjects\PayerClass;

interface ChargeAuthorizationPolicyResolverInterface
{
    public function for(PayerClass $payerClass): ChargeAuthorizationPolicyInterface;

    public function supports(PayerClass $payerClass): bool;
}
