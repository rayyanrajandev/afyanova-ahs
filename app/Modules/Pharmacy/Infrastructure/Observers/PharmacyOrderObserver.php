<?php

namespace App\Modules\Pharmacy\Infrastructure\Observers;

use App\Modules\Pharmacy\Application\Services\PharmacyQueueAnnouncer;
use App\Modules\Pharmacy\Infrastructure\Models\PharmacyOrderModel;

class PharmacyOrderObserver
{
    public function __construct(private readonly PharmacyQueueAnnouncer $announcer) {}

    public function created(PharmacyOrderModel $order): void
    {
        $this->announcer->markDirty($order->facility_id);
    }

    public function updated(PharmacyOrderModel $order): void
    {
        $this->announcer->markDirty($order->facility_id);
    }

    public function deleted(PharmacyOrderModel $order): void
    {
        $this->announcer->markDirty($order->facility_id);
    }
}
