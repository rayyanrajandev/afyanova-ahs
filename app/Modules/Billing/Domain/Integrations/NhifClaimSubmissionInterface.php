<?php

namespace App\Modules\Billing\Domain\Integrations;

use App\Modules\Billing\Domain\ValueObjects\NhifClaimSubmissionResult;

interface NhifClaimSubmissionInterface
{
    public function submitClaim(
        string $memberNumber,
        string $authorizationNumber,
        array $claimItems,
        float $totalAmount,
        ?string $claimReference = null,
    ): NhifClaimSubmissionResult;

    public function checkClaimStatus(string $claimReference): NhifClaimSubmissionResult;
}
