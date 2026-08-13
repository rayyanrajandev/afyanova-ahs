<?php

namespace App\Modules\Billing\Domain\Integrations;

interface NhifVerificationInterface
{
    public function verifyMember(string $memberId): ?array;

    public function checkCardStatus(string $cardNumber): ?array;

    public function getMemberDetails(string $memberId): ?array;
}
