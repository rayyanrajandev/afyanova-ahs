<?php

namespace App\Policies;

use App\Models\User;
use App\Modules\Patient\Infrastructure\Models\PatientModel;
use App\Modules\Radiology\Domain\ValueObjects\RadiologyOrderStatus;
use App\Modules\Radiology\Infrastructure\Models\RadiologyOrderModel;

class RadiologyOrderPolicy
{
    public function order(User $user, PatientModel $patient): bool
    {
        if (! $user->hasPermissionTo('imaging.order')) {
            return false;
        }

        return true;
    }

    public function perform(User $user, RadiologyOrderModel $order): bool
    {
        if (! $user->hasPermissionTo('imaging.perform')) {
            return false;
        }

        return $order->status === RadiologyOrderStatus::ORDERED->value
            || $order->status === RadiologyOrderStatus::SCHEDULED->value;
    }

    public function enterResult(User $user, RadiologyOrderModel $order): bool
    {
        if (! $user->hasPermissionTo('imaging.result.enter')) {
            return false;
        }

        return $order->status === RadiologyOrderStatus::IN_PROGRESS->value;
    }

    public function verifyResult(User $user, RadiologyOrderModel $order): bool
    {
        if (! $user->hasPermissionTo('imaging.result.verify')) {
            return false;
        }

        if ($order->ordered_by_user_id === $user->id) {
            return false;
        }

        return $order->status === RadiologyOrderStatus::IN_PROGRESS->value;
    }
}
