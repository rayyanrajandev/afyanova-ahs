<?php

namespace App\Modules\ServiceRequest\Infrastructure\Repositories;

use App\Modules\ServiceRequest\Domain\Repositories\ServiceRequestItemRepositoryInterface;
use App\Modules\ServiceRequest\Infrastructure\Models\ServiceRequestItemModel;
use Illuminate\Support\Str;

class EloquentServiceRequestItemRepository implements ServiceRequestItemRepositoryInterface
{
    public function createMany(string $serviceRequestId, array $items): void
    {
        $timestamp = now();

        $records = array_map(static function (array $item, int $index) use ($serviceRequestId, $timestamp): array {
            return [
                'id' => (string) Str::uuid(),
                'service_request_id' => $serviceRequestId,
                'service_type' => $item['service_type'] ?? '',
                'catalog_item_id' => $item['catalog_item_id'] ?? null,
                'reference_pharmacy_order_id' => $item['reference_pharmacy_order_id'] ?? null,
                'item_name' => $item['item_name'] ?? '',
                'item_code' => $item['item_code'] ?? null,
                'quantity' => $item['quantity'] ?? 1,
                'status' => $item['status'] ?? 'pending',
                'clinical_indication' => $item['clinical_indication'] ?? null,
                'instructions' => $item['instructions'] ?? null,
                'requested_by_user_id' => $item['requested_by_user_id'] ?? null,
                'requested_at' => $item['requested_at'] ?? $timestamp,
                'sort_order' => $item['sort_order'] ?? $index,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];
        }, $items, array_keys($items));

        ServiceRequestItemModel::query()->insert($records);
    }

    public function findByServiceRequestId(string $serviceRequestId): array
    {
        return ServiceRequestItemModel::query()
            ->where('service_request_id', $serviceRequestId)
            ->orderBy('sort_order')
            ->get()
            ->toArray();
    }

    public function findById(string $id): ?array
    {
        return ServiceRequestItemModel::query()
            ->find($id)
            ?->toArray();
    }

    public function update(string $id, array $attributes): ?array
    {
        $model = ServiceRequestItemModel::query()->find($id);
        if (! $model) {
            return null;
        }

        $model->fill($attributes);
        $model->save();

        return $model->toArray();
    }

    public function deleteByServiceRequestId(string $serviceRequestId): void
    {
        ServiceRequestItemModel::query()
            ->where('service_request_id', $serviceRequestId)
            ->delete();
    }
}
