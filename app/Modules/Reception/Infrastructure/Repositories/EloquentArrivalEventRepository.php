<?php

namespace App\Modules\Reception\Infrastructure\Repositories;

use App\Modules\Reception\Domain\Repositories\ArrivalEventRepositoryInterface;
use App\Modules\Reception\Infrastructure\Models\ArrivalEventModel;

class EloquentArrivalEventRepository implements ArrivalEventRepositoryInterface
{
    public function create(array $attributes): array
    {
        return ArrivalEventModel::query()->create($attributes)->toArray();
    }

    public function findLatestForAppointment(string $appointmentId): ?array
    {
        return ArrivalEventModel::query()
            ->where('appointment_id', $appointmentId)
            ->orderByDesc('arrived_at')
            ->first()
            ?->toArray();
    }
}
