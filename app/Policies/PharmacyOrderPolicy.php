<?php

namespace App\Policies;

use App\Models\User;
use App\Modules\Patient\Infrastructure\Models\PatientModel;
use App\Modules\Pharmacy\Domain\ValueObjects\PharmacyOrderStatus;
use App\Modules\Pharmacy\Infrastructure\Models\PharmacyOrderModel;

class PharmacyOrderPolicy
{
    public function prescribe(User $user, PatientModel $patient): bool
    {
        if (! $user->hasPermissionTo('medication.prescribe')) {
            return false;
        }

        return true;
    }

    public function dispense(User $user, PharmacyOrderModel $order): bool
    {
        if (! $user->hasPermissionTo('medication.dispense')) {
            return false;
        }

        if ($order->verified_at === null) {
            return false;
        }

        return ! in_array($order->status, [
            PharmacyOrderStatus::DISPENSED->value,
            PharmacyOrderStatus::CANCELLED->value,
        ], true);
    }

    public function cancelDispense(User $user, PharmacyOrderModel $order): bool
    {
        if (! $user->hasPermissionTo('dispense.cancel')) {
            return false;
        }

        return $order->status !== PharmacyOrderStatus::DISPENSED->value
            && $order->status !== PharmacyOrderStatus::CANCELLED->value;
    }
}
