<?php

namespace App\Modules\Billing\Application\UseCases;

use App\Modules\Billing\Domain\Repositories\CashBillingAccountRepositoryInterface;
use App\Modules\Billing\Domain\Repositories\CashBillingChargeRepositoryInterface;
use App\Modules\Billing\Domain\Repositories\CashBillingPaymentRepositoryInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ConvertCashBillingToInvoiceUseCase
{
    public function __construct(
        private readonly CashBillingAccountRepositoryInterface $cashBillingAccountRepository,
        private readonly CashBillingChargeRepositoryInterface $cashBillingChargeRepository,
        private readonly CashBillingPaymentRepositoryInterface $cashBillingPaymentRepository,
        private readonly CreateBillingInvoiceUseCase $createBillingInvoiceUseCase,
    ) {}

    /**
     * Convert a legacy cash billing account to a billing invoice.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function execute(array $payload): array
    {
        return DB::transaction(function () use ($payload): array {
            $account = $this->cashBillingAccountRepository->findById((string) $payload['cash_billing_account_id']);

            if ($account === null) {
                throw new RuntimeException('Cash billing account not found.');
            }

            if ($account['status'] !== 'active') {
                throw new RuntimeException('Cash billing account is not active. It may have already been converted.');
            }

            $charges = $this->cashBillingChargeRepository->findByAccountId($account['id']);

            if ($charges === []) {
                throw new RuntimeException('No charges found on this cash billing account. Nothing to convert.');
            }

            $payments = $this->cashBillingPaymentRepository->findByAccountId($account['id']);

            $totalCharged = (float) ($account['total_charged'] ?? 0);
            $totalPaid = (float) ($account['total_paid'] ?? 0);
            $balance = (float) ($account['account_balance'] ?? 0);

            $lineItems = [];
            foreach ($charges as $charge) {
                $quantity = max((int) ($charge['quantity'] ?? 1), 1);
                $unitPrice = round(max((float) ($charge['unit_price'] ?? 0), 0), 2);
                $lineTotal = round(max((float) ($charge['charge_amount'] ?? $quantity * $unitPrice), 0), 2);

                $lineItems[] = [
                    'description' => $charge['service_name'] ?? ($charge['description'] ?? 'Legacy charge'),
                    'quantity' => $quantity,
                    'unitPrice' => $unitPrice,
                    'lineTotal' => $lineTotal,
                    'serviceCode' => $charge['service_id'] ?? null,
                    'notes' => $charge['description'] ?? null,
                    'sourceWorkflowKind' => 'legacy_cash_charge',
                    'sourceWorkflowId' => $charge['id'],
                    'sourceWorkflowLabel' => $charge['service_name'] ?? 'Cash charge',
                    'sourcePerformedAt' => $charge['charge_date'] ?? null,
                ];
            }

            $notes = 'Converted from legacy cash billing account.';
            if (count($payments) > 0) {
                $paymentMethods = [];
                foreach ($payments as $payment) {
                    $paymentMethods[] = ($payment['payment_method'] ?? 'unknown').':'.((float) ($payment['amount_paid'] ?? 0));
                }
                $notes .= ' Payments on source: '.implode(', ', $paymentMethods).'.';
            }

            $invoicePayload = [
                'patient_id' => $account['patient_id'],
                'source_cash_billing_account_id' => $account['id'],
                'currency_code' => $account['currency_code'] ?? 'TZS',
                'subtotal_amount' => $totalCharged,
                'discount_amount' => 0,
                'tax_amount' => 0,
                'total_amount' => $totalCharged,
                'paid_amount' => $totalPaid,
                'balance_amount' => $balance,
                'invoice_date' => now()->format('Y-m-d H:i:s'),
                'auto_price_line_items' => false,
                'line_items' => $lineItems,
                'notes' => $notes,
            ];

            $invoiceResult = $this->createBillingInvoiceUseCase->execute(
                payload: $invoicePayload,
                actorId: $payload['actor_id'] ?? null,
            );

            $this->cashBillingAccountRepository->update($account['id'], [
                'status' => 'converted',
                'notes' => ($account['notes'] ?? '')
                    .(!empty($account['notes']) ? '; ' : '')
                    .'Converted to invoice '.($invoiceResult['invoice']['invoice_number'] ?? '')
                    .' on '.now()->format('Y-m-d H:i:s').'.',
            ]);

            return $invoiceResult;
        });
    }
}
