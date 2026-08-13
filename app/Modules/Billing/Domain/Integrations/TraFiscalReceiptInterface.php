<?php

namespace App\Modules\Billing\Domain\Integrations;

use App\Modules\Billing\Infrastructure\Integrations\DTOs\FiscalReceiptRequest;
use App\Modules\Billing\Infrastructure\Integrations\DTOs\FiscalReceiptResponse;

interface TraFiscalReceiptInterface
{
    public function issueReceipt(FiscalReceiptRequest $request): FiscalReceiptResponse;

    public function submitZReport(\DateTimeInterface $date): ?array;

    public function verifyReceipt(string $rctvnum): ?array;
}
