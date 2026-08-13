<?php

namespace App\Modules\Billing\Infrastructure\Repositories;

use App\Modules\Billing\Domain\Repositories\BillingPaymentPlanRepositoryInterface;
use App\Modules\Billing\Infrastructure\Models\BillingPaymentPlanInstallmentModel;
use App\Modules\Billing\Infrastructure\Models\BillingPaymentPlanModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class BillingPaymentPlanRepository implements BillingPaymentPlanRepositoryInterface
{
    public function paginateForFacility(string $tenantId, string $facilityId, array $filters, int $page, int $perPage): array
    {
        $query = $this->basePlanQuery()
            ->where('billing_payment_plans.tenant_id', $tenantId)
            ->where('billing_payment_plans.facility_id', $facilityId);

        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $query->where(function (Builder $builder) use ($q): void {
                $builder
                    ->where('billing_payment_plans.plan_number', 'like', '%'.$q.'%')
                    ->orWhere('billing_payment_plans.plan_name', 'like', '%'.$q.'%')
                    ->orWhere('billing_invoices.invoice_number', 'like', '%'.$q.'%')
                    ->orWhere('patients.patient_number', 'like', '%'.$q.'%')
                    ->orWhere('patients.first_name', 'like', '%'.$q.'%')
                    ->orWhere('patients.middle_name', 'like', '%'.$q.'%')
                    ->orWhere('patients.last_name', 'like', '%'.$q.'%');
            });
        }

        $status = trim((string) ($filters['status'] ?? ''));
        if ($status !== '' && $status !== 'all') {
            $query->where('billing_payment_plans.status', $status);
        }

        $patientId = trim((string) ($filters['patientId'] ?? ''));
        if ($patientId !== '') {
            $query->where('billing_payment_plans.patient_id', $patientId);
        }

        $billingInvoiceId = trim((string) ($filters['billingInvoiceId'] ?? ''));
        if ($billingInvoiceId !== '') {
            $query->where('billing_payment_plans.billing_invoice_id', $billingInvoiceId);
        }

        $cashBillingAccountId = trim((string) ($filters['cashBillingAccountId'] ?? ''));
        if ($cashBillingAccountId !== '') {
            $query->where('billing_payment_plans.cash_billing_account_id', $cashBillingAccountId);
        }

        $paginator = $query
            ->orderByRaw("case billing_payment_plans.status when 'active' then 0 when 'partially_paid' then 1 when 'defaulted' then 2 else 3 end")
            ->orderBy('billing_payment_plans.next_due_date')
            ->orderByDesc('billing_payment_plans.updated_at')
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

    public function findById(string $id): ?array
    {
        $plan = $this->basePlanQuery()
            ->where('billing_payment_plans.id', $id)
            ->first();

        return $plan ? (array) $plan : null;
    }

    public function findActiveBySource(?string $billingInvoiceId, ?string $cashBillingAccountId): ?array
    {
        if ($billingInvoiceId === null && $cashBillingAccountId === null) {
            return null;
        }

        $query = BillingPaymentPlanModel::query()
            ->whereIn('status', ['active', 'partially_paid', 'defaulted']);

        if ($billingInvoiceId !== null) {
            $query->where('billing_invoice_id', $billingInvoiceId);
        }

        if ($cashBillingAccountId !== null) {
            $query->where('cash_billing_account_id', $cashBillingAccountId);
        }

        $plan = $query->latest('created_at')->first();

        return $plan?->toArray();
    }

    public function create(array $attributes, array $installments): array
    {
        return DB::transaction(function () use ($attributes, $installments): array {
            $plan = BillingPaymentPlanModel::create($attributes);

            foreach ($installments as $installment) {
                BillingPaymentPlanInstallmentModel::create(array_merge($installment, [
                    'billing_payment_plan_id' => $plan->id,
                ]));
            }

            return $this->findById($plan->id) ?? $plan->toArray();
        });
    }

    public function update(string $id, array $attributes): ?array
    {
        $plan = BillingPaymentPlanModel::query()->find($id);
        if (! $plan) {
            return null;
        }

        $plan->fill($attributes);
        $plan->save();

        return $this->findById($id);
    }

    public function installments(string $billingPaymentPlanId): array
    {
        return BillingPaymentPlanInstallmentModel::query()
            ->where('billing_payment_plan_id', $billingPaymentPlanId)
            ->orderBy('installment_number')
            ->get()
            ->map(static fn (BillingPaymentPlanInstallmentModel $installment): array => $installment->toArray())
            ->all();
    }

    public function updateInstallment(string $id, array $attributes): ?array
    {
        $installment = BillingPaymentPlanInstallmentModel::query()->find($id);
        if (! $installment) {
            return null;
        }

        $installment->fill($attributes);
        $installment->save();

        return $installment->toArray();
    }

    private function basePlanQuery(): Builder
    {
        return BillingPaymentPlanModel::query()
            ->leftJoin('patients', 'patients.id', '=', 'billing_payment_plans.patient_id')
            ->leftJoin('billing_invoices', 'billing_invoices.id', '=', 'billing_payment_plans.billing_invoice_id')
            ->leftJoin('cash_billing_accounts', 'cash_billing_accounts.id', '=', 'billing_payment_plans.cash_billing_account_id')
            ->select([
                'billing_payment_plans.*',
                'patients.patient_number',
                'patients.first_name',
                'patients.middle_name',
                'patients.last_name',
                'billing_invoices.invoice_number',
                'cash_billing_accounts.currency_code as cash_account_currency_code',
            ]);
    }
}
