<?php

namespace App\Modules\Billing\Application\UseCases;

use App\Modules\Billing\Domain\Repositories\BillingInvoiceRepositoryInterface;
use App\Modules\Patient\Domain\Repositories\PatientRepositoryInterface;

class GetBillingPatientWorkspaceUseCase
{
    public function __construct(
        private readonly PatientRepositoryInterface $patientRepository,
        private readonly BillingInvoiceRepositoryInterface $billingInvoiceRepository,
    ) {}

    public function execute(string $patientId): ?array
    {
        $patient = $this->patientRepository->findById($patientId);
        if ($patient === null) {
            return null;
        }

        $invoices = $this->billingInvoiceRepository->search(
            query: null,
            patientId: $patientId,
            status: null,
            statuses: null,
            currencyCode: null,
            fromDateTime: null,
            toDateTime: null,
            paymentActivityFromDateTime: null,
            paymentActivityToDateTime: null,
            page: 1,
            perPage: 100,
            sortBy: 'invoice_date',
            sortDirection: 'desc',
        );

        $invoiceList = $invoices['data'] ?? [];

        $totalBilled = 0;
        $totalPaid = 0;
        $totalUnpaid = 0;
        $unpaidCount = 0;

        foreach ($invoiceList as $invoice) {
            $total = (float) ($invoice['total_amount'] ?? 0);
            $paid = (float) ($invoice['paid_amount'] ?? 0);
            $balance = (float) ($invoice['balance_amount'] ?? 0);
            $status = $invoice['status'] ?? '';

            $totalBilled += $total;
            $totalPaid += $paid;
            $totalUnpaid += $balance;

            if (! in_array($status, ['cancelled', 'voided'], true) && $balance > 0) {
                $unpaidCount++;
            }
        }

        return [
            'patient' => $patient,
            'invoices' => $invoiceList,
            'summary' => [
                'totalBilled' => round($totalBilled, 2),
                'totalPaid' => round($totalPaid, 2),
                'totalUnpaid' => round($totalUnpaid, 2),
                'invoiceCount' => count($invoiceList),
                'unpaidInvoiceCount' => $unpaidCount,
            ],
        ];
    }
}
