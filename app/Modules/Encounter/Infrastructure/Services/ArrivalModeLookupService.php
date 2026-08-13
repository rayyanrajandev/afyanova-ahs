<?php

namespace App\Modules\Encounter\Infrastructure\Services;

use App\Modules\Encounter\Domain\Services\ArrivalModeLookupServiceInterface;
use App\Modules\Reception\Infrastructure\Models\ArrivalEventModel;

class ArrivalModeLookupService implements ArrivalModeLookupServiceInterface
{
    public function findLatestForAppointment(string $appointmentId): ?string
    {
        return ArrivalEventModel::query()
            ->where('appointment_id', $appointmentId)
            ->orderByDesc('arrived_at')
            ->value('arrival_mode');
    }
}
