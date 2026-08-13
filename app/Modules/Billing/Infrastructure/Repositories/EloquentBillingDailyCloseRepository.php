<?php

namespace App\Modules\Billing\Infrastructure\Repositories;

use App\Modules\Billing\Domain\Repositories\BillingDailyCloseRepositoryInterface;
use App\Modules\Billing\Infrastructure\Models\BillingDailyCloseModel;
use Illuminate\Support\Str;

class EloquentBillingDailyCloseRepository implements BillingDailyCloseRepositoryInterface
{
    public function create(array $attributes): array
    {
        $model = new BillingDailyCloseModel();
        $model->id = Str::uuid()->toString();

        foreach ($attributes as $key => $value) {
            $model->{$key} = $value;
        }

        $model->save();

        return $model->toArray();
    }

    public function findById(string $id): ?array
    {
        $model = BillingDailyCloseModel::query()->find($id);

        return $model?->toArray();
    }

    public function search(
        ?string $query,
        ?string $facilityId,
        ?string $status,
        ?string $fromDate,
        ?string $toDate,
        int $page,
        int $perPage,
        ?string $sortBy,
        string $sortDirection
    ): array {
        $queryBuilder = BillingDailyCloseModel::query();

        if ($query !== null) {
            $queryBuilder->where(function ($q) use ($query): void {
                $q->where('notes', 'like', "%{$query}%")
                    ->orWhere('id', 'like', "%{$query}%");
            });
        }

        if ($facilityId !== null) {
            $queryBuilder->where('facility_id', $facilityId);
        }

        if ($status !== null) {
            $queryBuilder->where('status', $status);
        }

        if ($fromDate !== null) {
            $queryBuilder->whereDate('closed_at', '>=', $fromDate);
        }

        if ($toDate !== null) {
            $queryBuilder->whereDate('closed_at', '<=', $toDate);
        }

        if ($sortBy !== null) {
            $queryBuilder->orderBy($sortBy, $sortDirection === 'desc' ? 'desc' : 'asc');
        } else {
            $queryBuilder->orderByDesc('closed_at');
        }

        $paginator = $queryBuilder->paginate($perPage, ['*'], 'page', $page);

        return [
            'data' => $paginator->items(),
            'meta' => [
                'currentPage' => $paginator->currentPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
                'lastPage' => $paginator->lastPage(),
            ],
        ];
    }

    public function update(string $id, array $attributes): ?array
    {
        $model = BillingDailyCloseModel::query()->find($id);

        if ($model === null) {
            return null;
        }

        foreach ($attributes as $key => $value) {
            $model->{$key} = $value;
        }

        $model->save();

        return $model->toArray();
    }
}
