<?php

namespace App\Modules\Laboratory\Infrastructure\Observers;

use App\Modules\Laboratory\Application\Services\LaboratoryQueueAnnouncer;
use App\Modules\Laboratory\Infrastructure\Models\LaboratoryOrderModel;

class LaboratoryOrderObserver
{
    public function __construct(private readonly LaboratoryQueueAnnouncer $announcer) {}

    public function created(LaboratoryOrderModel $order): void
    {
        $this->announcer->markDirty($order->facility_id);
    }

    public function updated(LaboratoryOrderModel $order): void
    {
        $this->announcer->markDirty($order->facility_id);
    }

    public function deleted(LaboratoryOrderModel $order): void
    {
        $this->announcer->markDirty($order->facility_id);
    }
}
