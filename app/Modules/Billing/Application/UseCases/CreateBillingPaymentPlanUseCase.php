<?php

namespace App\Modules\Billing\Application\UseCases;

use App\Modules\Billing\Domain\Repositories\BillingInvoiceRepositoryInterface;
use App\Modules\Billing\Domain\Repositories\BillingPaymentPlanRepositoryInterface;
use App\Modules\Billing\Domain\Repositories\CashBillingAccountRepositoryInterface;
use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use RuntimeException;

class CreateBillingPaymentPlanUseCase
{
    public function __construct(
        private readonly BillingPaymentPlanRepositoryInterface $repository,
        private readonly BillingInvoiceRepositoryInterface $billingInvoiceRepository,
        private readonly CashBillingAccountRepositoryInterface $cashBillingAccountRepository,
        private readonly RecordBillingInvoicePaymentUseCase $recordBillingInvoicePaymentUseCase,
        private readonly RecordCashPaymentUseCase $recordCashPaymentUseCase,
        private readonly CurrentPlatformScopeContextInterface $platformScopeContext,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    public function execute(array $payload, ?int $actorId = null): array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $billingInvoiceId = $this->nullableString($payload['billing_invoice_id'] ?? null);
        $cashBillingAccountId = $this->nullableString($payload['cash_billing_account_id'] ?? null);

        if ($billingInvoiceId === null && $cashBillingAccountId === null) {
            throw new RuntimeException('Payment plan must be linked to a billing invoice or a cash billing account.');
        }

        $existing = $this->repository->findActiveBySource($billingInvoiceId, $cashBillingAccountId);
        if ($existing !== null) {
            throw new RuntimeException('An active payment plan already exists for this billing source.');
        }

        $source = $this->resolveSource($billingInvoiceId, $cashBillingAccountId);
        $requestedTotalAmount = round((float) ($payload['total_amount'] ?? $source['available_balance']), 2);
        if ($requestedTotalAmount <= 0) {
            throw new RuntimeException('Payment plan amount must be greater than zero.');
        }

        if ($requestedTotalAmount > $source['available_balance']) {
            throw new RuntimeException('Payment plan amount cannot exceed the current open balance.');
        }

        $downPaymentAmount = round(max((float) ($payload['down_payment_amount'] ?? 0), 0), 2);
        if ($downPaymentAmount > $requestedTotalAmount) {
            throw new RuntimeException('Down payment cannot exceed the total payment plan amount.');
        }

        $installmentCount = max((int) ($payload['installment_count'] ?? 1), 1);
        $frequency = (string) ($payload['installment_frequency'] ?? 'monthly');
        $intervalDays = $this->resolveIntervalDays($frequency, $payload['installment_interval_days'] ?? null);
        $firstDueDate = CarbonImmutable::parse((string) $payload['first_due_date'])->startOfDay();
        $financedAmount = round($requestedTotalAmount - $downPaymentAmount, 2);

        $paidAmount = 0.0;
        $lastPaymentAt = null;
        if ($downPaymentAmount > 0) {
            $downPaymentAt = $this->nullableString($payload['down_payment_paid_at'] ?? null) ?? now()->toDateTimeString();
            if ($billingInvoiceId !== null) {
                $result = $this->recordBillingInvoicePaymentUseCase->execute(
                    billingInvoiceId: $billingInvoiceId,
                    amount: $downPaymentAmount,
                    payerType: (string) ($payload['payer_type'] ?? 'self_pay'),
                    paymentMethod: (string) ($payload['down_payment_payment_method'] ?? 'cash'),
                    paymentReference: $this->nullableString($payload['down_payment_reference'] ?? null),
                    note: 'Initial payment posted during payment plan setup.',
                    paymentAt: $downPaymentAt,
                    actorId: $actorId,
                );
                if ($result === null) {
                    throw new RuntimeException('Unable to post down payment to the billing invoice.');
                }
            } elseif ($cashBillingAccountId !== null) {
                $this->recordCashPaymentUseCase->execute([
                    'cash_billing_account_id' => $cashBillingAccountId,
                    'amount_paid' => $downPaymentAmount,
                    'currency_code' => $source['currency_code'],
                    'payment_method' => (string) ($payload['down_payment_payment_method'] ?? 'cash'),
                    'payment_reference' => $this->nullableString($payload['down_payment_reference'] ?? null),
                    'paid_at' => $downPaymentAt,
                    'notes' => 'Initial payment posted during payment plan setup.',
                    'confirmed_by_user_id' => $actorId,
                ]);
            }

            $paidAmount = $downPaymentAmount;
            $lastPaymentAt = $downPaymentAt;
        }

        $balanceAmount = round($requestedTotalAmount - $paidAmount, 2);
        $status = $balanceAmount <= 0 ? 'completed' : ($paidAmount > 0 ? 'partially_paid' : 'active');
        $installments = $this->buildInstallments(
            financedAmount: $financedAmount,
            installmentCount: $installmentCount,
            firstDueDate: $firstDueDate,
            intervalDays: $intervalDays,
        );

        $plan = $this->repository->create([
            'tenant_id' => $this->platformScopeContext->tenantId(),
            'facility_id' => $this->platformScopeContext->facilityId(),
            'patient_id' => $source['patient_id'],
            'billing_invoice_id' => $billingInvoiceId,
            'cash_billing_account_id' => $cashBillingAccountId,
            'plan_number' => $this->generatePlanNumber(),
            'plan_name' => trim((string) ($payload['plan_name'] ?? $source['default_plan_name'])),
            'currency_code' => $source['currency_code'],
            'total_amount' => $requestedTotalAmount,
            'down_payment_amount' => $downPaymentAmount,
            'financed_amount' => $financedAmount,
            'paid_amount' => $paidAmount,
            'balance_amount' => $balanceAmount,
            'installment_count' => $installmentCount,
            'installment_frequency' => $frequency,
            'installment_interval_days' => $intervalDays,
            'first_due_date' => $firstDueDate->toDateString(),
            'next_due_date' => $balanceAmount > 0 && $installments !== [] ? $installments[0]['due_date'] : null,
            'last_payment_at' => $lastPaymentAt,
            'status' => $status,
            'terms_and_notes' => $this->nullableString($payload['terms_and_notes'] ?? null),
            'metadata' => [
                'sourceType' => $billingInvoiceId !== null ? 'billing_invoice' : 'cash_billing_account',
                'sourceReference' => $source['reference'],
            ],
            'created_by_user_id' => $actorId,
        ], $installments);

        $plan['installments'] = $this->repository->installments((string) $plan['id']);

        return $plan;
    }

    private function resolveSource(?string $billingInvoiceId, ?string $cashBillingAccountId): array
    {
        if ($billingInvoiceId !== null) {
            $invoice = $this->billingInvoiceRepository->findById($billingInvoiceId);
            if ($invoice === null) {
                throw new RuntimeException('Billing invoice not found for payment plan setup.');
            }

            return [
                'patient_id' => (string) $invoice['patient_id'],
                'currency_code' => (string) ($invoice['currency_code'] ?? 'TZS'),
                'available_balance' => round((float) ($invoice['balance_amount'] ?? 0), 2),
                'reference' => (string) ($invoice['invoice_number'] ?? $billingInvoiceId),
                'default_plan_name' => 'Invoice payment plan '.(string) ($invoice['invoice_number'] ?? ''),
            ];
        }

        $account = $this->cashBillingAccountRepository->findById((string) $cashBillingAccountId);
        if ($account === null) {
            throw new RuntimeException('Cash billing account not found for payment plan setup.');
        }

        return [
            'patient_id' => (string) $account['patient_id'],
            'currency_code' => (string) ($account['currency_code'] ?? 'TZS'),
            'available_balance' => round((float) ($account['account_balance'] ?? 0), 2),
            'reference' => (string) ($account['id'] ?? $cashBillingAccountId),
            'default_plan_name' => 'Cash account payment plan',
        ];
    }

    private function resolveIntervalDays(string $frequency, mixed $customInterval): ?int
    {
        return match ($frequency) {
            'weekly' => 7,
            'biweekly' => 14,
            'monthly' => 30,
            'quarterly' => 90,
            'custom' => max((int) $customInterval, 1),
            default => throw new RuntimeException('Unsupported installment frequency.'),
        };
    }

    private function buildInstallments(float $financedAmount, int $installmentCount, CarbonImmutable $firstDueDate, int $intervalDays): array
    {
        if ($financedAmount <= 0) {
            return [];
        }

        $baseAmount = round($financedAmount / $installmentCount, 2);
        $installments = [];
        $allocated = 0.0;

        for ($index = 1; $index <= $installmentCount; $index++) {
            $scheduledAmount = $index === $installmentCount
                ? round($financedAmount - $allocated, 2)
                : $baseAmount;
            $allocated = round($allocated + $scheduledAmount, 2);

            $installments[] = [
                'installment_number' => $index,
                'due_date' => $firstDueDate->addDays(($index - 1) * $intervalDays)->toDateString(),
                'scheduled_amount' => $scheduledAmount,
                'paid_amount' => 0,
                'outstanding_amount' => $scheduledAmount,
                'status' => 'pending',
            ];
        }

        return $installments;
    }

    private function generatePlanNumber(): string
    {
        return 'PPL-'.now()->format('Ymd').'-'.strtoupper(Str::random(6));
    }

    private function nullableString(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }
}
