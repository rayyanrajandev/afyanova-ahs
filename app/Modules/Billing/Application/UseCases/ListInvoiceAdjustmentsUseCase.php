<?php

namespace App\Modules\Billing\Application\UseCases;

use App\Modules\Billing\Domain\Repositories\BillingInvoiceRepositoryInterface;

class ListInvoiceAdjustmentsUseCase
{
    public function __construct(
        private readonly BillingInvoiceRepositoryInterface $billingInvoiceRepository,
    ) {}

    public function execute(string $invoiceId): ?array
    {
        $invoice = $this->billingInvoiceRepository->findById($invoiceId);

        if ($invoice === null) {
            return null;
        }

        $adjustments = is_array($invoice['adjustments'] ?? null) ? $invoice['adjustments'] : [];

        return [
            'data' => $adjustments,
        ];
    }
}
