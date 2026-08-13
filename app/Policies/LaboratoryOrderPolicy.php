<?php

namespace App\Policies;

use App\Models\User;
use App\Modules\Laboratory\Domain\ValueObjects\LaboratoryOrderStatus;
use App\Modules\Laboratory\Infrastructure\Models\LaboratoryOrderModel;
use App\Modules\Patient\Infrastructure\Models\PatientModel;

class LaboratoryOrderPolicy
{
    public function order(User $user, PatientModel $patient): bool
    {
        if (! $user->hasPermissionTo('lab.order')) {
            return false;
        }

        return true;
    }

    public function collectSample(User $user, LaboratoryOrderModel $order): bool
    {
        if (! $user->hasPermissionTo('lab.sample.collect')) {
            return false;
        }

        return $order->status === LaboratoryOrderStatus::ORDERED->value;
    }

    public function performTest(User $user, LaboratoryOrderModel $order): bool
    {
        if (! $user->hasPermissionTo('lab.test.perform')) {
            return false;
        }

        return $order->status === LaboratoryOrderStatus::COLLECTED->value;
    }

    public function enterResult(User $user, LaboratoryOrderModel $order): bool
    {
        if (! $user->hasPermissionTo('lab.result.enter')) {
            return false;
        }

        return $order->status === LaboratoryOrderStatus::IN_PROGRESS->value
            || $order->status === LaboratoryOrderStatus::COLLECTED->value;
    }

    /**
     * @param  array<string, mixed>  $order
     */
    public function verifyResult(User $user, array $order): bool
    {
        if (! $user->hasPermissionTo('lab.result.verify')) {
            return false;
        }

        if (($order['ordered_by_user_id'] ?? null) === $user->id) {
            return false;
        }

        $status = $order['status'] ?? null;

        return $status === LaboratoryOrderStatus::COMPLETED->value
            || $status === LaboratoryOrderStatus::IN_PROGRESS->value
            || $status === LaboratoryOrderStatus::COLLECTED->value;
    }

    public function releaseResult(User $user, LaboratoryOrderModel $order): bool
    {
        if (! $user->hasPermissionTo('lab.result.release')) {
            return false;
        }

        return $order->status === LaboratoryOrderStatus::COMPLETED->value;
    }

    public function rejectSample(User $user, LaboratoryOrderModel $order): bool
    {
        if (! $user->hasPermissionTo('lab.sample.reject')) {
            return false;
        }

        return $order->status === LaboratoryOrderStatus::COLLECTED->value;
    }
}
