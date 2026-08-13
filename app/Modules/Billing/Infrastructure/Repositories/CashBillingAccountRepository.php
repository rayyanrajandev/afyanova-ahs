<?php

namespace App\Modules\Billing\Infrastructure\Repositories;

use App\Modules\Billing\Domain\Repositories\CashBillingAccountRepositoryInterface;
use App\Modules\Billing\Infrastructure\Models\CashBillingAccountModel;
use Illuminate\Database\Eloquent\Builder;

class CashBillingAccountRepository implements CashBillingAccountRepositoryInterface
{
    public function paginateForFacility(
        string $tenantId,
        string $facilityId,
        array $filters = [],
        int $page = 1,
        int $perPage = 20,
    ): array {
        $query = $this->baseQuery()
            ->where('cash_billing_accounts.tenant_id', $tenantId)
            ->where('cash_billing_accounts.facility_id', $facilityId);

        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $query->where(function (Builder $builder) use ($q): void {
                $builder
                    ->where('patients.patient_number', 'like', '%'.$q.'%')
                    ->orWhere('patients.first_name', 'like', '%'.$q.'%')
                    ->orWhere('patients.middle_name', 'like', '%'.$q.'%')
                    ->orWhere('patients.last_name', 'like', '%'.$q.'%')
                    ->orWhere('patients.phone', 'like', '%'.$q.'%')
                    ->orWhere('cash_billing_accounts.notes', 'like', '%'.$q.'%');
            });
        }

        $status = trim((string) ($filters['status'] ?? ''));
        if ($status !== '') {
            $query->where('cash_billing_accounts.status', $status);
        }

        $paginator = $query
            ->orderByRaw("case when cash_billing_accounts.status = 'active' then 0 else 1 end")
            ->orderByDesc('cash_billing_accounts.updated_at')
            ->paginate($perPage, ['*'], 'page', $page);

        return [
            'data' => array_map(
                static fn (CashBillingAccountModel $account): array => $account->toArray(),
                $paginator->items(),
            ),
            'meta' => [
                'currentPage' => $paginator->currentPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
                'lastPage' => $paginator->lastPage(),
            ],
        ];
    }

    /**
     * Find a cash billing account by ID
     */
    public function findById(string $id): ?array
    {
        $account = $this->baseQuery()
            ->where('cash_billing_accounts.id', $id)
            ->first();

        return $account?->toArray();
    }

    /**
     * Find a cash billing account by patient ID
     */
    public function findByPatientId(string $patientId, string $tenantId, string $facilityId): ?array
    {
        $account = CashBillingAccountModel::where('tenant_id', $tenantId)
            ->where('facility_id', $facilityId)
            ->where('patient_id', $patientId)
            ->where('status', 'active')
            ->latest('created_at')
            ->first();

        return $account?->toArray();
    }

    /**
     * Create a new cash billing account
     */
    public function create(array $data): array
    {
        $account = CashBillingAccountModel::create($data);

        return $account->toArray();
    }

    /**
     * Update a cash billing account
     */
    public function update(string $id, array $data): array
    {
        $account = CashBillingAccountModel::findOrFail($id);
        $account->update($data);

        return $account->toArray();
    }

    private function baseQuery(): Builder
    {
        return CashBillingAccountModel::query()
            ->leftJoin('patients', 'patients.id', '=', 'cash_billing_accounts.patient_id')
            ->select([
                'cash_billing_accounts.*',
                'patients.patient_number',
                'patients.first_name',
                'patients.middle_name',
                'patients.last_name',
                'patients.phone as patient_phone',
                'patients.gender as patient_gender',
                'patients.date_of_birth as patient_date_of_birth',
                'patients.status as patient_status',
            ]);
    }
}
