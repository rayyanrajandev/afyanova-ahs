<?php

namespace App\Modules\Billing\Infrastructure\Repositories;

use App\Modules\Billing\Domain\Repositories\BillingWriteOffRepositoryInterface;
use App\Modules\Billing\Infrastructure\Models\BillingWriteOffModel;
use Illuminate\Support\Str;

class EloquentBillingWriteOffRepository implements BillingWriteOffRepositoryInterface
{
    public function create(array $attributes): array
    {
        $model = new BillingWriteOffModel();
        $model->id = Str::uuid()->toString();

        foreach ($attributes as $key => $value) {
            $model->{$key} = $value;
        }

        $model->save();

        return $model->toArray();
    }

    public function findById(string $id): ?array
    {
        $model = BillingWriteOffModel::query()->find($id);

        return $model?->toArray();
    }

    public function search(
        ?string $query,
        ?string $invoiceId,
        ?string $patientId,
        ?string $status,
        int $page,
        int $perPage,
        ?string $sortBy,
        string $sortDirection
    ): array {
        $queryBuilder = BillingWriteOffModel::query();

        if ($query !== null) {
            $queryBuilder->where(function ($q) use ($query): void {
                $q->where('reason', 'like', "%{$query}%")
                    ->orWhere('id', 'like', "%{$query}%");
            });
        }

        if ($invoiceId !== null) {
            $queryBuilder->where('billing_invoice_id', $invoiceId);
        }

        if ($patientId !== null) {
            $queryBuilder->where('patient_id', $patientId);
        }

        if ($status !== null) {
            $queryBuilder->where('status', $status);
        }

        if ($sortBy !== null) {
            $queryBuilder->orderBy($sortBy, $sortDirection === 'desc' ? 'desc' : 'asc');
        } else {
            $queryBuilder->orderByDesc('created_at');
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
        $model = BillingWriteOffModel::query()->find($id);

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
