<?php

namespace App\Modules\Radiology\Infrastructure\Observers;

use App\Modules\Radiology\Application\Services\RadiologyQueueAnnouncer;
use App\Modules\Radiology\Infrastructure\Models\RadiologyOrderModel;

class RadiologyOrderObserver
{
    public function __construct(private readonly RadiologyQueueAnnouncer $announcer) {}

    public function created(RadiologyOrderModel $order): void
    {
        $this->announcer->markDirty($order->facility_id);
    }

    public function updated(RadiologyOrderModel $order): void
    {
        $this->announcer->markDirty($order->facility_id);
    }

    public function deleted(RadiologyOrderModel $order): void
    {
        $this->announcer->markDirty($order->facility_id);
    }
}
