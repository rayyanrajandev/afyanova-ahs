<?php

namespace App\Support\ClinicalOrders;

use App\Models\User;

final class ClinicalOrderUserSummaryEnricher
{
    /**
     * @param  list<array<string, mixed>>  $orders
     * @return array<int, array<string, mixed>>
     */
    public static function summariesByOrderingUserId(array $orders): array
    {
        $userIds = [];

        foreach ($orders as $order) {
            $userId = (int) ($order['ordered_by_user_id'] ?? 0);
            if ($userId > 0) {
                $userIds[$userId] = true;
            }
        }

        if ($userIds === []) {
            return [];
        }

        $users = User::query()
            ->whereIn('id', array_keys($userIds))
            ->get(['id', 'name']);

        $summaries = [];

        foreach ($users as $user) {
            $summaries[(int) $user->id] = [
                'id' => (int) $user->id,
                'name' => $user->name,
            ];
        }

        return $summaries;
    }

    /**
     * @param  list<array<string, mixed>>  $rawOrders
     * @param  list<array<string, mixed>>  $transformedOrders
     * @return list<array<string, mixed>>
     */
    public static function attachOrderingClinicianToTransformedOrders(array $rawOrders, array $transformedOrders): array
    {
        $summaries = self::summariesByOrderingUserId($rawOrders);

        return array_map(function (array $order) use ($summaries): array {
            $userId = (int) ($order['orderedByUserId'] ?? 0);

            return array_merge($order, [
                'orderedBy' => $userId > 0 ? ($summaries[$userId] ?? null) : null,
            ]);
        }, $transformedOrders);
    }
}
