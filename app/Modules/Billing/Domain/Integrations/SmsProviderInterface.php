<?php

namespace App\Modules\Billing\Domain\Integrations;

use App\Modules\Billing\Domain\ValueObjects\SmsResult;

interface SmsProviderInterface
{
    public function send(string $phoneNumber, string $message, array $options = []): SmsResult;
}
