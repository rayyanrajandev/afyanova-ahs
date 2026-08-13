<?php

namespace App\Modules\Billing\Infrastructure\Repositories;

use App\Modules\Billing\Domain\Repositories\BillingRefundRepositoryInterface;
use App\Modules\Billing\Infrastructure\Models\BillingRefundModel;
use Illuminate\Database\Eloquent\Builder;

class BillingRefundRepository implements BillingRefundRepositoryInterface
{
    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, array<string, mixed>>
     */
    public function findForFacility(string $tenantId, string $facilityId, array $filters = []): array
    {
        $query = $this->baseQuery()
            ->where(function (Builder $builder) use ($tenantId, $facilityId): void {
                $builder
                    ->where(function (Builder $scoped) use ($tenantId, $facilityId): void {
                        $scoped
                            ->where('billing_invoices.tenant_id', $tenantId)
                            ->where('billing_invoices.facility_id', $facilityId);
                    })
                    ->orWhere(function (Builder $legacy): void {
                        $legacy
                            ->whereNull('billing_invoices.tenant_id')
                            ->whereNull('billing_invoices.facility_id');
                    });
            });

        $status = trim((string) ($filters['status'] ?? ''));
        if ($status !== '' && $status !== 'all') {
            $query->where('billing_refunds.refund_status', $status);
        }

        $invoiceId = $filters['invoice_id'] ?? null;
        if (is_string($invoiceId) && $invoiceId !== '') {
            $query->where('billing_refunds.billing_invoice_id', $invoiceId);
        }

        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $query->where(function (Builder $builder) use ($q): void {
                $builder
                    ->where('billing_invoices.invoice_number', 'like', '%'.$q.'%')
                    ->orWhere('patients.patient_number', 'like', '%'.$q.'%')
                    ->orWhere('patients.first_name', 'like', '%'.$q.'%')
                    ->orWhere('patients.middle_name', 'like', '%'.$q.'%')
                    ->orWhere('patients.last_name', 'like', '%'.$q.'%')
                    ->orWhere('billing_refunds.notes', 'like', '%'.$q.'%')
                    ->orWhere('billing_refunds.mobile_money_reference', 'like', '%'.$q.'%')
                    ->orWhere('billing_refunds.card_reference', 'like', '%'.$q.'%')
                    ->orWhere('billing_refunds.check_number', 'like', '%'.$q.'%');
            });
        }

        return $query
            ->orderByRaw("
                case billing_refunds.refund_status
                    when 'pending' then 0
                    when 'approved' then 1
                    when 'processed' then 2
                    else 3
                end
            ")
            ->orderByDesc('billing_refunds.requested_at')
            ->get()
            ->toArray();
    }

    public function findById(string $id): ?array
    {
        $refund = $this->baseQuery()
            ->where('billing_refunds.id', $id)
            ->first();

        return $refund?->toArray();
    }

    public function findByInvoiceId(string $invoiceId): array
    {
        return $this->baseQuery()
            ->where('billing_refunds.billing_invoice_id', $invoiceId)
            ->orderBy('requested_at', 'desc')
            ->get()
            ->toArray();
    }

    public function findByStatus(string $status): array
    {
        return $this->baseQuery()
            ->where('billing_refunds.refund_status', $status)
            ->orderBy('requested_at', 'desc')
            ->get()
            ->toArray();
    }

    public function create(array $data): array
    {
        $refund = BillingRefundModel::create($data);

        return $refund->toArray();
    }

    public function update(string $id, array $data): array
    {
        $refund = BillingRefundModel::findOrFail($id);
        $refund->update($data);

        return $refund->toArray();
    }

    private function baseQuery(): Builder
    {
        return BillingRefundModel::query()
            ->leftJoin('billing_invoices', 'billing_invoices.id', '=', 'billing_refunds.billing_invoice_id')
            ->leftJoin('patients', 'patients.id', '=', 'billing_refunds.patient_id')
            ->select([
                'billing_refunds.*',
                'billing_invoices.invoice_number',
                'billing_invoices.currency_code as invoice_currency_code',
                'billing_invoices.status as invoice_status',
                'billing_invoices.total_amount as invoice_total_amount',
                'billing_invoices.paid_amount as invoice_paid_amount',
                'billing_invoices.balance_amount as invoice_balance_amount',
                'patients.patient_number',
                'patients.first_name',
                'patients.middle_name',
                'patients.last_name',
                'patients.phone as patient_phone',
            ]);
    }
}
