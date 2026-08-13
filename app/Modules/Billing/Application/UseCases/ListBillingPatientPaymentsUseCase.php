<?php

namespace App\Modules\Billing\Application\UseCases;

use App\Modules\Billing\Domain\Repositories\BillingInvoicePaymentRepositoryInterface;
use App\Modules\Billing\Domain\Repositories\BillingInvoiceRepositoryInterface;
use App\Modules\Patient\Domain\Repositories\PatientRepositoryInterface;

class ListBillingPatientPaymentsUseCase
{
    public function __construct(
        private readonly PatientRepositoryInterface $patientRepository,
        private readonly BillingInvoiceRepositoryInterface $billingInvoiceRepository,
        private readonly BillingInvoicePaymentRepositoryInterface $billingInvoicePaymentRepository,
    ) {}

    /**
     * @return array<int, array<string, mixed>>|null
     */
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
        $invoiceIds = array_map(static fn (array $invoice): string => (string) $invoice['id'], $invoiceList);
        $invoiceMap = array_combine($invoiceIds, $invoiceList);

        $payments = $this->billingInvoicePaymentRepository->listByBillingInvoiceIds($invoiceIds);

        return array_map(static function (array $payment) use ($invoiceMap): array {
            $invoice = $invoiceMap[(string) ($payment['billing_invoice_id'] ?? '')] ?? null;

            return $payment + [
                'invoice_number' => $invoice['invoice_number'] ?? null,
                'invoice_status' => $invoice['status'] ?? null,
                'currency_code' => $invoice['currency_code'] ?? null,
            ];
        }, $payments);
    }
}
