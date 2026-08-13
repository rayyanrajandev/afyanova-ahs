<?php

namespace App\Modules\Billing\Application\UseCases;

use App\Modules\Billing\Domain\Repositories\BillingCorporateAccountRepositoryInterface;
use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use RuntimeException;

class CreateBillingCorporateInvoiceRunUseCase
{
    public function __construct(
        private readonly BillingCorporateAccountRepositoryInterface $repository,
        private readonly CurrentPlatformScopeContextInterface $platformScopeContext,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    public function execute(string $accountId, array $payload, ?int $actorId = null): array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $account = $this->repository->findAccountById($accountId);
        if ($account === null) {
            throw new RuntimeException('Corporate billing account not found.');
        }

        $billingPeriodStart = CarbonImmutable::parse((string) $payload['billing_period_start'])->startOfDay();
        $billingPeriodEnd = CarbonImmutable::parse((string) $payload['billing_period_end'])->endOfDay();
        if ($billingPeriodEnd->lt($billingPeriodStart)) {
            throw new RuntimeException('Billing period end date must be on or after the start date.');
        }

        $eligibleInvoices = $this->repository->eligibleInvoicesForRun(
            tenantId: $this->platformScopeContext->tenantId(),
            facilityId: $this->platformScopeContext->facilityId(),
            billingPayerContractId: (string) $account['billing_payer_contract_id'],
            fromDate: $billingPeriodStart->toDateString(),
            toDate: $billingPeriodEnd->toDateString(),
        );

        if ($eligibleInvoices === []) {
            throw new RuntimeException('No eligible invoices are available for the selected billing period.');
        }

        $issueDate = CarbonImmutable::parse((string) ($payload['issue_date'] ?? now()->toDateString()));
        $dueDate = isset($payload['due_date']) && trim((string) $payload['due_date']) !== ''
            ? CarbonImmutable::parse((string) $payload['due_date'])
            : $issueDate->addDays((int) ($account['settlement_terms_days'] ?? 30));

        $invoiceCount = count($eligibleInvoices);
        $totalAmount = round(array_sum(array_map(static fn (array $invoice): float => (float) ($invoice['included_amount'] ?? 0), $eligibleInvoices)), 2);

        return $this->repository->createRun([
            'billing_corporate_account_id' => $accountId,
            'tenant_id' => $this->platformScopeContext->tenantId(),
            'facility_id' => $this->platformScopeContext->facilityId(),
            'run_number' => $this->generateRunNumber(),
            'billing_period_start' => $billingPeriodStart->toDateString(),
            'billing_period_end' => $billingPeriodEnd->toDateString(),
            'issue_date' => $issueDate->toDateString(),
            'due_date' => $dueDate->toDateString(),
            'currency_code' => (string) ($account['currency_code'] ?? 'TZS'),
            'invoice_count' => $invoiceCount,
            'total_amount' => $totalAmount,
            'paid_amount' => 0,
            'balance_amount' => $totalAmount,
            'status' => 'issued',
            'notes' => trim((string) ($payload['notes'] ?? '')) ?: null,
            'metadata' => [
                'billingPayerContractId' => $account['billing_payer_contract_id'] ?? null,
                'contractCode' => $account['contract_code'] ?? null,
                'contractName' => $account['contract_name'] ?? null,
            ],
            'created_by_user_id' => $actorId,
        ], array_map(static function (array $invoice): array {
            return [
                'billing_invoice_id' => $invoice['id'],
                'patient_id' => $invoice['patient_id'],
                'invoice_number' => $invoice['invoice_number'],
                'patient_display_name' => $invoice['patient_display_name'],
                'invoice_date' => $invoice['invoice_date'],
                'invoice_total_amount' => $invoice['invoice_total_amount'],
                'included_amount' => $invoice['included_amount'],
                'paid_amount' => 0,
                'outstanding_amount' => $invoice['included_amount'],
                'status' => 'open',
            ];
        }, $eligibleInvoices));
    }

    private function generateRunNumber(): string
    {
        return 'CRUN-'.now()->format('Ymd').'-'.strtoupper(Str::random(6));
    }
}
