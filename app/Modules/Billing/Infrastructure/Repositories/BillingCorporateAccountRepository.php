<?php

namespace App\Modules\Billing\Infrastructure\Repositories;

use App\Modules\Billing\Domain\Repositories\BillingCorporateAccountRepositoryInterface;
use App\Modules\Billing\Infrastructure\Models\BillingCorporateAccountModel;
use App\Modules\Billing\Infrastructure\Models\BillingCorporateInvoiceRunModel;
use App\Modules\Billing\Infrastructure\Models\BillingCorporateRunInvoiceModel;
use App\Modules\Billing\Infrastructure\Models\BillingCorporateRunPaymentModel;
use App\Modules\Billing\Infrastructure\Models\BillingInvoiceModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class BillingCorporateAccountRepository implements BillingCorporateAccountRepositoryInterface
{
    public function paginateAccountsForFacility(string $tenantId, string $facilityId, array $filters, int $page, int $perPage): array
    {
        $query = $this->baseAccountQuery()
            ->where('billing_corporate_accounts.tenant_id', $tenantId)
            ->where('billing_corporate_accounts.facility_id', $facilityId);

        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $query->where(function (Builder $builder) use ($q): void {
                $builder
                    ->where('billing_corporate_accounts.account_code', 'like', '%'.$q.'%')
                    ->orWhere('billing_corporate_accounts.account_name', 'like', '%'.$q.'%')
                    ->orWhere('billing_payer_contracts.contract_code', 'like', '%'.$q.'%')
                    ->orWhere('billing_payer_contracts.contract_name', 'like', '%'.$q.'%')
                    ->orWhere('billing_payer_contracts.payer_name', 'like', '%'.$q.'%');
            });
        }

        $status = trim((string) ($filters['status'] ?? ''));
        if ($status !== '' && $status !== 'all') {
            $query->where('billing_corporate_accounts.status', $status);
        }

        $paginator = $query
            ->orderByRaw("case billing_corporate_accounts.status when 'active' then 0 else 1 end")
            ->orderBy('billing_corporate_accounts.account_name')
            ->paginate($perPage, ['*'], 'page', $page);

        return [
            'data' => array_map(static fn ($row): array => (array) $row, $paginator->items()),
            'meta' => [
                'currentPage' => $paginator->currentPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
                'lastPage' => $paginator->lastPage(),
            ],
        ];
    }

    public function findAccountById(string $id): ?array
    {
        $account = $this->baseAccountQuery()->where('billing_corporate_accounts.id', $id)->first();

        return $account ? (array) $account : null;
    }

    public function createAccount(array $attributes): array
    {
        $account = BillingCorporateAccountModel::create($attributes);

        return $this->findAccountById($account->id) ?? $account->toArray();
    }

    public function updateAccount(string $id, array $attributes): ?array
    {
        $account = BillingCorporateAccountModel::query()->find($id);
        if (! $account) {
            return null;
        }

        $account->fill($attributes);
        $account->save();

        return $this->findAccountById($id);
    }

    public function paginateRunsForAccount(string $accountId, array $filters, int $page, int $perPage): array
    {
        $query = BillingCorporateInvoiceRunModel::query()->where('billing_corporate_account_id', $accountId);

        $status = trim((string) ($filters['status'] ?? ''));
        if ($status !== '' && $status !== 'all') {
            $query->where('status', $status);
        }

        $paginator = $query
            ->orderByDesc('billing_period_end')
            ->orderByDesc('created_at')
            ->paginate($perPage, ['*'], 'page', $page);

        return [
            'data' => array_map(static fn (BillingCorporateInvoiceRunModel $run): array => $run->toArray(), $paginator->items()),
            'meta' => [
                'currentPage' => $paginator->currentPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
                'lastPage' => $paginator->lastPage(),
            ],
        ];
    }

    public function findRunById(string $id): ?array
    {
        $run = BillingCorporateInvoiceRunModel::query()->find($id);

        return $run?->toArray();
    }

    public function createRun(array $attributes, array $runInvoices): array
    {
        return DB::transaction(function () use ($attributes, $runInvoices): array {
            $run = BillingCorporateInvoiceRunModel::create($attributes);

            foreach ($runInvoices as $runInvoice) {
                BillingCorporateRunInvoiceModel::create(array_merge($runInvoice, [
                    'billing_corporate_invoice_run_id' => $run->id,
                ]));
            }

            return $run->toArray();
        });
    }

    public function updateRun(string $id, array $attributes): ?array
    {
        $run = BillingCorporateInvoiceRunModel::query()->find($id);
        if (! $run) {
            return null;
        }

        $run->fill($attributes);
        $run->save();

        return $run->toArray();
    }

    public function runInvoices(string $runId): array
    {
        return BillingCorporateRunInvoiceModel::query()
            ->where('billing_corporate_invoice_run_id', $runId)
            ->orderBy('invoice_date')
            ->orderBy('invoice_number')
            ->get()
            ->map(static fn (BillingCorporateRunInvoiceModel $row): array => $row->toArray())
            ->all();
    }

    public function updateRunInvoice(string $id, array $attributes): ?array
    {
        $row = BillingCorporateRunInvoiceModel::query()->find($id);
        if (! $row) {
            return null;
        }

        $row->fill($attributes);
        $row->save();

        return $row->toArray();
    }

    public function createRunPayment(array $attributes): array
    {
        $payment = BillingCorporateRunPaymentModel::create($attributes);

        return $payment->toArray();
    }

    public function runPayments(string $runId): array
    {
        return BillingCorporateRunPaymentModel::query()
            ->where('billing_corporate_invoice_run_id', $runId)
            ->orderByDesc('paid_at')
            ->get()
            ->map(static fn (BillingCorporateRunPaymentModel $row): array => $row->toArray())
            ->all();
    }

    public function eligibleInvoicesForRun(string $tenantId, string $facilityId, string $billingPayerContractId, string $fromDate, string $toDate): array
    {
        $alreadyIncludedInvoiceIds = BillingCorporateRunInvoiceModel::query()
            ->join('billing_corporate_invoice_runs', 'billing_corporate_invoice_runs.id', '=', 'billing_corporate_run_invoices.billing_corporate_invoice_run_id')
            ->whereIn('billing_corporate_invoice_runs.status', ['draft', 'issued', 'partially_paid'])
            ->pluck('billing_corporate_run_invoices.billing_invoice_id')
            ->all();

        return BillingInvoiceModel::query()
            ->leftJoin('patients', 'patients.id', '=', 'billing_invoices.patient_id')
            ->where('billing_invoices.tenant_id', $tenantId)
            ->where('billing_invoices.facility_id', $facilityId)
            ->where('billing_invoices.billing_payer_contract_id', $billingPayerContractId)
            ->whereDate('billing_invoices.invoice_date', '>=', $fromDate)
            ->whereDate('billing_invoices.invoice_date', '<=', $toDate)
            ->whereIn('billing_invoices.status', ['issued', 'partially_paid'])
            ->where('billing_invoices.balance_amount', '>', 0)
            ->when($alreadyIncludedInvoiceIds !== [], fn (Builder $query) => $query->whereNotIn('billing_invoices.id', $alreadyIncludedInvoiceIds))
            ->orderBy('billing_invoices.invoice_date')
            ->orderBy('billing_invoices.invoice_number')
            ->get([
                'billing_invoices.id',
                'billing_invoices.patient_id',
                'billing_invoices.invoice_number',
                'billing_invoices.invoice_date',
                'billing_invoices.total_amount',
                'billing_invoices.balance_amount',
                'patients.patient_number',
                'patients.first_name',
                'patients.middle_name',
                'patients.last_name',
            ])
            ->map(static function ($row): array {
                $patientDisplayName = collect([$row->first_name, $row->middle_name, $row->last_name])->filter()->implode(' ');

                return [
                    'id' => $row->id,
                    'patient_id' => $row->patient_id,
                    'invoice_number' => $row->invoice_number,
                    'invoice_date' => $row->invoice_date,
                    'invoice_total_amount' => (float) $row->total_amount,
                    'included_amount' => (float) $row->balance_amount,
                    'paid_amount' => 0.0,
                    'outstanding_amount' => (float) $row->balance_amount,
                    'patient_display_name' => $patientDisplayName !== '' ? $patientDisplayName : $row->patient_number,
                ];
            })
            ->all();
    }

    private function baseAccountQuery(): Builder
    {
        return BillingCorporateAccountModel::query()
            ->leftJoin('billing_payer_contracts', 'billing_payer_contracts.id', '=', 'billing_corporate_accounts.billing_payer_contract_id')
            ->select([
                'billing_corporate_accounts.*',
                'billing_payer_contracts.contract_code',
                'billing_payer_contracts.contract_name',
                'billing_payer_contracts.payer_type',
                'billing_payer_contracts.payer_name',
                'billing_payer_contracts.currency_code',
            ]);
    }
}
