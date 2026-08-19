<?php

namespace App\Modules\Revenue\Infrastructure\Observers;

use App\Modules\Revenue\Application\Services\CashierQueueAnnouncer;
use App\Modules\Revenue\Infrastructure\Models\ServiceChargeModel;

/**
 * The queue is a projection of service_charges, so service_charges is what
 * announces it has moved.
 */
class ServiceChargeObserver
{
    public function __construct(private readonly CashierQueueAnnouncer $announcer) {}

    public function created(ServiceChargeModel $charge): void
    {
        $this->announcer->markDirty($charge->facility_id);
    }

    public function updated(ServiceChargeModel $charge): void
    {
        $this->announcer->markDirty($charge->facility_id);
    }

    public function deleted(ServiceChargeModel $charge): void
    {
        $this->announcer->markDirty($charge->facility_id);
    }
}
