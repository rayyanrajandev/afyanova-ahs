<?php

namespace App\Modules\Billing\Infrastructure\Repositories;

use App\Modules\Billing\Domain\Repositories\BillingDiscountPolicyRepositoryInterface;
use App\Modules\Billing\Infrastructure\Models\BillingDiscountPolicyModel;
use Illuminate\Database\Eloquent\Builder;

class BillingDiscountPolicyRepository implements BillingDiscountPolicyRepositoryInterface
{
    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, array<string, mixed>>
     */
    public function findForFacility(string $tenantId, string $facilityId, array $filters = []): array
    {
        $query = BillingDiscountPolicyModel::query()
            ->where('tenant_id', $tenantId)
            ->where('facility_id', $facilityId);

        $status = trim((string) ($filters['status'] ?? ''));
        if ($status !== '' && $status !== 'all') {
            $query->where('status', $status);
        }

        $availability = trim((string) ($filters['availability'] ?? ''));
        if ($availability !== '' && $availability !== 'all') {
            $now = now();

            if ($availability === 'current') {
                $query
                    ->where('active_from_date', '<=', $now)
                    ->where(function (Builder $builder) use ($now): void {
                        $builder->whereNull('active_to_date')->orWhere('active_to_date', '>=', $now);
                    });
            } elseif ($availability === 'scheduled') {
                $query->whereNotNull('active_from_date')->where('active_from_date', '>', $now);
            } elseif ($availability === 'expired') {
                $query->whereNotNull('active_to_date')->where('active_to_date', '<', $now);
            }
        }

        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $query->where(function (Builder $builder) use ($q): void {
                $builder
                    ->where('code', 'like', '%'.$q.'%')
                    ->orWhere('name', 'like', '%'.$q.'%')
                    ->orWhere('description', 'like', '%'.$q.'%');
            });
        }

        return $query
            ->orderByRaw("case when status = 'active' then 0 else 1 end")
            ->orderBy('active_from_date')
            ->orderBy('name')
            ->get()
            ->toArray();
    }

    public function findById(string $id): ?array
    {
        $policy = BillingDiscountPolicyModel::find($id);

        return $policy?->toArray();
    }

    public function findByCode(string $code, string $tenantId, string $facilityId): ?array
    {
        $policy = BillingDiscountPolicyModel::where('tenant_id', $tenantId)
            ->where('facility_id', $facilityId)
            ->where('code', $code)
            ->first();

        return $policy?->toArray();
    }

    public function getActiveByFacility(string $tenantId, string $facilityId): array
    {
        return BillingDiscountPolicyModel::where('tenant_id', $tenantId)
            ->where('facility_id', $facilityId)
            ->where('status', 'active')
            ->where('active_from_date', '<=', now())
            ->where(function ($query) {
                $query->whereNull('active_to_date')
                    ->orWhere('active_to_date', '>=', now());
            })
            ->orderBy('name')
            ->get()
            ->toArray();
    }

    public function create(array $data): array
    {
        $policy = BillingDiscountPolicyModel::create($data);

        return $policy->toArray();
    }

    public function update(string $id, array $data): array
    {
        $policy = BillingDiscountPolicyModel::findOrFail($id);
        $policy->update($data);

        return $policy->toArray();
    }
}
