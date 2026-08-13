<?php

namespace App\Modules\Billing\Application\UseCases;

use App\Modules\Billing\Application\Support\BillingFinancePostingSnapshotService;
use App\Modules\Billing\Domain\Repositories\BillingInvoiceRepositoryInterface;

class GetBillingInvoiceFinancePostingSummaryUseCase
{
    public function __construct(
        private readonly BillingInvoiceRepositoryInterface $billingInvoiceRepository,
        private readonly BillingFinancePostingSnapshotService $snapshotService,
    ) {}

    public function execute(string $billingInvoiceId): ?array
    {
        $invoice = $this->billingInvoiceRepository->findById($billingInvoiceId);
        if ($invoice === null) {
            return null;
        }

        return $this->snapshotService->invoiceSummary($billingInvoiceId);
    }
}
